<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LocalProvider;
use OCA\AIquila\Service\Provider\ModelCompatibility;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Backend detection and per-model compatibility for the local provider.
 *
 * The LM Studio fixtures are recorded from a real LM Studio server
 * (/api/v1/models, /api/v0/models, its 200 answer to an unknown endpoint, and
 * /v1/models). The Ollama ones follow the documented /api/tags shape and the
 * `capabilities` values in Ollama's types/model/capability.go.
 */
class LocalProviderCatalogTest extends TestCase {
    private const BASE = 'http://lm.local:1234';
    private const FIXTURES = __DIR__ . '/../fixtures/local-models/';

    private $client;
    private $config;
    private $credentials;
    /** @var list<string> URLs requested, in order */
    private array $requested = [];

    protected function setUp(): void {
        $this->client = $this->createStub(IClient::class);
        $this->config = $this->createStub(AppSettings::class);
        $this->credentials = $this->createStub(CredentialService::class);
        $this->config->method('getUserValue')->willReturn('');
        $this->credentials->method('getApiKey')->willReturn('');
        $this->credentials->method('getSecret')->willReturn('');
    }

    /** @param array<string, string> $appValues */
    private function provider(array $appValues = []): LocalProvider {
        $appValues += ['local_base_url' => self::BASE];
        $this->config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $appValues[$key] ?? $default
        );
        $clientService = $this->createStub(IClientService::class);
        $clientService->method('newClient')->willReturn($this->client);
        return new LocalProvider($clientService, $this->config, $this->credentials, $this->createStub(LoggerInterface::class));
    }

    /**
     * Route GETs by path. A path mapped to a Throwable throws it; an unmapped
     * path answers 404 the way Guzzle does (status as the exception code).
     *
     * @param array<string, string|\Throwable> $routes path => body
     * @param array<string, string> $shows Ollama model => /api/show body
     */
    private function routes(array $routes, array $shows = []): void {
        $this->client->method('get')->willReturnCallback(function (string $url) use ($routes) {
            $this->requested[] = $url;
            $path = substr($url, strlen(self::BASE));
            $answer = $routes[$path] ?? new \RuntimeException('Client error: `GET ' . $url . '` resulted in a `404 Not Found` response', 404);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $this->body($answer);
        });
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use ($shows) {
            $this->requested[] = $url;
            $model = json_decode($opts['body'], true)['model'] ?? '';
            if (!isset($shows[$model])) {
                throw new \RuntimeException('Server error', 500);
            }
            return $this->body($shows[$model]);
        });
    }

    private function body(string $json): IResponse {
        $response = $this->createStub(IResponse::class);
        $response->method('getBody')->willReturn($json);
        return $response;
    }

    private static function fixture(string $name): string {
        return (string)file_get_contents(self::FIXTURES . $name);
    }

    /** @return array<string, string> model => /api/show body */
    private static function ollamaShows(): array {
        return array_map(
            static fn($show) => (string)json_encode($show),
            json_decode(self::fixture('ollama-show.json'), true),
        );
    }

    // ── LM Studio ───────────────────────────────────────────────────────

    public function testLmStudioV1ReportsTypeVisionToolsAndLoadState(): void {
        $this->routes(['/api/v1/models' => self::fixture('lmstudio-v1-models.json')]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertFalse($catalog->isFailure());
        $this->assertSame(LocalProvider::SOURCE_LMSTUDIO, $catalog->source);
        $this->assertSame(['prism-ml/bonsai-27b', 'text-embedding-nomic-embed-text-v1.5'], $catalog->ids());

        $chat = $catalog->find('prism-ml/bonsai-27b');
        $this->assertSame(ModelCompatibility::STATUS_OK, $chat?->status);
        $this->assertSame('Bonsai 27B', $chat->label);
        $this->assertTrue($chat->tools);
        $this->assertTrue($chat->vision);
        $this->assertTrue($chat->loaded);
        $this->assertSame('', $chat->message);

        $embed = $catalog->find('text-embedding-nomic-embed-text-v1.5');
        $this->assertSame(ModelCompatibility::STATUS_INCOMPATIBLE, $embed?->status);
        $this->assertFalse($embed->isSelectable());
        $this->assertSame(
            'Nomic Embed Text v1.5 (text-embedding-nomic-embed-text-v1.5) only creates embeddings and can\'t chat. Pick a chat model.',
            $embed->message,
        );
        $this->assertSame([self::BASE . '/api/v1/models'], $this->requested, 'one request when v1 answers');
    }

    public function testLmStudioModelNotLoadedOrNotTrainedForToolsIsExplained(): void {
        $this->routes(['/api/v1/models' => (string)json_encode(['models' => [[
            'type' => 'llm',
            'key' => 'qwen/qwen3-4b',
            'display_name' => 'Qwen3 4B',
            'loaded_instances' => [],
            'capabilities' => ['vision' => false, 'trained_for_tool_use' => false],
        ]]])]);
        $m = $this->provider()->fetchModelCatalog()->find('qwen/qwen3-4b');

        $this->assertSame(ModelCompatibility::STATUS_NO_TOOLS, $m?->status);
        $this->assertTrue($m->isSelectable());
        $this->assertFalse($m->loaded);
        $this->assertSame(
            'Qwen3 4B (qwen/qwen3-4b) isn\'t trained for tool use. LM Studio still offers it tools in a generic format, so Nextcloud tools and Cowork may work poorly with it.'
            . ' It isn\'t loaded in LM Studio yet. With just-in-time loading on (the default) LM Studio loads it on the first request; with it off, LM Studio answers with whichever model is loaded instead, so load this one first.',
            $m->message,
        );
    }

    public function testLmStudioV0IsTheFallbackForOlderServers(): void {
        $this->routes(['/api/v0/models' => self::fixture('lmstudio-v0-models.json')]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertSame(LocalProvider::SOURCE_LMSTUDIO_V0, $catalog->source);
        $chat = $catalog->find('prism-ml/bonsai-27b');
        $this->assertSame(ModelCompatibility::STATUS_OK, $chat?->status);
        $this->assertTrue($chat->vision, '"vlm" means vision');
        $this->assertTrue($chat->tools, '"tool_use" in capabilities');
        $this->assertTrue($chat->loaded);
        $this->assertSame(
            'text-embedding-nomic-embed-text-v1.5 only creates embeddings and can\'t chat. Pick a chat model.',
            $catalog->find('text-embedding-nomic-embed-text-v1.5')?->message,
        );
    }

    public function testAnUnknownLmStudioEndpointAnswering200IsNotMistakenForAListing(): void {
        // LM Studio answers paths it does not know with 200 and an error body.
        $unknown = self::fixture('lmstudio-unknown-endpoint.json');
        $this->routes([
            '/api/v1/models' => $unknown,
            '/api/v0/models' => $unknown,
            '/api/tags' => $unknown,
            '/v1/models' => self::fixture('lmstudio-openai-models.json'),
        ]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertSame(LocalProvider::SOURCE_OPENAI, $catalog->source);
        $this->assertSame(['prism-ml/bonsai-27b', 'text-embedding-nomic-embed-text-v1.5'], $catalog->ids());
    }

    public function testAnLmStudioModelThatIsNotListedSaysItWillBeSubstituted(): void {
        $this->routes(['/api/v1/models' => self::fixture('lmstudio-v1-models.json')]);
        $this->assertSame(
            'typo isn\'t in the model list from Local model. Download it in LM Studio (or with `lms get`), or pick one from the list. LM Studio does not reject an unknown model: it answers with whichever model is loaded.',
            $this->provider()->fetchModelCatalog()->assess('typo')?->message,
        );
    }

    // ── Ollama ──────────────────────────────────────────────────────────

    public function testOllamaCapabilitiesComeFromApiShow(): void {
        $this->routes(['/api/tags' => self::fixture('ollama-tags.json')], self::ollamaShows());
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertSame(LocalProvider::SOURCE_OLLAMA, $catalog->source);

        $llama = $catalog->find('llama3.2:latest');
        $this->assertSame(ModelCompatibility::STATUS_OK, $llama?->status);
        $this->assertTrue($llama->tools);
        $this->assertFalse($llama->vision);

        $llava = $catalog->find('llava:7b');
        $this->assertSame(ModelCompatibility::STATUS_NO_TOOLS, $llava?->status);
        $this->assertTrue($llava->vision);

        $this->assertSame(
            'gemma:2b doesn\'t support tool calling. Plain chat works, but Nextcloud tools and Cowork need a model with tool support.',
            $catalog->find('gemma:2b')?->message,
        );
        $this->assertSame(
            'nomic-embed-text:latest only creates embeddings and can\'t chat. Pick a chat model.',
            $catalog->find('nomic-embed-text:latest')?->message,
        );
        $this->assertFalse($catalog->find('nomic-embed-text:latest')->isSelectable());

        $this->assertContains(self::BASE . '/api/show', $this->requested);
    }

    public function testAnOllamaModelThatIsNotPulledGetsThePullCommand(): void {
        $this->routes(['/api/tags' => self::fixture('ollama-tags.json')], self::ollamaShows());
        $this->assertSame(
            'qwen3:8b isn\'t in the model list from Local model. Pull it on the Ollama host with `ollama pull qwen3:8b`, or pick one from the list.',
            $this->provider()->fetchModelCatalog()->assess('qwen3:8b')?->message,
        );
    }

    public function testOllamaWithoutCapabilitiesListsModelsUnverified(): void {
        // Releases before the field existed, or a failing /api/show.
        $this->routes(['/api/tags' => self::fixture('ollama-tags.json')], [
            'llama3.2:latest' => (string)json_encode(['modelfile' => '', 'details' => []]),
        ]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertSame(ModelCompatibility::STATUS_UNVERIFIED, $catalog->find('llama3.2:latest')?->status);
        $this->assertSame(
            'llava:7b is not verified: Ollama did not report its capabilities. Updating Ollama adds them.',
            $catalog->find('llava:7b')?->message,
        );
    }

    // ── llama.cpp & co ──────────────────────────────────────────────────

    public function testAnOpenAiOnlyBackendListsEveryModelUnverified(): void {
        $this->routes(['/v1/models' => (string)json_encode(['object' => 'list', 'data' => [['id' => 'qwen2.5-7b-instruct-q4_k_m.gguf', 'object' => 'model']]])]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertSame(LocalProvider::SOURCE_OPENAI, $catalog->source);
        $m = $catalog->find('qwen2.5-7b-instruct-q4_k_m.gguf');
        $this->assertSame(ModelCompatibility::STATUS_UNVERIFIED, $m?->status);
        $this->assertTrue($m->isSelectable());
        $this->assertStringContainsString('is not verified: this endpoint only offers the OpenAI-compatible /v1/models list', $m->message);
    }

    // ── Failures ────────────────────────────────────────────────────────

    public function testATransportFailureStopsAtTheFirstRequestWithTheProvidersSentence(): void {
        $this->routes(['/api/v1/models' => new \RuntimeException('cURL error 7: Failed to connect to lm.local port 1234: Connection refused')]);
        $catalog = $this->provider()->fetchModelCatalog();

        $this->assertTrue($catalog->isFailure());
        $this->assertCount(1, $this->requested, 'no further probes against a dead host');
        $this->assertSame(
            'Couldn\'t load the model list from Local model: Nothing is listening at the local model endpoint (' . self::BASE . '/v1). Is the backend running, and is the port right? Showing the built-in list.',
            $catalog->failureMessage(),
        );
    }

    public function testListModelsReturnsTheCatalogIdsOrNull(): void {
        $this->routes(['/api/v1/models' => self::fixture('lmstudio-v1-models.json')]);
        $this->assertSame(['prism-ml/bonsai-27b', 'text-embedding-nomic-embed-text-v1.5'], $this->provider()->listModels());
    }

    public function testUnconfiguredCatalogIsAFailureWithoutRequests(): void {
        $this->routes([]);
        $catalog = $this->provider(['local_base_url' => ''])->fetchModelCatalog();
        $this->assertTrue($catalog->isFailure());
        $this->assertSame([], $this->requested);
    }

    // ── Runtime errors ──────────────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function ollamaRuntimeErrors(): array {
        return [
            'tools refused' => [
                'Client error: `POST http://lm.local:1234/v1/chat/completions` resulted in a `400 Bad Request` response: {"error":{"message":"registry.ollama.ai/library/gemma:2b does not support tools","type":"invalid_request_error"}}',
                'gemma:2b can\'t use tools. Pick a model with tool support or turn tools off.',
            ],
            'cannot chat' => [
                'Client error: `POST http://lm.local:1234/v1/chat/completions` resulted in a `400 Bad Request` response: {"error":{"message":"\"gemma:2b\" does not support chat"}}',
                'gemma:2b can\'t chat. Pick a chat model.',
            ],
            'not pulled' => [
                'Client error: `POST http://lm.local:1234/v1/chat/completions` resulted in a `404 Not Found` response: {"error":{"message":"model \"gemma:2b\" not found, try pulling it first"}}',
                'gemma:2b isn\'t in the model list from Local model. Pull it on the Ollama host with `ollama pull gemma:2b`, or pick one from the list.',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ollamaRuntimeErrors')]
    public function testRuntimeModelErrorsBecomeTheSameSentences(string $raw, string $expected): void {
        $this->client->method('post')->willThrowException(new \RuntimeException($raw, 400));
        $result = $this->provider()->chat([['role' => 'user', 'content' => 'hi']], null, null, ['model' => 'gemma:2b']);
        $this->assertSame($expected, $result['error'] ?? null);
    }
}
