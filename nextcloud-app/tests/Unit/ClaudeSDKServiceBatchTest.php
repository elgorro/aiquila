<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatch;
use Anthropic\Messages\Batches\MessageBatchIndividualResponse;
use Anthropic\Messages\Batches\MessageBatchRequestCounts;
use Anthropic\Messages\Batches\MessageBatchCanceledResult;
use Anthropic\Messages\Batches\MessageBatchErroredResult;
use Anthropic\Messages\Batches\MessageBatchExpiredResult;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\ErrorResponse;
use Anthropic\InvalidRequestError;
use Anthropic\Messages\Message;
use Anthropic\Messages\RefusalStopDetails;
use Anthropic\Messages\Metadata;
use Anthropic\Messages\Usage;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Subclass for ClaudeSDKService::summarizeViaBatch() coverage.
 * Stubs callBatchCreate / callBatchRetrieve / callBatchResults and
 * suppresses the inter-poll sleep so the test runs in milliseconds.
 */
class BatchTestableService extends ClaudeSDKService {
    /** @var list<string> processingStatus values returned in order across retrieve calls */
    public array $retrieveStatuses = ['ended'];
    public string $resultText = 'a concise summary';
    public int $createCalls = 0;
    public int $retrieveCalls = 0;
    public int $sleepCalls = 0;
    public ?array $lastBatchRequests = null;
    public int $cancelCalls = 0;
    /**
     * When non-empty, callBatchResults() yields these instead of a single
     * success — in exactly this order, so a test can feed results back out of
     * submission order.
     *
     * @var list<array{custom_id: string, kind: string, text?: string}>
     */
    public array $resultSpecs = [];

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    protected function callBatchCreate(Client $client, array $requests): MessageBatch {
        $this->createCalls++;
        $this->lastBatchRequests = $requests;
        return $this->makeBatch('in_progress');
    }

    protected function callBatchRetrieve(Client $client, string $batchId): MessageBatch {
        $idx = min($this->retrieveCalls, count($this->retrieveStatuses) - 1);
        $status = $this->retrieveStatuses[$idx];
        $this->retrieveCalls++;
        return $this->makeBatch($status);
    }

    protected function callBatchResults(Client $client, string $batchId): iterable {
        if ($this->resultSpecs !== []) {
            foreach ($this->resultSpecs as $spec) {
                yield $this->makeSpecResponse($spec);
            }
            return;
        }
        $customId = $this->lastBatchRequests[0]['custom_id'];
        yield $this->makeIndividualResponse($customId, $this->resultText);
    }

    protected function callBatchCancel(Client $client, string $batchId): MessageBatch {
        $this->cancelCalls++;
        return $this->makeBatch('canceling');
    }

    protected function sleepBetweenBatchPolls(): void {
        $this->sleepCalls++;
    }

    private function makeBatch(string $status): MessageBatch {
        $counts = MessageBatchRequestCounts::with(
            canceled: 0, errored: 0, expired: 0,
            processing: $status === 'ended' ? 0 : 1,
            succeeded: $status === 'ended' ? 1 : 0,
        );
        return MessageBatch::with(
            id: 'batch_test',
            archivedAt: null,
            cancelInitiatedAt: null,
            createdAt: new \DateTime(),
            endedAt: $status === 'ended' ? new \DateTime() : null,
            expiresAt: new \DateTime('+24 hours'),
            processingStatus: $status,
            requestCounts: $counts,
            resultsURL: $status === 'ended' ? 'https://example.invalid/r' : null,
        );
    }

