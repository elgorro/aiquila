<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Talk;

use OCA\AIquila\BackgroundJob\TalkBotReplyJob;
use OCA\AIquila\Db\TalkMessage;
use OCA\AIquila\Db\TalkMessageMapper;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The AIquila bot in Nextcloud Talk.
 *
 * Installed as a Talk app bot (`nextcloudapp://aiquila`): Talk dispatches a
 * BotInvokeEvent in-process for every message in conversations where a
 * moderator enabled the bot, instead of calling a webhook. Each message goes
 * into a short per-conversation buffer, because Talk gives a bot no way to
 * read history. When a message addresses the bot, it reacts with 👀 straight
 * away and queues TalkBotReplyJob: the model call must not hold up the request
 * that sent the message. The job answers through Talk's bot API, signed with
 * the bot's secret.
 *
 * @see https://github.com/nextcloud/spreed/blob/main/docs/bots.md
 */
class TalkBotService {
    public const URL = 'nextcloudapp://aiquila';
    public const NAME = 'AIquila';
    /** Bot::FEATURE_RESPONSE (2) | Bot::FEATURE_EVENT (4). */
    public const FEATURES = 6;
    /** Messages kept per conversation, and so the context a reply sees. */
    public const KEEP_MESSAGES = 30;
    /** Talk's chat limit. */
    public const MAX_MESSAGE_LENGTH = 32000;

    private const SECRET_NAME = 'talk_bot_secret';
    /** App config: base URL this server reaches itself on, when the public one does not work from inside. */
    public const CONFIG_BASE_URL = 'talk_bot_base_url';
    /** `@AIquila` as a word, anywhere in the message. */
    private const ADDRESS_PATTERN = '/(^|\s)@aiquila\b/i';

    public function __construct(
        private CredentialService $credentials,
        private TalkMessageMapper $messages,
        private IEventDispatcher $dispatcher,
        private IJobList $jobList,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
        private ITimeFactory $time,
        private IAppManager $appManager,
        private AppSettings $config,
        private LoggerInterface $logger,
    ) {
    }

    public function isTalkAvailable(): bool {
        return $this->appManager->isEnabledForAnyone('spreed') && class_exists(BotInstallEvent::class);
    }

    public function isInstalled(): bool {
        return $this->credentials->hasSecret(self::SECRET_NAME);
    }

    /**
     * Register the bot with Talk. Idempotent: an existing secret is reused,
     * which is how Talk recognises the same bot.
     *
     * @throws \RuntimeException when Talk is not available
     */
    public function install(): void {
        if (!$this->isTalkAvailable()) {
            throw new \RuntimeException('Nextcloud Talk is not enabled.');
        }
        $secret = $this->credentials->getSecret(self::SECRET_NAME);
        if ($secret === '') {
            $secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
            $this->credentials->setSecret(self::SECRET_NAME, $secret);
        }
        $this->dispatcher->dispatchTyped(new BotInstallEvent(
            self::NAME,
            $secret,
            self::URL,
            'Answers when you write @AIquila, using the last messages of the conversation as context.',
            self::FEATURES,
        ));
    }

    /** Remove the bot from Talk and forget every buffered message. */
    public function uninstall(): void {
        $secret = $this->credentials->getSecret(self::SECRET_NAME);
        if ($secret !== '' && $this->isTalkAvailable()) {
            $this->dispatcher->dispatchTyped(new BotUninstallEvent($secret, self::URL));
        }
        $this->credentials->deleteSecret(self::SECRET_NAME);
        $this->messages->deleteAll();
    }

    /** Handle one message Talk delivered to the bot. */
    public function handleInvoke(BotInvokeEvent $event): void {
        if ($event->getBotUrl() !== self::URL) {
            return;
        }
        $payload = $event->getMessage();
        if (($payload['type'] ?? null) !== 'Create') {
            return;
        }

        $token = (string)($payload['target']['id'] ?? '');
        $messageId = (int)($payload['object']['id'] ?? 0);
        $text = self::renderMessage((string)($payload['object']['content'] ?? ''));
        if ($token === '' || $messageId === 0 || $text === '') {
            return;
        }
        $actorId = (string)($payload['actor']['id'] ?? '');

        $this->remember($token, $messageId, (string)($payload['actor']['name'] ?? ''), $text, false);

        if (!self::isAddressed($text, $payload)) {
            return;
        }

        $event->addReaction('👀');
        $this->jobList->add(TalkBotReplyJob::class, [
            'token' => $token,
            'messageId' => $messageId,
            // users/<uid> → the asking user's provider; guests get the instance default.
            'userId' => str_starts_with($actorId, 'users/') ? substr($actorId, 6) : null,
            'roomName' => (string)($payload['target']['name'] ?? ''),
        ]);
    }

