<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
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
     * Execute an OCC command and return the output.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function execute(): JSONResponse {
        $command = $this->request->getParam('command', '');
        $args = $this->request->getParam('args', []);
        $timeout = (int) $this->request->getParam('timeout', self::DEFAULT_TIMEOUT);

        if (empty($command)) {
            return new JSONResponse([
                'success' => false,
                'error' => 'No command provided',
            ], 400);
        }

        if (!is_array($args)) {
            $args = [];
        }

        $timeout = max(1, min($timeout, self::MAX_TIMEOUT));

        // Build the shell command with proper escaping
        $shellCmd = escapeshellarg(PHP_BINARY)
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
            ], 500);
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
                ], 408);
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
