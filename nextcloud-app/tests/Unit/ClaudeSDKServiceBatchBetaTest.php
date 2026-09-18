<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatch;
use Anthropic\Messages\Batches\MessageBatchRequestCounts;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the extended-output switch (`batch_output_300k`).
 *
 * Two halves that have to agree: the beta header on the create call, and the
 * lifted maxTokens ceiling in the request params. Either one alone is either
 * inert (header without ceiling) or a guaranteed 400 (ceiling without header).
 */
class BatchBetaTestableService extends ClaudeSDKService {
    public ?array $capturedOptions = null;
    public ?array $lastBatchRequests = null;

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    protected function callBatchCreate(Client $client, array $requests): MessageBatch {
        $this->lastBatchRequests = $requests;
        $this->capturedOptions = $this->batchRequestOptions();

        return MessageBatch::with(
            id: 'batch_beta_test',
            archivedAt: null,
            cancelInitiatedAt: null,
            createdAt: new \DateTime(),
            endedAt: null,
            expiresAt: new \DateTime('+24 hours'),
            processingStatus: 'in_progress',
            requestCounts: MessageBatchRequestCounts::with(
                canceled: 0, errored: 0, expired: 0, processing: 1, succeeded: 0,
            ),
            resultsURL: null,
        );
    }
}

class ClaudeSDKServiceBatchBetaTest extends TestCase {
    private function makeService(array $appConfig = []): BatchBetaTestableService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn ($app, $key, $default) => $appConfig[$key] ?? $default
        );
        $config->method('getUserValue')->willReturnCallback(fn ($u, $a, $k, $d) => $d);
        $logger = $this->createMock(LoggerInterface::class);
        $credentials = $this->createMock(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createMock(ICache::class);
        // No cached capabilities and no reachable API, so getMaxTokens() falls
        // back to the static ClaudeModels ceilings — which is what we assert on.
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        $requestMetadata = $this->createMock(RequestMetadataService::class);
        $requestMetadata->method('hashUserId')->willReturn('deadbeef');

        return new BatchBetaTestableService($config, $logger, $credentials, $cacheFactory, $requestMetadata);
    }

    private function submitOne(BatchBetaTestableService $svc): void {
        $svc->submitBatch([
            ['custom_id' => 'doc-1', 'messages' => [['role' => 'user', 'content' => 'body']]],
        ]);
    }

    public function testHeaderIsAbsentWhenTheSwitchIsOff(): void {
        $svc = $this->makeService(['model' => ClaudeModels::OPUS_5]);
        $this->submitOne($svc);

        $this->assertNull($svc->capturedOptions);
    }

    public function testHeaderIsPresentOnASupportedModel(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'batch_output_300k' => 'true',
        ]);
        $this->submitOne($svc);

        $this->assertSame(
            'output-300k-2026-03-24',
            $svc->capturedOptions['extraHeaders']['anthropic-beta']
        );
    }

    /**
     * Sending the header on a model that does not accept it risks a 400 on the
     * whole batch, so an unsupported model gets no header and no complaint.
     */
    public function testHeaderIsAbsentOnAnUnsupportedModel(): void {
        foreach ([ClaudeModels::HAIKU_4_5, ClaudeModels::FABLE_5] as $model) {
            $svc = $this->makeService(['model' => $model, 'batch_output_300k' => 'true']);
            $this->submitOne($svc);

            $this->assertNull($svc->capturedOptions, "$model must not carry the beta header");
        }
    }

    /** The flag has historically persisted as both 'true' and '1'. */
    public function testSwitchAcceptsBothStoredTruthyForms(): void {
        $svc = $this->makeService(['model' => ClaudeModels::OPUS_5, 'batch_output_300k' => '1']);
        $this->submitOne($svc);

        $this->assertNotNull($svc->capturedOptions);
    }

    public function testCeilingStaysAtTheStandardLimitWithTheSwitchOff(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => '250000',
        ]);
        $this->submitOne($svc);

        $this->assertSame(
            ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_5),
            $svc->lastBatchRequests[0]['params']['maxTokens']
        );
    }

    public function testCeilingIsLiftedWithTheSwitchOn(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => '250000',
            'batch_output_300k' => 'true',
        ]);
        $this->submitOne($svc);

        $this->assertSame(250000, $svc->lastBatchRequests[0]['params']['maxTokens']);
    }

    /**
     * The switch lifts a cap rather than setting one: an instance left on the
     * default max_tokens sees no change at all.
     */
    public function testSwitchAloneDoesNotRaiseAModestMaxTokens(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'batch_output_300k' => 'true',
        ]);
        $this->submitOne($svc);

        $this->assertSame(
            ClaudeModels::DEFAULT_MAX_TOKENS,
            $svc->lastBatchRequests[0]['params']['maxTokens']
        );
    }

    /** Interactive requests are unaffected; only the batch path changes. */
    public function testSynchronousMaxTokensIsUnaffected(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => '250000',
            'batch_output_300k' => 'true',
        ]);

        $this->assertSame(
            ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_5),
            $svc->getMaxTokens()
        );
    }

    // ── The model actually being sent, not the configured one ─────────────

    /**
     * A per-request `model` option pins what the request is for. Deriving the
     * header and the ceiling from the configured model instead would send
     * `output-300k` and a 300k cap alongside a model whose real ceiling is far
     * lower — which the API rejects, taking the whole batch with it.
     */
    public function testAPinnedUnsupportedModelSuppressesTheHeader(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => '250000',
            'batch_output_300k' => 'true',
        ]);

        $svc->submitBatch([[
            'custom_id' => 'doc-1',
            'messages' => [['role' => 'user', 'content' => 'body']],
            'options' => ['model' => ClaudeModels::HAIKU_4_5],
        ]]);

        $this->assertNull($svc->capturedOptions);
        $this->assertSame(
            ClaudeModels::getMaxTokenCeiling(ClaudeModels::HAIKU_4_5),
            $svc->lastBatchRequests[0]['params']['maxTokens']
        );
    }

    /**
     * The header is one HTTP option for the whole batch, so a single
     * unsupported model in it has to disqualify all of them.
     */
    public function testOneUnsupportedModelDisqualifiesTheWholeBatch(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => '250000',
            'batch_output_300k' => 'true',
        ]);

        $svc->submitBatch([
            ['custom_id' => 'doc-1', 'messages' => [['role' => 'user', 'content' => 'a']]],
            [
                'custom_id' => 'doc-2',
                'messages' => [['role' => 'user', 'content' => 'b']],
                'options' => ['model' => ClaudeModels::HAIKU_4_5],
            ],
        ]);

        $this->assertNull($svc->capturedOptions);
        foreach ($svc->lastBatchRequests as $request) {
            $this->assertLessThanOrEqual(
                ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_5),
                $request['params']['maxTokens']
            );
        }
    }

    /**
     * The reverse case: a capable model pinned on an instance whose default is
     * not capable must still get the header and the raised ceiling.
     */
    public function testAPinnedSupportedModelStillGetsTheHeader(): void {
        $svc = $this->makeService([
            'model' => ClaudeModels::HAIKU_4_5,
            'max_tokens' => '250000',
            'batch_output_300k' => 'true',
        ]);

        $svc->submitBatch([[
            'custom_id' => 'doc-1',
            'messages' => [['role' => 'user', 'content' => 'body']],
            'options' => ['model' => ClaudeModels::SONNET_5],
        ]]);

        $this->assertNotNull($svc->capturedOptions);
        $this->assertSame(250000, $svc->lastBatchRequests[0]['params']['maxTokens']);
    }
}
