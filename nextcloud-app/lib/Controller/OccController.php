<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class OccController extends Controller {
    private LoggerInterface $logger;

    private const DEFAULT_TIMEOUT = 120;
    private const MAX_TIMEOUT = 600;

    public function __construct(
        string $appName,
        IRequest $request,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->logger = $logger;
    }

    /**
     * Execute an OCC command and return the output
     *
     * @param string       $command OCC command name (e.g. "maintenance:mode")
     * @param list<string> $args    Additional command arguments
     * @param int          $timeout Execution timeout in seconds (1–600, default: 120)
     *
     * 200: Command completed; see exitCode for success/failure
     * 400: No command was provided
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, stdout: string, stderr: string, exitCode: int}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{success: bool, error: string}, array{}>
     *        |JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, error: string}, array{}>
     *
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function execute(string $command = '', array $args = [], int $timeout = 120): JSONResponse {
        if (empty($command)) {
            return new JSONResponse([
                'success' => false,
                'error' => 'No command provided',
            ], Http::STATUS_BAD_REQUEST);
        }

        $timeout = max(1, min($timeout, self::MAX_TIMEOUT));

        // Resolve a valid PHP CLI binary.
        // In Apache/FPM environments PHP_BINARY may be empty or point to a
        // non-CLI binary, so we fall back to PATH lookup.
        $phpBinary = PHP_BINARY;
        if (empty($phpBinary) || !is_executable($phpBinary)) {
            $isApacheSapi = strpos(PHP_SAPI, 'apache') === 0;
            if (empty($phpBinary) && $isApacheSapi) {
                $this->logger->debug('PHP_BINARY is empty (Apache SAPI), falling back to PATH lookup');
            } else {
                $this->logger->warning('PHP_BINARY is empty or non-executable, falling back to PATH lookup', [
                    'php_binary' => $phpBinary,
                    'sapi' => PHP_SAPI,
                ]);
            }
            $candidates = ['php', 'php8', 'php8.4', 'php8.3'];
            $phpBinary = '';
            foreach ($candidates as $candidate) {
                $out = [];
                exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null', $out);
                if (!empty($out[0]) && is_executable($out[0])) {
                    $phpBinary = $out[0];
                    break;
                }
            }
        }

        if (empty($phpBinary)) {
            $this->logger->error('Cannot locate PHP CLI binary; OCC command aborted', [
                'command' => $command,
            ]);
            return new JSONResponse([
                'success' => false,
                'error' => 'Cannot locate PHP CLI binary',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Build the shell command with proper escaping
        $shellCmd = escapeshellarg($phpBinary)
            . ' ' . escapeshellarg(\OC::$SERVERROOT . '/occ')
            . ' ' . escapeshellarg($command)
            . ' --no-ansi --no-interaction';

        foreach ($args as $arg) {
            $shellCmd .= ' ' . escapeshellarg((string) $arg);
        }

        $this->logger->info('OCC execution requested', [
            'command' => $command,
            'args' => $args,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($shellCmd, $descriptors, $pipes, \OC::$SERVERROOT);

        if (!is_resource($process)) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to start process',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new JSONResponse([
                    'success' => false,
                    'error' => "Command timed out after {$timeout} seconds",
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exitCode' => -1,
                ], Http::STATUS_REQUEST_TIMEOUT);
            }

            usleep(50000);
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->logger->info('OCC execution completed', [
            'command' => $command,
            'exitCode' => $exitCode,
        ]);

        return new JSONResponse([
            'success' => $exitCode === 0,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
        ]);
    }
}
