<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCA\AIquila\TaskProcessing\SummaryProvider;
use OCA\AIquila\TaskProcessing\TaskFailure;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskFailureTest extends TestCase {
    private const UNREACHABLE = 'The AI provider could not be reached. Try again later; if this keeps happening, ask your administrator to check the AIquila provider settings.';
    private const AUTH = 'The AI provider rejected AIquila\'s credentials. Check the API key in your AIquila settings, or ask your administrator.';
    private const RATE = 'The AI provider\'s rate limit or quota was reached. Try again later.';
    private const MODEL = 'The AI provider does not offer the configured model. Pick another model in your AIquila settings, or ask your administrator.';
    private const TOO_LARGE = 'The input is too large for the AI provider. Shorten it or split it into smaller parts.';
    private const NOT_CONFIGURED = 'The AI provider is not set up yet. Configure it in your AIquila settings, or ask your administrator.';

    /**
     * The errors the provider services actually return, so a rewording there
     * that drops out of its class shows up here.
     *
     * @return array<string, array{string, string}>
     */
    public static function providerErrors(): array {
        return [
            'local: nothing listening' => ['Nothing is listening at the local model endpoint (http://lmstudio:1234/v1). Is the backend running, and is the port right?', self::UNREACHABLE],
            'local: unresolved host' => ['The host in the local model endpoint (http://ollama:11434/v1) could not be resolved. From a container, "localhost" is the container itself — use host.docker.internal or the service name.', self::UNREACHABLE],
            'local: TLS with raw detail' => ['TLS failed against the local model endpoint (cURL error 60: SSL certificate problem: unable to get local issuer certificate). For a private CA, set a CA bundle path in the AIquila admin settings or import the CA with occ security:certificates:import.', self::UNREACHABLE],
            'local: blocked local address' => ['Nextcloud blocked the request to the local model endpoint. Enable "Allow local addresses" in the AIquila admin settings.', self::UNREACHABLE],
            'local: proxy auth' => ['An HTTP proxy demanded authentication (407). This is Nextcloud\'s own `proxy` / `proxyuserpwd` system configuration, not an AIquila setting.', self::UNREACHABLE],
            'local: 404' => ['The local model endpoint returned 404. Check the base URL and that the model is loaded.', self::MODEL],
            'local: basic auth' => ['The local model endpoint rejected the Basic credentials. Check the username and the key in the AIquila admin settings.', self::AUTH],
            'local: not configured' => ['No local model endpoint configured. Set one in the AIquila admin settings.', self::NOT_CONFIGURED],
            'claude: invalid key' => ['Invalid API key. Please check your configuration.', self::AUTH],
            'claude: permission' => ['Your API key does not have permission to use this resource.', self::AUTH],
            'claude: rate limit' => ['Rate limit exceeded. Please try again later.', self::RATE],
            'claude: server error' => ['Anthropic API is temporarily unavailable. Please try again later.', self::UNREACHABLE],
            'claude: connection' => ['Connection to Claude API failed: cURL error 28: Operation timed out after 30001 milliseconds', self::UNREACHABLE],
            'claude: prompt too long' => ['Error: prompt is too long: 215000 tokens > 200000 maximum', self::TOO_LARGE],
            'claude: batch credit' => ['invalid_request_error: Your credit balance is too low to access the Anthropic API.', self::RATE],
            'mistral: raw 404 model' => ['Mistral API error: Client error: `POST https://api.mistral.ai/v1/chat/completions` resulted in a `404 Not Found` response: {"message":"Invalid model: mistral-huge"}', self::MODEL],
            'hetzner: token' => ['Hetzner Inference rejected the token. Check it in the AIquila settings — tokens are created at https://experiments.hetzner.com/inference.', self::AUTH],
            'hetzner: not configured' => ['No Hetzner Inference token configured. Create one at https://experiments.hetzner.com/inference.', self::NOT_CONFIGURED],
            'hetzner: quota' => ['Hetzner Inference rate limit reached (per-token quota). Please try again shortly.', self::RATE],
            'hetzner: 503' => ['Hetzner Inference is currently unavailable. The service is experimental and offered without an availability guarantee.', self::UNREACHABLE],
            'openai-compatible: vision model' => ['DeepSeek is not configured with a vision-capable model. Please switch models or providers.', self::MODEL],
            'openai-compatible: 413' => ['DeepSeek API error: Client error: `POST https://api.deepseek.com/chat/completions` resulted in a `413 Payload Too Large` response', self::TOO_LARGE],
            'stream dropped' => ['The connection to Mistral dropped before the reply was complete.', self::UNREACHABLE],
        ];
    }

    #[DataProvider('providerErrors')]
    public function testProviderErrorsMapToTheirClass(string $error, string $expected): void {
        $e = TaskFailure::fromProviderError($error);

        $this->assertInstanceOf(UserFacingProcessingException::class, $e);
        $this->assertSame($expected, $e->getUserFacingMessage());
        // The detail stays the internal message: the log and the notification read it.
        $this->assertSame($error, $e->getMessage());
    }

    public function testUserFacingTextNeverCarriesTheUpstreamDetail(): void {
        $error = 'Mistral API error: 401 Unauthorized {"detail":"key sk-abc123 revoked"} at /var/www/html/custom_apps/aiquila';

        $e = TaskFailure::fromProviderError($error);

        $this->assertInstanceOf(UserFacingProcessingException::class, $e);
        $this->assertSame(self::AUTH, $e->getUserFacingMessage());
        $this->assertStringNotContainsString('sk-abc123', (string)$e->getUserFacingMessage());
    }

    public function testAnUnclassifiedErrorGetsNoUserFacingMessage(): void {
        $e = TaskFailure::fromProviderError('Max tool-use iterations reached');

        // Still a ProcessingException, so core logs it as a provider failure
        // rather than an unknown error, but nothing is guessed for the user.
        $this->assertInstanceOf(ProcessingException::class, $e);
        $this->assertNotInstanceOf(UserFacingProcessingException::class, $e);
        $this->assertSame('Max tool-use iterations reached', $e->getMessage());
    }

    public function testStatusCodesOnlyMatchAsWholeWords(): void {
        $this->assertNull(TaskFailure::classify('Request req_4290017 returned an empty body'));
    }

    public function testUserFacingKeepsAIquilasOwnMessage(): void {
        $previous = new \RuntimeException('inner');
        $e = TaskFailure::userFacing('No input text provided', $previous);

        $this->assertSame('No input text provided', $e->getMessage());
        $this->assertSame('No input text provided', $e->getUserFacingMessage());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testAProviderFailureReachesTheTaskAsUserFacing(): void {
        $local = $this->createStub(LLMProviderInterface::class);
        $local->method('ask')->willReturn(['error' => 'Nothing is listening at the local model endpoint (http://lmstudio:1234/v1). Is the backend running, and is the port right?']);
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($local);

        try {
            (new SummaryProvider(new ProviderResolver($factory)))->process('alice', ['input' => 'text'], static fn (float $p) => null);
            $this->fail('Expected the provider error to be thrown');
        } catch (UserFacingProcessingException $e) {
            $this->assertSame(self::UNREACHABLE, $e->getUserFacingMessage());
        }
    }

    public function testNoPermittedProviderIsUserFacing(): void {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willThrowException(new NoPermittedProviderException('alice'));

        try {
            (new ProviderResolver($factory))->resolve('alice');
            $this->fail('Expected the resolver to fail');
        } catch (UserFacingProcessingException $e) {
            // The bare message, not the internal one that names the user.
            $this->assertSame(NoPermittedProviderException::USER_MESSAGE, $e->getUserFacingMessage());
        }
    }
}
