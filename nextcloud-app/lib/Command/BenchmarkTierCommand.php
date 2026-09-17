<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Measure what `service_tier` and fast mode actually do on this instance.
 *
 * Both settings trade money for latency, and neither can be judged from the
 * documentation alone: priority capacity depends on what the organisation has
 * purchased, and fast mode's real speedup depends on the shape of the traffic.
 * So rather than committing numbers that would be wrong for everyone else, this
 * command lets an admin measure their own.
 *
 * It sends real requests and spends real money, hence the confirmation.
 */
class BenchmarkTierCommand extends Base {
    /** Roughly one token per four characters of English filler. */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private ClaudeSDKService $claudeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:benchmark-tier')
            ->setDescription('Measure latency for each service tier and for fast mode')
            ->addOption(
                'model',
                null,
                InputOption::VALUE_REQUIRED,
                'Model to benchmark (defaults to the configured model)'
            )
            ->addOption(
                'runs',
                null,
                InputOption::VALUE_REQUIRED,
                'Requests per variant',
                '5'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User whose API key to use (optional)'
            )
            ->addOption(
                'prompt-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Approximate size of the synthetic prompt, in tokens',
                '2000'
            )
            ->addOption(
                'max-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Output cap per request; also roughly how much output gets generated',
                '1024'
            )
            ->addOption(
                'price-in',
                null,
                InputOption::VALUE_REQUIRED,
                'Input price in USD per million tokens; enables the cost column'
            )
            ->addOption(
                'price-out',
                null,
                InputOption::VALUE_REQUIRED,
                'Output price in USD per million tokens; enables the cost column'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getOption('user');
        $model = ClaudeModels::resolveModel(
            (string)($input->getOption('model') ?: $this->claudeService->getModel($userId))
        );
        $runs = max(1, (int)$input->getOption('runs'));
        $promptTokens = max(1, (int)$input->getOption('prompt-tokens'));
        $maxTokens = max(1, (int)$input->getOption('max-tokens'));

        if ($this->claudeService->getApiKey($userId) === '') {
            $output->writeln('<error>No Anthropic API key configured.</error>');
            return 1;
        }

        $variants = $this->variantsFor($model);
        $total = count(array_filter($variants, static fn (array $v): bool => $v['skip'] === null)) * $runs;
        if ($total === 0) {
            $output->writeln('<error>Nothing to benchmark on ' . $model . '.</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Benchmarking ' . $model . '</info>');
        $output->writeln(sprintf(
            '  %d requests (%d per variant), ~%d prompt tokens and up to %d output tokens each.',
            $total,
            $runs,
            $promptTokens,
            $maxTokens,
        ));
        $output->writeln('  <comment>These are real API calls and are billed to your account.</comment>');
        $output->writeln('');

        if (!$input->getOption('yes') && !$this->confirm($input, $output)) {
            $output->writeln('Aborted.');
            return 0;
        }

        $results = [];
        foreach ($variants as $label => $variant) {
            if ($variant['skip'] !== null) {
                $output->writeln(sprintf('  <comment>%-14s skipped — %s</comment>', $label, $variant['skip']));
                continue;
            }

            $output->write(sprintf('  %-14s ', $label));
            $results[$label] = $this->runVariant(
                $output,
                $variant['options'] + ['max_tokens_hint' => $maxTokens],
                $runs,
                $promptTokens,
                $maxTokens,
                $userId,
            );
            $output->writeln('');
        }

        $output->writeln('');
        $this->renderTable(
            $output,
            $results,
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
     * The variants worth measuring on this model, in the order they are run.
     * A variant the model cannot serve carries the reason instead of options,
     * so the report says why a row is missing rather than silently dropping it.
     *
     * @return array<string, array{options: array<string, mixed>, skip: string|null}>
     */
    private function variantsFor(string $model): array {
        return [
            'standard_only' => [
                'options' => ['service_tier' => 'standard_only'],
                'skip' => null,
            ],
            'auto' => [
                // Always runnable: with no priority capacity the API serves it
                // at standard rather than failing, and the served-tier column
                // is what tells the two apart.
                'options' => ['service_tier' => 'auto'],
                'skip' => null,
            ],
            'fast' => [
                'options' => ['speed' => true],
                'skip' => ClaudeModels::supportsFastMode($model)
                    ? null
                    : 'fast mode is available on Opus 5 and Opus 4.8 only',
            ],
        ];
    }

    /**
     * @return array{latencies: list<float>, output_tokens: list<int>, input_tokens: list<int>, tiers: list<string>, errors: list<string>}
     */
    private function runVariant(
        OutputInterface $output,
        array $options,
        int $runs,
        int $promptTokens,
        int $maxTokens,
        ?string $userId,
    ): array {
        unset($options['max_tokens_hint']);

        $result = ['latencies' => [], 'output_tokens' => [], 'input_tokens' => [], 'tiers' => [], 'errors' => []];

        for ($i = 0; $i < $runs; $i++) {
            $started = microtime(true);
            $response = $this->claudeService->ask(
                $this->syntheticPrompt($promptTokens, $maxTokens, $i),
                '',
                $userId,
                $options,
            );
            $elapsed = (microtime(true) - $started) * 1000.0;

            if (isset($response['error'])) {
                $result['errors'][] = (string)$response['error'];
                $output->write('<error>x</error>');
                continue;
            }

            $result['latencies'][] = $elapsed;
            $result['input_tokens'][] = (int)($response['usage']['input_tokens'] ?? 0);
            $result['output_tokens'][] = (int)($response['usage']['output_tokens'] ?? 0);
            $result['tiers'][] = (string)($response['usage']['service_tier'] ?? '?');
            $output->write('.');
        }

        return $result;
    }

    /**
     * A prompt of roughly the requested size that reliably produces roughly
     * $maxTokens of output.
     *
     * The run index goes into the text so no two requests are byte-identical:
     * repeating one prompt would let the API's cache answer the later runs and
     * flatter every variant after the first.
     */
    private function syntheticPrompt(int $promptTokens, int $maxTokens, int $run): string {
        $instruction = sprintf(
            "Write about %d tokens of continuous prose describing the passage below. Do not summarise it; elaborate. Run %d.\n\nPassage:\n",
            $maxTokens,
            $run,
        );

        $filler = 'The quick brown fox jumps over the lazy dog while the tired cat watches from a warm windowsill. ';
        $wanted = max(0, $promptTokens * self::CHARS_PER_TOKEN - strlen($instruction));

        return $instruction . substr(str_repeat($filler, (int)ceil($wanted / strlen($filler)) + 1), 0, $wanted);
    }

    /**
     * @param array<string, array{latencies: list<float>, output_tokens: list<int>, input_tokens: list<int>, tiers: list<string>, errors: list<string>}> $results
     */
    private function renderTable(OutputInterface $output, array $results, ?float $priceIn, ?float $priceOut): void {
        $withCost = $priceIn !== null && $priceOut !== null;

        $header = sprintf('  %-14s %9s %9s %11s %10s  %s', 'variant', 'p50', 'p95', 'out tok/s', 'served', 'runs');
        if ($withCost) {
            $header .= sprintf(' %10s', '$/req');
        }
        $output->writeln('<info>' . $header . '</info>');

        foreach ($results as $label => $r) {
            $ok = count($r['latencies']);
            if ($ok === 0) {
                $output->writeln(sprintf('  %-14s %s', $label, '<error>all ' . count($r['errors']) . ' requests failed</error>'));
                continue;
            }

            $p50 = $this->percentile($r['latencies'], 0.50);
            $p95 = $this->percentile($r['latencies'], 0.95);
            // Throughput over the whole call, so it includes time to first
            // token. That is the number a user actually waits through.
            $tokensPerSecond = (float)array_sum($r['output_tokens']) / (array_sum($r['latencies']) / 1000.0);

            $line = sprintf(
                '  %-14s %8.0fms %8.0fms %11.1f %10s  %d/%d',
                $label,
                $p50,
                $p95,
                $tokensPerSecond,
                implode('+', array_unique($r['tiers'])),
                $ok,
                $ok + count($r['errors']),
            );

            if ($withCost) {
                $line .= sprintf(
                    ' %10.5f',
                    ((float)array_sum($r['input_tokens']) * $priceIn + (float)array_sum($r['output_tokens']) * $priceOut) / (1_000_000.0 * (float)$ok),
                );
            }

            $output->writeln($line);
        }

        $failed = array_filter($results, static fn (array $r): bool => $r['errors'] !== []);
        if ($failed !== []) {
            $output->writeln('');
            foreach ($failed as $label => $r) {
                $output->writeln(sprintf('  <error>%s: %s</error>', $label, $r['errors'][0]));
            }
        }

        $output->writeln('');
        $output->writeln('  <comment>"served" is the tier the API reports having used, which is not always the one asked for.</comment>');
        $output->writeln('  <comment>Fast mode is not reported back on this endpoint — throughput is the evidence for that row.</comment>');
        if (!$withCost) {
            $output->writeln('  <comment>Pass --price-in and --price-out (USD per million tokens) for a cost column.</comment>');
        }
        $output->writeln('');
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $p): float {
        sort($values);
        $index = (int)ceil($p * (float)count($values)) - 1;
        return $values[max(0, $index)];
    }
}
