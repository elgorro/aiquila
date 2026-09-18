<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Measure what the Message Batches API actually does on this instance.
 *
 * The discount is documented and the 24-hour ceiling is documented, but what
 * matters for a bulk job is neither: it is how long a batch of this size
 * actually takes here, and how many of its requests come back refused. Both
 * depend on the traffic and the account, so rather than committing numbers
 * that would be wrong for everyone else, this lets an admin measure their own.
 *
 * It also demonstrates the one property the whole design rests on — results
 * arrive in no particular order — by counting how many of them did.
 *
 * It sends real requests and spends real money, hence the confirmation.
 */
class BenchmarkBatchCommand extends Base {
    /** Roughly one token per four characters of English filler. */
    private const CHARS_PER_TOKEN = 4;

    /** Batch pricing is half the synchronous rate on both sides. */
    private const BATCH_DISCOUNT = 0.5;

    public function __construct(
        private ClaudeSDKService $claudeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:benchmark-batch')
            ->setDescription('Measure latency, ordering and cost of the Message Batches API')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model to use (defaults to the configured model)')
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Requests in the batch', '10')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User whose API key to use (optional)')
            ->addOption('prompt-tokens', null, InputOption::VALUE_REQUIRED, 'Approximate size of each synthetic prompt, in tokens', '2000')
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, 'Roughly how much output to ask each request for', '1024')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Minutes to wait for the batch before giving up', '30')
            ->addOption('compare-sync', null, InputOption::VALUE_NONE, 'Also run the same requests synchronously, for a side-by-side')
            ->addOption('price-in', null, InputOption::VALUE_REQUIRED, 'Input price in USD per million tokens; enables the cost column')
            ->addOption('price-out', null, InputOption::VALUE_REQUIRED, 'Output price in USD per million tokens; enables the cost column')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getOption('user');
        $model = ClaudeModels::resolveModel(
            (string)($input->getOption('model') ?: $this->claudeService->getModel($userId))
        );
        $requests = max(1, (int)$input->getOption('requests'));
        $promptTokens = max(1, (int)$input->getOption('prompt-tokens'));
        $maxTokens = max(1, (int)$input->getOption('max-tokens'));
        $timeoutSeconds = max(60, (int)$input->getOption('timeout') * 60);
        $compareSync = (bool)$input->getOption('compare-sync');

        if ($this->claudeService->getApiKey($userId) === '') {
            $output->writeln('<error>No Anthropic API key configured.</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Batch of ' . $requests . ' request(s) on ' . $model . '</info>');
        $output->writeln(sprintf(
            '  ~%d prompt tokens in and ~%d out per request%s; the instance output cap applies to both paths.',
            $promptTokens,
            $maxTokens,
            $compareSync ? ', run twice (batch and synchronous)' : ''
        ));
        $output->writeln('  <comment>These are real API calls and are billed to your account.</comment>');
        $output->writeln('');

        if (!$input->getOption('yes') && !$this->confirm($input, $output)) {
            $output->writeln('Aborted.');
            return 0;
        }

        $batch = $this->runBatch($output, $model, $requests, $promptTokens, $maxTokens, $timeoutSeconds, $userId);
        if ($batch === null) {
            return 1;
        }

        $sync = $compareSync
            ? $this->runSync($output, $model, $requests, $promptTokens, $maxTokens, $userId)
            : null;

        $this->report(
            $output,
            $batch,
            $sync,
            $this->floatOption($input, 'price-in'),
            $this->floatOption($input, 'price-out'),
        );

        return 0;
    }

    /**
     * Unlike the other confirmations in this app, a non-interactive run without
     * --yes is refused rather than waved through: this command spends money,
     * and a scripted invocation that meant to pass --yes should say so.
     */
    private function confirm(InputInterface $input, OutputInterface $output): bool {
        $helper = $this->getHelper('question');
        if (!$input->isInteractive() || !$helper instanceof QuestionHelper) {
            $output->writeln('<error>Refusing to spend money without a confirmation — pass --yes.</error>');
            return false;
        }
        $confirmed = (bool)$helper->ask($input, $output, new ConfirmationQuestion('Proceed? [y/N] ', false));
        $output->writeln('');
        return $confirmed;
    }

    private function floatOption(InputInterface $input, string $name): ?float {
        $raw = $input->getOption($name);
        return ($raw === null || $raw === '') ? null : (float)$raw;
    }

    /**
     * @return array{elapsed: float, input_tokens: int, output_tokens: int, outcomes: array<string, int>, refusals: list<string>, out_of_order: int, batch_id: string, ended: bool}|null
     */
    private function runBatch(
        OutputInterface $output,
        string $model,
        int $requests,
        int $promptTokens,
        int $maxTokens,
        int $timeoutSeconds,
        ?string $userId,
    ): ?array {
        $payload = [];
        $order = [];
        for ($i = 0; $i < $requests; $i++) {
            $customId = sprintf('bench-%03d', $i);
            $order[$customId] = $i;
            $payload[] = [
                'custom_id' => $customId,
                'messages' => [['role' => 'user', 'content' => $this->syntheticPrompt($promptTokens, $maxTokens, $i)]],
                'options' => ['model' => $model],
            ];
        }

        $started = microtime(true);
        try {
            $batchId = $this->claudeService->submitBatch($payload, $userId);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not submit the batch: ' . $e->getMessage() . '</error>');
            return null;
        }

        $output->writeln('  submitted             ' . $batchId);
        $output->write('  waiting               ');

        $ended = false;
        while ((microtime(true) - $started) < $timeoutSeconds) {
            $status = $this->claudeService->getBatchStatus($batchId, $userId);
            if ($status['ended']) {
                $ended = true;
                break;
            }
            $output->write('.');
            $this->sleep();
        }
        $elapsed = microtime(true) - $started;
        $output->writeln('');

        if (!$ended) {
            $output->writeln('<error>Batch did not end within the timeout. It is still running; results are not lost.</error>');
            $output->writeln('  Check it later with the batch id above.');
            return null;
        }

        $results = $this->claudeService->fetchBatchResults($batchId, $userId);

        $outcomes = ['succeeded' => 0, 'refused' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0, 'missing' => 0];
        $refusals = [];
        $inputTokens = 0;
        $outputTokens = 0;

        // Arrival order versus submission order. This is the load-bearing
        // property of the whole design: anything reading results positionally
        // would pair a request with another request's answer.
        $outOfOrder = 0;
        $highWater = -1;
        foreach (array_keys($results) as $customId) {
            $index = $order[$customId] ?? -1;
            if ($index < $highWater) {
                $outOfOrder++;
            } else {
                $highWater = $index;
            }
        }

        foreach ($order as $customId => $_) {
            if (!isset($results[$customId])) {
                $outcomes['missing']++;
                continue;
            }
            $result = $results[$customId];
            if (isset($result['error'])) {
                $kind = (string)($result['error_type'] ?? 'errored');
                $outcomes[$kind] = ($outcomes[$kind] ?? 0) + 1;
                if ($kind === 'refused') {
                    $refusals[] = $result['error'];
                }
                continue;
            }
            $outcomes['succeeded']++;
            $inputTokens += (int)($result['usage']['input_tokens'] ?? 0);
            $outputTokens += (int)($result['usage']['output_tokens'] ?? 0);
        }

        return [
            'elapsed' => $elapsed,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'outcomes' => $outcomes,
            'refusals' => $refusals,
            'out_of_order' => $outOfOrder,
            'batch_id' => $batchId,
            'ended' => true,
        ];
    }

    /**
     * @return array{elapsed: float, input_tokens: int, output_tokens: int, errors: int}
     */
    private function runSync(
        OutputInterface $output,
        string $model,
        int $requests,
        int $promptTokens,
        int $maxTokens,
        ?string $userId,
    ): array {
        $output->write('  synchronous           ');

        $started = microtime(true);
        $inputTokens = 0;
        $outputTokens = 0;
        $errors = 0;

        for ($i = 0; $i < $requests; $i++) {
            $response = $this->claudeService->ask(
                $this->syntheticPrompt($promptTokens, $maxTokens, $i),
                '',
                $userId,
                ['model' => $model],
            );
            if (isset($response['error'])) {
                $errors++;
                $output->write('<error>x</error>');
                continue;
            }
            $inputTokens += (int)($response['usage']['input_tokens'] ?? 0);
            $outputTokens += (int)($response['usage']['output_tokens'] ?? 0);
            $output->write('.');
        }

        $output->writeln('');

        return [
            'elapsed' => microtime(true) - $started,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'errors' => $errors,
        ];
    }

    /** Overridable for tests. */
    protected function sleep(): void {
        sleep(10);
    }

    /**
     * A prompt of roughly the requested size that reliably produces roughly
     * $maxTokens of output.
     *
     * The request index goes into the text so no two requests are
     * byte-identical: repeating one prompt would let the API's cache answer
     * the rest and flatter every number here.
     */
    private function syntheticPrompt(int $promptTokens, int $maxTokens, int $index): string {
        $instruction = sprintf(
            "Write about %d tokens of continuous prose describing the passage below. Do not summarise it; elaborate. Item %d.\n\nPassage:\n",
            $maxTokens,
            $index,
        );

        $filler = 'The quick brown fox jumps over the lazy dog while the tired cat watches from a warm windowsill. ';
        $wanted = max(0, $promptTokens * self::CHARS_PER_TOKEN - strlen($instruction));

        return $instruction . substr(str_repeat($filler, (int)ceil($wanted / strlen($filler)) + 1), 0, $wanted);
    }

    /**
     * @param array{elapsed: float, input_tokens: int, output_tokens: int, outcomes: array<string, int>, refusals: list<string>, out_of_order: int, batch_id: string, ended: bool} $batch
     * @param array{elapsed: float, input_tokens: int, output_tokens: int, errors: int}|null $sync
     */
    private function report(OutputInterface $output, array $batch, ?array $sync, ?float $priceIn, ?float $priceOut): void {
        $withCost = $priceIn !== null && $priceOut !== null;

        $output->writeln('');
        $output->writeln(sprintf('  time to batch end     %s', $this->duration($batch['elapsed'])));

        $outcomes = array_filter($batch['outcomes'], static fn (int $n): bool => $n > 0);
        $output->writeln('  outcomes              ' . implode(', ', array_map(
            static fn (string $k, int $n): string => "$n $k",
            array_keys($outcomes),
            array_values($outcomes),
        )));
        $output->writeln(sprintf(
            '  out-of-order results  %s',
            $batch['out_of_order'] > 0
                ? $batch['out_of_order'] . ' of them arrived before an earlier request'
                : 'none this time (the API still promises nothing)'
        ));

        $output->writeln('');
        $header = sprintf('  %-12s %11s %11s %12s', 'variant', 'wall', 'tokens in', 'tokens out');
        if ($withCost) {
            $header .= sprintf(' %11s', 'est. cost');
        }
        $output->writeln('<info>' . $header . '</info>');

        $output->writeln($this->row('batch', $batch['elapsed'], $batch['input_tokens'], $batch['output_tokens'], $priceIn, $priceOut, self::BATCH_DISCOUNT));
        if ($sync !== null) {
            $output->writeln($this->row('sync', $sync['elapsed'], $sync['input_tokens'], $sync['output_tokens'], $priceIn, $priceOut, 1.0));
        }

        if ($batch['refusals'] !== []) {
            $output->writeln('');
            $output->writeln('<comment>  Refusals (verbatim — the Batch API offers no server-side fallback for these):</comment>');
            foreach (array_slice($batch['refusals'], 0, 5) as $refusal) {
                $output->writeln('    ' . $refusal);
            }
        }

        $output->writeln('');
        $output->writeln('  <comment>Batch cost is estimated at half the rate given; the API applies the discount itself.</comment>');
        if (!$withCost) {
            $output->writeln('  <comment>Pass --price-in and --price-out (USD per million tokens) for a cost column.</comment>');
        }
        $output->writeln('');
    }

    private function row(string $label, float $elapsed, int $inputTokens, int $outputTokens, ?float $priceIn, ?float $priceOut, float $discount): string {
        $line = sprintf('  %-12s %11s %11s %12s', $label, $this->duration($elapsed), number_format($inputTokens), number_format($outputTokens));
        if ($priceIn !== null && $priceOut !== null) {
            $cost = ((float)$inputTokens * $priceIn + (float)$outputTokens * $priceOut) * $discount / 1_000_000.0;
            $line .= sprintf(' %11s', '$' . number_format($cost, 4));
        }
        return $line;
    }

    private function duration(float $seconds): string {
        $whole = (int)$seconds;
        return sprintf('%02d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
