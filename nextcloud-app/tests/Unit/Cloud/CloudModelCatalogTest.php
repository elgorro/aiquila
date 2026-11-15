<?php

namespace OCA\AIquila\Tests\Unit\Cloud;

use Anthropic\Core\BaseClient;
use Anthropic\Core\Exceptions\APIStatusException;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\Cloud\AbstractCloudClaudeProvider;
use OCA\AIquila\Service\Provider\Cloud\BedrockModelCatalog;
use OCA\AIquila\Service\Provider\Cloud\BedrockProvider;
use OCA\AIquila\Service\Provider\Cloud\ClaudePlatformAwsProvider;
use OCA\AIquila\Service\Provider\Cloud\CloudSdk;
use OCA\AIquila\Service\Provider\Cloud\FoundryProvider;
use OCA\AIquila\Service\Provider\Cloud\VertexProvider;
use OCA\AIquila\Service\Provider\ModelCatalog;
use OCA\AIquila\Service\Provider\ModelCompatibility;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/** Bedrock with the control plane answered from fixtures. */
class FixtureBedrockProvider extends BedrockProvider {
    /** @var array<string, array<array-key, mixed>|\Throwable> keyed "path?type&nextToken" */
    public array $responses = [];
    /** @var list<string> */
    public array $requested = [];

    protected function controlPlaneClient(): BaseClient {
        return (new \ReflectionClass(\Anthropic\Bedrock\Client::class))->newInstanceWithoutConstructor();
    }

    protected function callControlPlane(BaseClient $client, string $path, array $query): array {
        $key = $path . (isset($query['type']) ? '?' . $query['type'] : '') . (isset($query['nextToken']) ? '&' . $query['nextToken'] : '');
        $this->requested[] = $key;
        $answer = $this->responses[$key] ?? ['inferenceProfileSummaries' => []];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        return $answer;
    }
}

class CloudModelCatalogTest extends TestCase {
    /** @var array<string, string> */
    private array $app = [];
    /** @var array<string, string> */
    private array $keys = [];

    private static function fixture(string $name): array {
        return json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/bedrock-models/' . $name), true);
    }

    /**
     * @template T of AbstractCloudClaudeProvider
     * @param class-string<T> $class
     * @return T
     */
    private function provider(string $class): AbstractCloudClaudeProvider {
        $config = $this->createStub(AppSettings::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->app[$key] ?? $default);
        $config->method('getUserValue')->willReturn('');
        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('getApiKey')->willReturnCallback(fn (?string $uid, string $provider = 'anthropic') => $this->keys[$provider] ?? '');
        $credentials->method('getSecret')->willReturn('');
        $sdk = $this->createStub(CloudSdk::class);
        $sdk->method('isAvailable')->willReturn(true);
        $cache = $this->createStub(ICache::class);
        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        return new $class($config, $this->createStub(LoggerInterface::class), $credentials, $cacheFactory, $this->createStub(RequestMetadataService::class), $sdk);
    }

    private function bedrock(): FixtureBedrockProvider {
        $this->app['region_bedrock'] = 'eu-central-1';
        $this->keys['bedrock'] = 'bedrock-key';
        $provider = $this->provider(FixtureBedrockProvider::class);
        $provider->responses = [
            'foundation-models' => self::fixture('foundation-models.json'),
            'inference-profiles?SYSTEM_DEFINED' => self::fixture('inference-profiles-system-1.json'),
            'inference-profiles?SYSTEM_DEFINED&page-2' => self::fixture('inference-profiles-system-2.json'),
            'inference-profiles?APPLICATION' => self::fixture('inference-profiles-application.json'),
        ];
        return $provider;
    }

    private function catalog(): ModelCatalog {
        $catalog = $this->bedrock()->fetchModelCatalog();
        $this->assertNotNull($catalog);
        $this->assertFalse($catalog->isFailure(), $catalog->error);
        return $catalog;
    }

    private static function apiError(int $status): APIStatusException {
        $body = (new \Nyholm\Psr7\Factory\Psr17Factory())->createStream('{"message":"denied"}');
        $response = (new \Nyholm\Psr7\Response($status, [], $body));
        return APIStatusException::from(request: new \Nyholm\Psr7\Request('GET', 'https://bedrock.eu-central-1.amazonaws.com/foundation-models'), response: $response);
    }

    // ── Bedrock control plane ──────────────────────────────────────────────

    public function testBedrockFollowsTheProfilePagesAndAsksForBothProfileTypes(): void {
        $provider = $this->bedrock();
        $provider->fetchModelCatalog();
        $this->assertSame([
            'foundation-models',
            'inference-profiles?SYSTEM_DEFINED',
            'inference-profiles?SYSTEM_DEFINED&page-2',
            'inference-profiles?APPLICATION',
        ], $provider->requested);
    }