    /**
     * Whether a message is for the bot: it says `@AIquila`, or it replies to
     * one of the bot's own messages.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function isAddressed(string $text, array $payload): bool {
        if (preg_match(self::ADDRESS_PATTERN, $text) === 1) {
            return true;
        }
        $parentActor = $payload['object']['inReplyTo']['actor']['id'] ?? '';
        return is_string($parentActor) && str_starts_with($parentActor, 'bots/');
    }

    /**
     * The plain text of a Talk message: the `message` of the JSON content with
     * its `{placeholder}` parameters (mentions, files, …) replaced by their
     * names.
     */
    public static function renderMessage(string $content): string {
        $data = json_decode($content, true);
        if (!is_array($data) || !is_string($data['message'] ?? null)) {
            return '';
        }
        $replacements = [];
        foreach ((array)($data['parameters'] ?? []) as $key => $parameter) {
            if (is_array($parameter) && is_string($parameter['name'] ?? null)) {
                $prefix = ($parameter['type'] ?? '') === 'user' || ($parameter['type'] ?? '') === 'guest' ? '@' : '';
                $replacements['{' . $key . '}'] = $prefix . $parameter['name'];
            }
        }
        return trim(strtr($data['message'], $replacements));
    }

    /** @return list<TalkMessage> */
    public function recentMessages(string $token): array {
        return $this->messages->findRecent($token, self::KEEP_MESSAGES);
    }

    public function remember(string $token, int $messageId, string $actorName, string $text, bool $fromBot): void {
        try {
            $row = new TalkMessage();
            $row->setRoomToken($token);
            $row->setMessageId($messageId);
            $row->setActorName($actorName);
            $row->setFromBot($fromBot);
            $row->setContent(mb_substr($text, 0, self::MAX_MESSAGE_LENGTH));
            $row->setCreatedAt($this->time->getTime());
            $this->messages->insert($row);
            $this->messages->prune($token, self::KEEP_MESSAGES);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Talk bot: could not store a message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Post $message to the conversation as the bot, replying to $replyTo.
     *
     * Goes through Talk's bot API on this server, signed as Talk expects:
     * HMAC-SHA256 over the random header followed by the message text. The
     * server is reached on its `overwrite.cli.url`, or on the base URL set with
     * `occ aiquila:talk-bot install --base-url` where that address does not
     * work from the server itself (a reverse proxy, a container port mapping).
     *
     * @throws \RuntimeException when Talk does not accept the message
     */
    public function postReply(string $token, string $message, int $replyTo): void {
        $secret = $this->credentials->getSecret(self::SECRET_NAME);
        if ($secret === '') {
            throw new \RuntimeException('The Talk bot is not installed.');
        }
        $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
        $random = bin2hex(random_bytes(32));

        $path = '/ocs/v2.php/apps/spreed/api/v1/bot/' . rawurlencode($token) . '/message';
        $base = rtrim($this->config->getAppValue('aiquila', self::CONFIG_BASE_URL, ''), '/');
        $response = $this->clientService->newClient()->post(
            $base !== '' ? $base . $path : $this->urlGenerator->getAbsoluteURL($path),
            [
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Nextcloud-Talk-Bot-Random' => $random,
                    'X-Nextcloud-Talk-Bot-Signature' => hash_hmac('sha256', $random . $message, $secret),
                ],
                'body' => json_encode(['message' => $message, 'replyTo' => $replyTo]),
                // The request goes to this server, which may sit on a private address.
                'nextcloud' => ['allow_local_address' => true],
                'http_errors' => false,
                'timeout' => 30,
            ],
        );

        $status = $response->getStatusCode();
        if ($status !== 201) {
            throw new \RuntimeException('Talk refused the bot reply with HTTP ' . $status);
        }
    }
}
