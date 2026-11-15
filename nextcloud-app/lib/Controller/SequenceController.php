<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;
use OCA\AIquila\Service\Exception\SequencePolicyException;
use OCA\AIquila\Service\SequenceCatalog;
use OCA\AIquila\Service\SequencePolicy;
use OCA\AIquila\Service\SequenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\RequestHeader;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Sequences: named chains of MCP tool calls the AIquila MCP server runs in
 * one call, as the calling user.
 *
 * `/api/sequences` manages the caller's own sequences, lists the
 * organization's read-only, and switches built-ins on or off for the caller.
 * `/api/sequences/effective` is what the MCP server reads, with an ETag.
 * `/api/admin/sequences` and `/api/admin/sequence-policy` are the admin side;
 * the framework refuses them to non-admins.
 *
 * @psalm-import-type AIquilaSequence from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaSequenceBuiltin from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaSequencePolicy from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaEffectiveSequence from \OCA\AIquila\ResponseDefinitions
 */
class SequenceController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private SequenceMapper $mapper,
        private SequenceService $service,
        private SequencePolicy $policy,
        private SequenceCatalog $catalog,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the caller's sequences, the organization's and the built-ins
     *
     * 200: Organization sequences, then the caller's own, each by tool name; the built-ins with their state; the policy
     *
     * @return JSONResponse<Http::STATUS_OK, array{sequences: list<AIquilaSequence>, builtins: list<AIquilaSequenceBuiltin>, policy: AIquilaSequencePolicy}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $userId = $this->requireUserId();
        $rows = array_merge($this->mapper->findAllInstance(), $this->mapper->findAllByUser($userId));
        return new JSONResponse([
            'sequences' => array_map(static fn (Sequence $s): array => $s->jsonSerialize(), $rows),
            'builtins' => $this->service->builtinsFor($userId),
            'policy' => $this->policy->get(),
        ]);
    }

    /**
     * The tools a step may call and the built-in sequences, for the editor
     *
     * 200: The MCP server's sequence catalog
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function catalog(): JSONResponse {
        return new JSONResponse($this->catalog->data());
    }

    /**
     * The sequences the MCP server lists and runs for the caller
     *
     * Enabled organization sequences, the caller's own enabled ones (when the
     * policy allows them), the built-ins switched off, and the policy. Answers
     * with an `ETag`; a request whose `If-None-Match` matches it gets
     * `304 Not Modified` without a body (RFC 9110 §13.1.2, §15.4.5).
     *
     * 200: The effective sequences
     * 304: Unchanged since the entity tag in If-None-Match
     *
     * @return JSONResponse<Http::STATUS_OK, array{sequences: list<AIquilaEffectiveSequence>, disabledBuiltins: list<string>, policy: AIquilaSequencePolicy}, array{ETag?: string}>|Response<Http::STATUS_NOT_MODIFIED, array{ETag?: string}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    #[RequestHeader(name: 'if-none-match', description: 'Entity tag of the copy the caller holds (RFC 9110 §13.1.2)')]
    public function effective(): JSONResponse|Response {
        $body = $this->service->effective($this->requireUserId());
        $etag = SequenceService::etag($body);
        if (SequenceService::noneMatch($this->request->getHeader('if-none-match'), $etag)) {
            /** @var Response<Http::STATUS_NOT_MODIFIED, array{ETag?: string}> $response */
            $response = new Response(Http::STATUS_NOT_MODIFIED);
            $response->setETag($etag);
            return $response;
        }
        $response = new JSONResponse($body);
        $response->setETag($etag);
        return $response;
    }

    /**
     * Create a personal sequence
     *
     * @param string $slug Tool name part: the sequence is `seq_my_<slug>`
     * @param string $title Title
     * @param array<string, mixed> $definition `{inputs, steps}`
     * @param string|null $description What the model reads about it
     * @param bool $pinned Also list it as its own tool
     * @param bool $enabled List and run it at all
     *
     * 200: The created sequence
     * 400: A field is invalid, or a limit is reached
     * 403: Personal sequences are switched off
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaSequence, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(string $slug, string $title, array $definition, ?string $description = null, bool $pinned = false, bool $enabled = true): JSONResponse {
        return $this->save(fn (): Sequence => $this->service->create(Sequence::SCOPE_USER, $this->requireUserId(), [
            'slug' => $slug, 'title' => $title, 'definition' => $definition,
            'description' => $description, 'pinned' => $pinned, 'enabled' => $enabled,
        ]));
    }

    /**
     * Update a personal sequence
     *
     * Omitted fields stay as they are.
     *
     * @param int $id Sequence ID
     * @param string|null $slug New tool name part
     * @param string|null $title New title
     * @param array<string, mixed>|null $definition New `{inputs, steps}`
     * @param string|null $description New description ('' clears it)
     * @param bool|null $pinned Also list it as its own tool
     * @param bool|null $enabled List and run it at all
     *
     * 200: The updated sequence
     * 400: A field is invalid, or a limit is reached
     * 403: Personal sequences are switched off
     * 404: Sequence not found
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaSequence, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, ?string $slug = null, ?string $title = null, ?array $definition = null, ?string $description = null, ?bool $pinned = null, ?bool $enabled = null): JSONResponse {
        try {
            $sequence = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Sequence not found');
        }
        return $this->save(fn (): Sequence => $this->service->update($sequence, [
            'slug' => $slug, 'title' => $title, 'definition' => $definition,
            'description' => $description, 'pinned' => $pinned, 'enabled' => $enabled,
        ]));
    }

    /**
     * Delete a personal sequence
     *
     * @param int $id Sequence ID
     *
     * 200: Deleted
     * 404: Sequence not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $sequence = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Sequence not found');
        }
        $this->mapper->delete($sequence);
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Switch a built-in sequence on or off for the caller
     *
     * @param string $slug Built-in sequence
     * @param bool $enabled On or off
     *
     * 200: The built-ins with their state
     * 400: Not a built-in sequence
     *
     * @return JSONResponse<Http::STATUS_OK, list<AIquilaSequenceBuiltin>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function setBuiltin(string $slug, bool $enabled): JSONResponse {
        $userId = $this->requireUserId();
        try {
            $this->policy->setUserBuiltinEnabled($userId, $slug, $enabled);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, $e->getMessage());
        }
        return new JSONResponse($this->service->builtinsFor($userId));
    }

    /**
     * List the organization's sequences, the built-ins and the policy
     *
     * 200: Organization sequences by tool name, the built-ins with their instance state, the policy
     *
     * @return JSONResponse<Http::STATUS_OK, array{sequences: list<AIquilaSequence>, builtins: list<AIquilaSequenceBuiltin>, policy: AIquilaSequencePolicy}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminIndex(): JSONResponse {
        return new JSONResponse([
            'sequences' => array_map(static fn (Sequence $s): array => $s->jsonSerialize(), $this->mapper->findAllInstance()),
            'builtins' => $this->service->builtinsFor(null),
            'policy' => $this->policy->get(),
        ]);
    }

    /**
     * Publish a sequence to every user
     *
     * @param string $slug Tool name part: the sequence is `seq_<slug>`
     * @param string $title Title
     * @param array<string, mixed> $definition `{inputs, steps}`
     * @param string|null $description What the model reads about it
     * @param bool $pinned Also list it as its own tool
     * @param bool $enabled List and run it at all
     *
     * 200: The created sequence
     * 400: A field is invalid
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaSequence, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminCreate(string $slug, string $title, array $definition, ?string $description = null, bool $pinned = false, bool $enabled = true): JSONResponse {
        return $this->save(fn (): Sequence => $this->service->create(Sequence::SCOPE_INSTANCE, $this->requireUserId(), [
            'slug' => $slug, 'title' => $title, 'definition' => $definition,
            'description' => $description, 'pinned' => $pinned, 'enabled' => $enabled,
        ]));
    }

    /**
     * Update an organization sequence
     *
     * @param int $id Sequence ID
     * @param string|null $slug New tool name part
     * @param string|null $title New title
     * @param array<string, mixed>|null $definition New `{inputs, steps}`
     * @param string|null $description New description ('' clears it)
     * @param bool|null $pinned Also list it as its own tool
     * @param bool|null $enabled List and run it at all
     *
     * 200: The updated sequence
     * 400: A field is invalid
     * 404: Sequence not found
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaSequence, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminUpdate(int $id, ?string $slug = null, ?string $title = null, ?array $definition = null, ?string $description = null, ?bool $pinned = null, ?bool $enabled = null): JSONResponse {
        try {
            $sequence = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Sequence not found');
        }
        return $this->save(fn (): Sequence => $this->service->update($sequence, [
            'slug' => $slug, 'title' => $title, 'definition' => $definition,
            'description' => $description, 'pinned' => $pinned, 'enabled' => $enabled,
        ]));
    }

    /**
     * Delete an organization sequence
     *
     * @param int $id Sequence ID
     *
     * 200: Deleted
     * 404: Sequence not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminDestroy(int $id): JSONResponse {
        try {
            $sequence = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Sequence not found');
        }
        $this->mapper->delete($sequence);
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Set the sequence policy and switch built-ins on or off for everyone
     *
     * Omitted fields stay as they are.
     *
     * @param bool|null $allowPersonal Users may write their own sequences
     * @param int|null $maxPerUser Sequences per user
     * @param int|null $maxSteps Steps per sequence
     * @param int|null $maxPinned Personal sequences per user shown as their own tool
     * @param array<string, bool>|null $builtins Built-in slug → on or off
     *
     * 200: The policy and the built-ins
     * 400: A value is out of range, or a slug is not a built-in
     *
     * @return JSONResponse<Http::STATUS_OK, array{policy: AIquilaSequencePolicy, builtins: list<AIquilaSequenceBuiltin>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminPolicy(?bool $allowPersonal = null, ?int $maxPerUser = null, ?int $maxSteps = null, ?int $maxPinned = null, ?array $builtins = null): JSONResponse {
        try {
            foreach (array_keys($builtins ?? []) as $slug) {
                if (!in_array((string)$slug, $this->catalog->builtinSlugs(), true)) {
                    throw new \InvalidArgumentException('Unknown built-in sequence');
                }
            }
            $this->policy->set($allowPersonal, $maxPerUser, $maxSteps, $maxPinned);
            foreach ($builtins ?? [] as $slug => $on) {
                $this->policy->setBuiltinEnabled((string)$slug, (bool)$on);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, $e->getMessage());
        }
        return new JSONResponse(['policy' => $this->policy->get(), 'builtins' => $this->service->builtinsFor(null)]);
    }

    /**
     * @param callable(): Sequence $write
     * @return JSONResponse<Http::STATUS_OK, AIquilaSequence, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    private function save(callable $write): JSONResponse {
        try {
            return new JSONResponse($write()->jsonSerialize());
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, $e->getMessage());
        } catch (SequencePolicyException $e) {
            return $this->clientError(403, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500, 'Could not save the sequence', 'SequenceController::save');
        }
    }
}