    public function testOnDemandModelIsAChatModelWithVisionFromItsModalities(): void {
        $haiku = $this->catalog()->find('anthropic.claude-haiku-4-5-20251001-v1:0');
        $this->assertNotNull($haiku);
        $this->assertSame(ModelCompatibility::STATUS_OK, $haiku->status);
        $this->assertSame('Claude Haiku 4.5', $haiku->label);
        $this->assertTrue($haiku->vision);
    }

    public function testProfileOnlyModelPointsAtItsProfilesAndCannotBePickedBare(): void {
        $opus = $this->catalog()->find('anthropic.claude-opus-5-5');
        $this->assertNotNull($opus);
        $this->assertFalse($opus->isSelectable());
        $this->assertSame(
            'Claude Opus 5.5 (anthropic.claude-opus-5-5) can\'t be called by its model ID on Bedrock: it is served only through an inference profile. '
            . 'Use eu.anthropic.claude-opus-5-5, global.anthropic.claude-opus-5-5 or arn:aws:bedrock:eu-central-1:123456789012:application-inference-profile/a1b2c3d4e5f6 instead.',
            $opus->message,
        );
    }

    public function testInferenceProfilesAreOfferedWithTheirModelsCapabilities(): void {
        $catalog = $this->catalog();
        foreach (['eu.anthropic.claude-opus-5-5', 'global.anthropic.claude-opus-5-5', 'eu.anthropic.claude-haiku-4-5-20251001-v1:0'] as $id) {
            $profile = $catalog->find($id);
            $this->assertNotNull($profile, $id);
            $this->assertSame(ModelCompatibility::STATUS_OK, $profile->status, $id);
            $this->assertTrue($profile->vision, $id);
        }
        // An application profile is called by its ARN, not its short id.
        $this->assertNotNull($catalog->find('arn:aws:bedrock:eu-central-1:123456789012:application-inference-profile/a1b2c3d4e5f6'));
        $this->assertNull($catalog->find('a1b2c3d4e5f6'));
    }

    public function testLegacyModelIsDeprecatedWithItsEndOfLife(): void {
        $sonnet = $this->catalog()->assess('anthropic.claude-sonnet-4-5-20250929-v1:0');
        $this->assertNotNull($sonnet);
        $this->assertSame(ModelCompatibility::STATUS_DEPRECATED, $sonnet->status);
        $this->assertSame('Claude Sonnet 4.5 (anthropic.claude-sonnet-4-5-20250929-v1:0) is deprecated and will be retired on 2027-03-01.', $sonnet->message);
    }

    public function testModelPastEndOfLifeIsRetiredAndOutOfThePicker(): void {
        $catalog = $this->catalog();
        $this->assertNotContains('anthropic.claude-3-haiku-20240307-v1:0', $catalog->ids());
        $old = $catalog->assess('anthropic.claude-3-haiku-20240307-v1:0');
        $this->assertNotNull($old);
        $this->assertSame('Claude 3 Haiku (anthropic.claude-3-haiku-20240307-v1:0) was retired on 2026-04-20. Requests to it fail. Pick another model.', $old->message);
    }

    public function testModelThatCannotStreamGetsANote(): void {
        $text = $this->catalog()->find('anthropic.claude-text-only-v1');
        $this->assertNotNull($text);
        $this->assertTrue($text->isSelectable());
        $this->assertFalse($text->vision);
        $this->assertSame('Bedrock reports that anthropic.claude-text-only-v1 can\'t stream responses, so streamed chat with it fails.', $text->message);
    }

    public function testUnlistedIdIsMissingWithARegionHint(): void {
        $this->assertSame(
            'anthropic.claude-nope isn\'t in the model list from Claude in Amazon Bedrock. Check the model or inference profile ID, and that it is offered in this Region.',
            $this->catalog()->assess('anthropic.claude-nope')?->message,
        );
    }

    public function testProfilesThatCannotBeListedStillLeaveAPicker(): void {
        $provider = $this->bedrock();
        $provider->responses['inference-profiles?SYSTEM_DEFINED'] = self::apiError(403);
        $catalog = $provider->fetchModelCatalog();
        $this->assertNotNull($catalog);
        $this->assertFalse($catalog->isFailure());
        $this->assertStringEndsWith(
            'Its inference profiles could not be listed with these credentials (bedrock:ListInferenceProfiles); use the profile ID for your geography from the Bedrock console.',
            (string)$catalog->find('anthropic.claude-opus-5-5')?->message,
        );
    }

    public function testApiKeyWithoutListPermissionSaysSoOnTheCard(): void {
        $provider = $this->bedrock();
        $provider->responses['foundation-models'] = self::apiError(403);
        $catalog = $provider->fetchModelCatalog();
        $this->assertNotNull($catalog);
        $this->assertSame(
            'Couldn\'t load the model list from Claude in Amazon Bedrock: this API key is not allowed to list models. Listing needs bedrock:ListFoundationModels and bedrock:ListInferenceProfiles, which a key allowed only on the Mantle endpoint may not have; type the model ID instead. Showing the built-in list.',
            $catalog->failureMessage(),
        );
    }

