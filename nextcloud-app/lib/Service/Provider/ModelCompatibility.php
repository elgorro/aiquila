<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Whether one model can serve AIquila, and the sentence that says so.
 *
 * "Compatible" means text chat in and text out through the provider's chat API.
 * Tool calling is needed for MCP tools and Cowork, image input for vision tasks;
 * a model without tool calling still chats, with a warning. The verdict comes
 * from the provider's own listing metadata, never from guessing at the id.
 *
 * Every user-facing wording lives here, so every provider says the same thing
 * about the same situation — in the picker, on the provider card, in the chat,
 * and when a request fails at runtime because of the model. Messages name the
 * model, give the reason and say what to do.
 *
 * Instances are flattened with toArray() for the distributed cache and the
 * settings API, and rebuilt with fromArray().
 */
final class ModelCompatibility {
    /** Chats, calls tools. Nothing to say. */
    public const STATUS_OK = 'ok';
    /** Chats, but tool calling is off or unreliable. Selectable, with a warning. */
    public const STATUS_NO_TOOLS = 'no_tools';
    /** Cannot chat at all (embeddings, rerankers, …). Not selectable. */
    public const STATUS_INCOMPATIBLE = 'incompatible';
    /** Listed, but the provider gives no capability data to check it against. */
    public const STATUS_UNVERIFIED = 'unverified';
    /** Still served, but scheduled to go away. */
    public const STATUS_DEPRECATED = 'deprecated';
    /** No longer served; requests to it fail. Not selectable. */
    public const STATUS_RETIRED = 'retired';
    /** Not in the provider's list at all — typed in, or removed upstream. */
    public const STATUS_MISSING = 'missing';

    /** Statuses the picker refuses to offer. */
    private const UNSELECTABLE = [self::STATUS_INCOMPATIBLE, self::STATUS_RETIRED];

    /** Statuses that are a problem rather than a remark — shown as a warning. */
    private const WARNING = [
        self::STATUS_NO_TOOLS,
        self::STATUS_INCOMPATIBLE,
        self::STATUS_DEPRECATED,
        self::STATUS_RETIRED,
        self::STATUS_MISSING,
    ];

    /**
     * @param bool|null $tools  null when the metadata does not say
     * @param bool|null $vision null when the metadata does not say
     * @param bool|null $loaded null for providers without a loaded state
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly ?bool $tools = null,
        public readonly ?bool $vision = null,
        public readonly ?bool $loaded = null,
    ) {
    }

    // ── Verdicts ────────────────────────────────────────────────────────────

    /**
     * A chat model. Tool support decides between ok and no_tools; $note is
     * appended for provider-specific circumstances (a model that is not
     * loaded yet, say) that do not change whether it can be picked.
     *
     * @param string $noToolsMessage replaces the default no-tools sentence when
     *                               the provider knows more (e.g. LM Studio
     *                               still attempts tool calls with a generic
     *                               format)
     */
    public static function chat(
        string $id,
        string $label = '',
        ?bool $tools = true,
        ?bool $vision = null,
        ?bool $loaded = null,
        string $note = '',
        string $noToolsMessage = '',
    ): self {
        if ($tools === false) {
            $message = $noToolsMessage !== '' ? $noToolsMessage : self::noToolsMessage(self::name($id, $label));
            return new self($id, $label, self::STATUS_NO_TOOLS, self::join($message, $note), false, $vision, $loaded);
        }
        return new self($id, $label, self::STATUS_OK, $note, $tools, $vision, $loaded);
    }

    /** An embedding model: it turns text into vectors and cannot answer. */
    public static function embedding(string $id, string $label = '', string $suggestion = '', ?bool $loaded = null): self {
        return new self($id, $label, self::STATUS_INCOMPATIBLE, self::embeddingMessage(self::name($id, $label), $suggestion), false, false, $loaded);
    }

    /**
     * Any other model that cannot chat. $kind names what it is instead, in
     * the provider's words ("a reranking model").
     */
    public static function cannotChat(string $id, string $label = '', string $kind = '', string $suggestion = ''): self {
        return new self($id, $label, self::STATUS_INCOMPATIBLE, self::cannotChatMessage(self::name($id, $label), $kind, $suggestion), false, false);
    }

    /**
     * Listed by a backend that reports no capabilities. $why says which
     * backend and why it cannot tell.
     */
    public static function unverified(string $id, string $label = '', string $why = ''): self {
        $name = self::name($id, $label);
        $message = $why !== ''
            ? sprintf('%s is not verified: %s', $name, $why)
            : sprintf('%s is not verified: the provider does not report what its models can do, so AIquila cannot check that it supports chat and tool calling.', $name);
        return new self($id, $label, self::STATUS_UNVERIFIED, $message);
    }

    /** Still served until $date (Y-m-d, or '' when unannounced). */
    public static function deprecated(string $id, string $label = '', string $date = '', string $replacement = ''): self {
        $name = self::name($id, $label);
        $message = $date !== ''
            ? sprintf('%s is deprecated and will be retired on %s.', $name, $date)
            : sprintf('%s is deprecated and will be retired.', $name);
        if ($replacement !== '') {
            $message .= sprintf(' Switch to %s before then.', $replacement);
        }
        return new self($id, $label, self::STATUS_DEPRECATED, $message);
    }

