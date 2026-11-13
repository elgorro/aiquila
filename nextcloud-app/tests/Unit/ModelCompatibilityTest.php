<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\Provider\ModelCatalog;
use OCA\AIquila\Service\Provider\ModelCompatibility;
use PHPUnit\Framework\TestCase;

/**
 * The shared verdicts and sentences every provider's picker, card and chat use.
 * The tests assert the wording, not just the state: the wording is the feature.
 */
class ModelCompatibilityTest extends TestCase {
    public function testAToolCapableChatModelIsOkAndSilent(): void {
        $m = ModelCompatibility::chat('llama3.2', '', true, false);
        $this->assertSame(ModelCompatibility::STATUS_OK, $m->status);
        $this->assertSame('', $m->message);
        $this->assertTrue($m->isSelectable());
        $this->assertFalse($m->isWarning());
    }

    public function testAChatModelWithoutToolsWarnsButStaysSelectable(): void {
        $m = ModelCompatibility::chat('gemma:2b', '', false);
        $this->assertSame(ModelCompatibility::STATUS_NO_TOOLS, $m->status);
        $this->assertSame(
            'gemma:2b doesn\'t support tool calling. Plain chat works, but Nextcloud tools and Cowork need a model with tool support.',
            $m->message,
        );
        $this->assertTrue($m->isSelectable());
        $this->assertTrue($m->isWarning());
    }

    public function testUnknownToolSupportIsNotTreatedAsMissing(): void {
        $this->assertSame(ModelCompatibility::STATUS_OK, ModelCompatibility::chat('x', '', null)->status);
    }

    public function testANoteIsAppendedWithoutChangingTheVerdict(): void {
        $m = ModelCompatibility::chat('a', '', true, null, false, 'It isn\'t loaded.');
        $this->assertSame(ModelCompatibility::STATUS_OK, $m->status);
        $this->assertSame('It isn\'t loaded.', $m->message);
        $this->assertFalse($m->loaded);
    }

    public function testAnEmbeddingModelCannotBePicked(): void {
        $m = ModelCompatibility::embedding('mistral-embed', '', 'mistral-medium-latest');
        $this->assertSame(ModelCompatibility::STATUS_INCOMPATIBLE, $m->status);
        $this->assertSame(
            'mistral-embed only creates embeddings and can\'t chat. Pick a chat model, for example mistral-medium-latest.',
            $m->message,
        );
        $this->assertFalse($m->isSelectable());
    }

    public function testTheDisplayNameIsUsedWithTheId(): void {
        $m = ModelCompatibility::embedding('text-embedding-nomic-embed-text-v1.5', 'Nomic Embed Text v1.5');
        $this->assertSame(
            'Nomic Embed Text v1.5 (text-embedding-nomic-embed-text-v1.5) only creates embeddings and can\'t chat. Pick a chat model.',
            $m->message,
        );
    }

    public function testAModelThatCannotChatSaysWhatItIs(): void {
        $this->assertSame(
            'rerank-1 can\'t chat: it is a "decision" model. Pick a chat model.',
            ModelCompatibility::cannotChat('rerank-1', '', 'a "decision" model')->message,
        );
        $this->assertSame('x can\'t chat. Pick a chat model.', ModelCompatibility::cannotChat('x')->message);
    }

    public function testUnverifiedIsARemarkNotAWarning(): void {
        $m = ModelCompatibility::unverified('qwen', '', 'the backend reports no capabilities.');
        $this->assertSame('qwen is not verified: the backend reports no capabilities.', $m->message);
        $this->assertTrue($m->isSelectable());
        $this->assertFalse($m->isWarning());
    }

    public function testDeprecatedNamesTheDateAndTheReplacement(): void {
        $m = ModelCompatibility::deprecated('claude-sonnet-4', 'Claude Sonnet 4', '2026-12-01', 'Claude Sonnet 5.5');
        $this->assertSame(
            'Claude Sonnet 4 (claude-sonnet-4) is deprecated and will be retired on 2026-12-01. Switch to Claude Sonnet 5.5 before then.',
            $m->message,
        );
        $this->assertTrue($m->isSelectable());
        $this->assertTrue($m->isWarning());
    }

    public function testRetiredCannotBePicked(): void {
        $m = ModelCompatibility::retired('claude-sonnet-4', 'Claude Sonnet 4', '2026-06-01', 'Claude Sonnet 5.5');
        $this->assertSame(
            'Claude Sonnet 4 (claude-sonnet-4) was retired on 2026-06-01. Requests to it fail. Pick Claude Sonnet 5.5.',
            $m->message,
        );
        $this->assertFalse($m->isSelectable());
    }

    public function testMissingWarnsButAFreeTypedIdStaysSelectable(): void {
        $m = ModelCompatibility::missing('brand-new', 'Local model');
        $this->assertSame(
            'brand-new isn\'t in the model list from Local model. Check the model name, or pick one from the list.',
            $m->message,
        );
        $this->assertTrue($m->isSelectable());
        $this->assertTrue($m->isWarning());
    }

    public function testRuntimeSentences(): void {
        $this->assertSame(
            'gemma:2b can\'t use tools. Pick a model with tool support or turn tools off.',
            ModelCompatibility::toolsRefusedMessage('gemma:2b'),
        );
        $this->assertSame(
            'Couldn\'t load the model list from Local model: Nothing is listening. Showing the built-in list.',
            ModelCompatibility::listingFailedMessage('Local model', 'Nothing is listening'),
        );
    }

    public function testRoundTripsThroughTheCacheShape(): void {
        $m = ModelCompatibility::chat('a', 'A', false, true, false, 'note');
        $back = ModelCompatibility::fromArray($m->toArray());
        $this->assertEquals($m, $back);
        $this->assertNull(ModelCompatibility::fromArray(['id' => 'a']));
        $this->assertNull(ModelCompatibility::fromArray('garbage'));
    }

    // ── Catalog ─────────────────────────────────────────────────────────

    public function testCatalogAssessesListedAndMissingIds(): void {
        $catalog = ModelCatalog::listed(
            [ModelCompatibility::chat('b'), ModelCompatibility::embedding('a')],
            'test',
            'Local model',
            'Pull it with `ollama pull {model}`.',
        );
        $this->assertSame(['a', 'b'], $catalog->ids(), 'sorted by id');
        $this->assertSame(ModelCompatibility::STATUS_OK, $catalog->assess('b')?->status);
        $this->assertSame(
            'nope isn\'t in the model list from Local model. Pull it with `ollama pull nope`.',
            $catalog->assess('nope')?->message,
        );
        $this->assertNull($catalog->assess(''));
    }

    public function testAFailedCatalogKnowsNothingAboutAnyModel(): void {
        $catalog = ModelCatalog::failed('Local model', 'Nothing is listening at the local model endpoint.');
        $this->assertTrue($catalog->isFailure());
        $this->assertNull($catalog->assess('anything'));
        $this->assertSame(
            'Couldn\'t load the model list from Local model: Nothing is listening at the local model endpoint. Showing the built-in list.',
            $catalog->failureMessage(),
        );
    }

    public function testCatalogRoundTripsThroughTheCacheShape(): void {
        $catalog = ModelCatalog::listed([ModelCompatibility::chat('a', '', false)], 'ollama', 'Local model', 'hint');
        $this->assertEquals($catalog, ModelCatalog::fromArray($catalog->toArray()));
        $this->assertNull(ModelCatalog::fromArray(['source' => 'x']));
        $this->assertNull(ModelCatalog::fromArray(false), 'a cache miss is not a catalog');
    }
}