    public function testControlPlaneUrlIsTheRegionalBedrockHost(): void {
        $this->assertSame('https://bedrock.eu-central-1.amazonaws.com', \OCA\AIquila\Service\Provider\Cloud\CloudEndpoints::bedrockControlPlaneUrl('eu-central-1'));
    }

    public function testCatalogBuilderIgnoresModelsWithoutTextOutput(): void {
        $catalog = BedrockModelCatalog::build([[
            'modelArn' => 'arn:aws:bedrock:eu-central-1::foundation-model/anthropic.embed',
            'modelId' => 'anthropic.embed',
            'outputModalities' => ['EMBEDDING'],
        ]], [], 'Bedrock', true, new \DateTimeImmutable('2026-11-15'));
        $this->assertSame([], $catalog->ids());
    }

    // ── Which cards get a picker ───────────────────────────────────────────

    /** @return array<string, array{class-string<AbstractCloudClaudeProvider>, string}> */
    public static function modelFieldTypes(): array {
        return [
            'bedrock lists through the control plane' => [BedrockProvider::class, ProviderSettingsSchema::TYPE_SELECT],
            'Claude Platform on AWS has the Models API' => [ClaudePlatformAwsProvider::class, ProviderSettingsSchema::TYPE_SELECT],
            'vertex has no per-project listing' => [VertexProvider::class, ProviderSettingsSchema::TYPE_TEXT],
            'foundry has no listing with a resource key' => [FoundryProvider::class, ProviderSettingsSchema::TYPE_TEXT],
        ];
    }

    /** @param class-string<AbstractCloudClaudeProvider> $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelFieldTypes')]
    public function testModelFieldIsAPickerOnlyWhereThereIsAList(string $class, string $type): void {
        $fields = $this->provider($class)->getSettingsSchema();
        $model = $fields[array_search('model', array_column($fields, 'id'), true)];
        $this->assertSame($type, $model['type']);
        $this->assertSame(ProviderSettingsSchema::FORMAT_MODEL_ID, $model['format']);
    }

    // ── Platforms without a listing ────────────────────────────────────────

    public function testVertexAndFoundryHaveNoCatalog(): void {
        $this->assertNull($this->provider(VertexProvider::class)->fetchModelCatalog());
        $this->assertNull($this->provider(FoundryProvider::class)->fetchModelCatalog());
    }

    public function testVertexSaysNothingAboutAKnownModel(): void {
        $this->assertNull($this->provider(VertexProvider::class)->assessUnlistedModel('claude-haiku-4-5@20251001'));
    }

    public function testVertexMarksAnUnknownModelNotVerified(): void {
        $verdict = $this->provider(VertexProvider::class)->assessUnlistedModel('claude-future-9');
        $this->assertNotNull($verdict);
        $this->assertSame(ModelCompatibility::STATUS_UNVERIFIED, $verdict->status);
        $this->assertSame(
            'claude-future-9 is not verified: Vertex AI has no list of the models enabled in a project, and AIquila does not know this model, so its limits, thinking and effort are not checked. Test the connection to confirm it answers.',
            $verdict->message,
        );
    }

    public function testFoundryAsksForTheBaseModelBehindACustomDeploymentName(): void {
        $verdict = $this->provider(FoundryProvider::class)->assessUnlistedModel('team-claude');
        $this->assertNotNull($verdict);
        $this->assertSame(
            'team-claude is not verified: the deployment name is not a Claude model ID, so AIquila cannot tell which model it serves and does not check thinking and effort against it. Set the base model below.',
            $verdict->message,
        );
        $this->app['base_model_foundry'] = 'claude-sonnet-5-5';
        $this->assertNull($this->provider(FoundryProvider::class)->assessUnlistedModel('team-claude'));
    }

    public function testNotFoundSentencesNameWhereToLook(): void {
        $this->app['project_vertex'] = 'my-project';
        $this->app['location_vertex'] = 'europe-west4';
        $this->app['resource_foundry'] = 'my-res';
        $vertex = new \ReflectionMethod(VertexProvider::class, 'modelNotFoundMessage');
        $foundry = new \ReflectionMethod(FoundryProvider::class, 'modelNotFoundMessage');
        $this->assertSame(
            'claude-opus-5-5 isn\'t available in project my-project at location europe-west4. Enable it from its Model Garden card, or pick a location that offers it, such as global.',
            $vertex->invoke($this->provider(VertexProvider::class), 'claude-opus-5-5'),
        );
        $this->assertSame(
            'Foundry has no deployment named team-claude on resource my-res. Check the name on the resource\'s Deployments page; it is the deployment name, not the model ID, when you chose another one.',
            $foundry->invoke($this->provider(FoundryProvider::class), 'team-claude'),
        );
    }
}