    /** No longer served since $date (Y-m-d, or '' when unknown). */
    public static function retired(string $id, string $label = '', string $date = '', string $replacement = ''): self {
        $name = self::name($id, $label);
        $message = ($date !== ''
            ? sprintf('%s was retired on %s. Requests to it fail.', $name, $date)
            : sprintf('%s has been retired. Requests to it fail.', $name))
            . ($replacement !== '' ? sprintf(' Pick %s.', $replacement) : ' Pick another model.');
        return new self($id, $label, self::STATUS_RETIRED, $message, false, false);
    }

    /**
     * Not in the provider's list. Free-typed ids stay allowed — a model often
     * ships before the list catches up — so this warns rather than refuses.
     * $hint says how to make it appear (pull it, download it, check spelling).
     */
    public static function missing(string $id, string $providerLabel, string $hint = ''): self {
        return new self($id, '', self::STATUS_MISSING, self::missingMessage($id, $providerLabel, $hint));
    }

    // ── Shared sentences ────────────────────────────────────────────────────
    //
    // Public so runtime error mapping (a provider's errorMessage()) renders a
    // failed request with exactly the sentence the picker would have shown.

    public static function noToolsMessage(string $model): string {
        return sprintf('%s doesn\'t support tool calling. Plain chat works, but Nextcloud tools and Cowork need a model with tool support.', $model);
    }

    /** A request that sent tools was refused because the model has none. */
    public static function toolsRefusedMessage(string $model): string {
        return sprintf('%s can\'t use tools. Pick a model with tool support or turn tools off.', $model);
    }

    public static function embeddingMessage(string $model, string $suggestion = ''): string {
        return sprintf('%s only creates embeddings and can\'t chat. Pick a chat model', $model)
            . ($suggestion !== '' ? sprintf(', for example %s.', $suggestion) : '.');
    }

    public static function cannotChatMessage(string $model, string $kind = '', string $suggestion = ''): string {
        return ($kind !== ''
            ? sprintf('%s can\'t chat: it is %s. Pick a chat model', $model, $kind)
            : sprintf('%s can\'t chat. Pick a chat model', $model))
            . ($suggestion !== '' ? sprintf(', for example %s.', $suggestion) : '.');
    }

    public static function missingMessage(string $model, string $providerLabel, string $hint = ''): string {
        return self::join(sprintf('%s isn\'t in the model list from %s.', $model, $providerLabel), $hint !== '' ? $hint : 'Check the model name, or pick one from the list.');
    }

    /** Shown on the card when the live list could not be fetched. */
    public static function listingFailedMessage(string $providerLabel, string $reason): string {
        return sprintf('Couldn\'t load the model list from %s: %s Showing the built-in list.', $providerLabel, self::sentence($reason));
    }

    // ── Accessors ───────────────────────────────────────────────────────────

    /** Whether the picker may offer it. Missing and unverified ids stay pickable. */
    public function isSelectable(): bool {
        return !in_array($this->status, self::UNSELECTABLE, true);
    }

    /** A problem worth a warning, as opposed to a remark (unverified) or nothing. */
    public function isWarning(): bool {
        return in_array($this->status, self::WARNING, true);
    }

    /** The same verdict with a further sentence appended to its message. */
    public function withNote(string $note): self {
        return new self($this->id, $this->label, $this->status, self::join($this->message, $note), $this->tools, $this->vision, $this->loaded);
    }

    /**
     * @return array{id: string, label: string, status: string, message: string, selectable: bool, tools: bool|null, vision: bool|null, loaded: bool|null}
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'message' => $this->message,
            'selectable' => $this->isSelectable(),
            'tools' => $this->tools,
            'vision' => $this->vision,
            'loaded' => $this->loaded,
        ];
    }

    /**
     * Rebuild from toArray() output, as read back from the cache. Anything
     * malformed yields null so a stale or foreign cache entry is refetched
     * rather than trusted.
     *
     * @param mixed $data
     */
    public static function fromArray($data): ?self {
        if (!is_array($data)
            || !is_string($data['id'] ?? null)
            || !is_string($data['status'] ?? null)
            || !is_string($data['message'] ?? null)
        ) {
            return null;
        }
        $bool = static fn($v): ?bool => is_bool($v) ? $v : null;
        return new self(
            $data['id'],
            is_string($data['label'] ?? null) ? $data['label'] : '',
            $data['status'],
            $data['message'],
            $bool($data['tools'] ?? null),
            $bool($data['vision'] ?? null),
            $bool($data['loaded'] ?? null),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The name a message uses: the display name when there is one, else the id. */
    private static function name(string $id, string $label): string {
        return $label !== '' && $label !== $id ? $label . ' (' . $id . ')' : $id;
    }

    private static function join(string $first, string $second): string {
        if ($first === '') {
            return $second;
        }
        return $second === '' ? $first : $first . ' ' . $second;
    }

    /** Ensure a reason ends with sentence punctuation before more text follows it. */
    private static function sentence(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return 'no reason given.';
        }
        return preg_match('/[.!?]$/', $text) === 1 ? $text : $text . '.';
    }
}