    /** @param array{custom_id: string, kind: string, text?: string} $spec */
    private function makeSpecResponse(array $spec): MessageBatchIndividualResponse {
        $customId = $spec['custom_id'];
        switch ($spec['kind']) {
            case 'refused':
                return MessageBatchIndividualResponse::with(
                    customID: $customId,
                    result: MessageBatchSucceededResult::with(
                        message: $this->makeMessage('', 'refusal')
                    ),
                );
            case 'errored':
                $error = new InvalidRequestError();
                $error->message = 'prompt is too long';
                return MessageBatchIndividualResponse::with(
                    customID: $customId,
                    result: MessageBatchErroredResult::with(
                        error: ErrorResponse::with(error: $error, requestID: null)
                    ),
                );
            case 'canceled':
                return MessageBatchIndividualResponse::with(
                    customID: $customId,
                    result: new MessageBatchCanceledResult(),
                );
            case 'expired':
                return MessageBatchIndividualResponse::with(
                    customID: $customId,
                    result: new MessageBatchExpiredResult(),
                );
            default:
                return $this->makeIndividualResponse($customId, $spec['text'] ?? $this->resultText);
        }
    }

    private function makeMessage(string $text, string $stopReason): Message {
        $msg = (new \ReflectionClass(Message::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass($msg);

        $textObj = new \stdClass();
        $textObj->type = 'text';
        $textObj->text = $text;

        foreach (['content' => [$textObj], 'stopReason' => $stopReason] as $prop => $val) {
            $ref->getProperty($prop)->setValue($msg, $val);
        }
        $ref->getProperty('usage')->setValue($msg, Usage::with(null, null, null, null, 5, 7, null, null, null));

        if ($stopReason === 'refusal') {
            $ref->getProperty('stopDetails')->setValue(
                $msg,
                RefusalStopDetails::with(category: 'cyber', explanation: 'Declined by policy')
            );
        }

        return $msg;
    }

    private function makeIndividualResponse(string $customId, string $text): MessageBatchIndividualResponse {
        $msg = (new \ReflectionClass(Message::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass($msg);

        $textObj = new \stdClass();
        $textObj->type = 'text';
        $textObj->text = $text;

        foreach (['content' => [$textObj], 'stopReason' => 'end_turn'] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setValue($msg, $val);
        }
        $usage = Usage::with(null, null, null, null, 5, 7, null, null, null);
        $up = $ref->getProperty('usage');
        $up->setValue($msg, $usage);

        return MessageBatchIndividualResponse::with(
            customID: $customId,
            result: MessageBatchSucceededResult::with(message: $msg),
        );
    }
}

class ClaudeSDKServiceBatchTest extends TestCase {
    private function makeService(array $appConfig = []): BatchTestableService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn ($app, $key, $default) => $appConfig[$key] ?? $default
        );
        $config->method('getUserValue')->willReturnCallback(fn ($u, $a, $k, $d) => $d);
        $logger = $this->createMock(LoggerInterface::class);
        $credentials = $this->createMock(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        $requestMetadata = $this->createMock(RequestMetadataService::class);
        $requestMetadata->method('hashUserId')->willReturn('deadbeef');
        return new BatchTestableService($config, $logger, $credentials, $cacheFactory, $requestMetadata);
    }

    public function testBatchSummarySucceedsOnFirstPoll(): void {
        $svc = $this->makeService();
        $svc->retrieveStatuses = ['ended'];

        $progress = [];
        $result = $svc->summarizeViaBatch('long content here', null, function ($p) use (&$progress) {
            $progress[] = $p;
        });

        $this->assertSame('a concise summary', $result['response']);
        $this->assertSame(5, $result['usage']['input_tokens']);
        $this->assertSame(7, $result['usage']['output_tokens']);
        $this->assertSame([], $result['citations']);
        $this->assertSame(1, $svc->createCalls);
        $this->assertSame(1, $svc->retrieveCalls);
        $this->assertSame(0, $svc->sleepCalls);
        $this->assertNotEmpty($progress);
        $this->assertGreaterThanOrEqual(0.9, end($progress));
    }

    public function testBatchSummaryPollsUntilEnded(): void {
        $svc = $this->makeService();
        $svc->retrieveStatuses = ['in_progress', 'in_progress', 'ended'];

        $result = $svc->summarizeViaBatch('content', null, null);

        $this->assertSame('a concise summary', $result['response']);
        $this->assertSame(3, $svc->retrieveCalls);
        $this->assertSame(2, $svc->sleepCalls); // two waits between three polls
    }

    public function testBatchRequestShapeMatchesSummaryPrompt(): void {
        $svc = $this->makeService();
        $svc->summarizeViaBatch('document body', null, null);

        $this->assertCount(1, $svc->lastBatchRequests);
        $req = $svc->lastBatchRequests[0];
        $this->assertStringStartsWith('aiquila-summary-', $req['custom_id']);
        $this->assertArrayHasKey('maxTokens', $req['params']);
        $this->assertArrayHasKey('messages', $req['params']);
        $this->assertSame('user', $req['params']['messages'][0]['role']);
        $this->assertStringContainsString('Summarize', $req['params']['messages'][0]['content']);
        $this->assertStringContainsString('document body', $req['params']['messages'][0]['content']);
    }

    /**
     * toBatchParams() translates a whitelist of keys, so a new one is dropped
     * unless it is handled — and metadata has to arrive as an SDK model, not
     * as the snake_case array the Messages API params carry.
     */
    public function testBatchParamsCarryHashedUserMetadata(): void {
        $svc = $this->makeService();
        $svc->summarizeViaBatch('document body', 'testuser', null);

        $metadata = $svc->lastBatchRequests[0]['params']['metadata'];
        $this->assertInstanceOf(Metadata::class, $metadata);
        $this->assertSame('deadbeef', $metadata->userID);
    }

    /** Batches accept a service tier, and it has to survive the key translation. */
    public function testBatchParamsCarryServiceTier(): void {
        $svc = $this->makeService(['service_tier' => 'standard_only']);
        $svc->summarizeViaBatch('document body', 'testuser', null);

        $this->assertSame('standard_only', $svc->lastBatchRequests[0]['params']['serviceTier']);
    }

    /**
     * Fast mode is not offered on the Batch API, so an instance that defaults
     * it on must not leak it into batch requests — the API would reject them.
     */
    public function testBatchParamsDropFastMode(): void {
        $svc = $this->makeService(['model' => 'claude-opus-5', 'speed_fast' => 'true']);
        $svc->summarizeViaBatch('document body', 'testuser', null);

        $params = $svc->lastBatchRequests[0]['params'];
        $this->assertArrayNotHasKey('speed', $params);
        $this->assertArrayNotHasKey('speed_fast', $params);
    }

    // ── Multi-request transport ───────────────────────────────────────────

    public function testSubmitBatchBuildsOneEntryPerRequest(): void {
        $svc = $this->makeService();

        $batchId = $svc->submitBatch([
            ['custom_id' => 'doc-1', 'messages' => [['role' => 'user', 'content' => 'first']]],
            ['custom_id' => 'doc-2', 'messages' => [['role' => 'user', 'content' => 'second']]],
        ]);

        $this->assertSame('batch_test', $batchId);
        $this->assertSame(1, $svc->createCalls, 'N requests go out as one batch');
        $this->assertCount(2, $svc->lastBatchRequests);
        $this->assertSame('doc-1', $svc->lastBatchRequests[0]['custom_id']);
        $this->assertSame('second', $svc->lastBatchRequests[1]['params']['messages'][0]['content']);
    }

    public function testSubmitBatchRejectsDuplicateCustomIds(): void {
        $svc = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate custom_id');
        $svc->submitBatch([
            ['custom_id' => 'same', 'messages' => [['role' => 'user', 'content' => 'a']]],
            ['custom_id' => 'same', 'messages' => [['role' => 'user', 'content' => 'b']]],
        ]);
    }

    public function testSubmitBatchRejectsMalformedCustomId(): void {
        $svc = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $svc->submitBatch([
            ['custom_id' => 'has spaces', 'messages' => [['role' => 'user', 'content' => 'a']]],
        ]);
    }

    public function testSubmitBatchRejectsAnEmptyBatch(): void {
        $svc = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $svc->submitBatch([]);
    }

    public function testSubmitBatchRejectsMoreRequestsThanTheLimit(): void {
        $svc = $this->makeService();

        $requests = [];
        for ($i = 0; $i <= ClaudeSDKService::MAX_BATCH_REQUESTS; $i++) {
            $requests[] = ['custom_id' => "doc-$i", 'messages' => [['role' => 'user', 'content' => 'x']]];
        }

        $this->expectException(\InvalidArgumentException::class);
        $svc->submitBatch($requests);
    }

    /**
     * The API makes no ordering promise, so results are keyed by custom_id.
     * Feeding them back reversed is the only way to catch a positional read.
     */
    public function testFetchBatchResultsKeysByCustomIdRegardlessOfOrder(): void {
        $svc = $this->makeService();
        $svc->resultSpecs = [
            ['custom_id' => 'doc-3', 'kind' => 'success', 'text' => 'third'],
            ['custom_id' => 'doc-1', 'kind' => 'success', 'text' => 'first'],
            ['custom_id' => 'doc-2', 'kind' => 'success', 'text' => 'second'],
        ];

        $results = $svc->fetchBatchResults('batch_test');

        $this->assertSame('first', $results['doc-1']['response']);
        $this->assertSame('second', $results['doc-2']['response']);
        $this->assertSame('third', $results['doc-3']['response']);
    }

    public function testFetchBatchResultsTagsEachFailureKind(): void {
        $svc = $this->makeService();
        $svc->resultSpecs = [
            ['custom_id' => 'ok', 'kind' => 'success', 'text' => 'fine'],
            ['custom_id' => 'no', 'kind' => 'refused'],
            ['custom_id' => 'bad', 'kind' => 'errored'],
            ['custom_id' => 'stopped', 'kind' => 'canceled'],
            ['custom_id' => 'late', 'kind' => 'expired'],
        ];

        $results = $svc->fetchBatchResults('batch_test');

        $this->assertArrayNotHasKey('error', $results['ok']);
        // A refusal arrives as a *succeeded* result, so without unpacking it
        // would read back as an empty response rather than a failure.
        $this->assertSame('refused', $results['no']['error_type']);
        $this->assertSame('Declined by policy', $results['no']['error']);
        $this->assertSame('cyber', $results['no']['refusal_category']);
        $this->assertSame('errored', $results['bad']['error_type']);
        $this->assertStringContainsString('prompt is too long', $results['bad']['error']);
        $this->assertSame('canceled', $results['stopped']['error_type']);
        $this->assertSame('expired', $results['late']['error_type']);
    }

    /** A request with no result at all is the caller's to report, not an exception. */
    public function testFetchBatchResultsOmitsCustomIdsWithNoResult(): void {
        $svc = $this->makeService();
        $svc->resultSpecs = [['custom_id' => 'doc-1', 'kind' => 'success', 'text' => 'only one']];

        $results = $svc->fetchBatchResults('batch_test');

        $this->assertArrayHasKey('doc-1', $results);
        $this->assertArrayNotHasKey('doc-2', $results);
    }

    public function testGetBatchStatusReportsCounts(): void {
        $svc = $this->makeService();
        $svc->retrieveStatuses = ['ended'];

        $status = $svc->getBatchStatus('batch_test');

        $this->assertTrue($status['ended']);
        $this->assertSame('ended', $status['status']);
        $this->assertSame(1, $status['counts']['succeeded']);
        $this->assertSame(0, $status['counts']['processing']);
    }

    public function testCancelBatchReportsSuccess(): void {
        $svc = $this->makeService();

        $this->assertTrue($svc->cancelBatch('batch_test'));
        $this->assertSame(1, $svc->cancelCalls);
    }
}
