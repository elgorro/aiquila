<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Http\DownloadStreamResponse;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCA\AIquila\Service\UsageExport\UsageExportWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamTraversableResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Downloads of the token usage in `aiquila_usage_stats`, as CSV or JSON.
 *
 * `/api/usage/export` is the caller's own usage and takes no user: there is
 * no parameter through which a user could name anyone else.
 * `/api/admin/usage/export` covers every user, or one user or group; the
 * framework refuses it to non-admins with 403.
 *
 * The body streams (DownloadStreamResponse), so a year of per-request rows
 * never sits in memory. Parameters are checked before the first byte; once
 * streaming starts the status is 200.
 */
class UsageExportController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private UsageExportService $export,
        private ITimeFactory $time,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Export the caller's own token usage
     *
     * One row per request, or sums per day, ISO week (Monday start) or month,
     * all in UTC. Rows are oldest first.
     *
     * @param 'csv'|'json' $format `csv` (RFC 4180, header row) or `json`
     * @param 'request'|'day'|'week'|'month' $granularity One row per request, or sums per UTC day, ISO week or month
     * @param string|null $from Start, inclusive: ISO 8601 date (UTC day) or date-time with offset
     * @param string|null $to End, exclusive; a bare date includes that whole day
     * @param string|null $model Only this model id
     * @param string|null $provider Only this provider id (rows from before providers were recorded have none)
     * @param 'chat'|'api'|'task'|'coworker'|'talk'|'flow'|null $requestType Only this request type
     *
     * 200: The export, streamed as a file download
     * 400: A parameter is invalid
     *
     * @return StreamTraversableResponse<Http::STATUS_OK, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    #[OpenAPI]
    public function export(
        string $format = 'csv',
        string $granularity = 'request',
        ?string $from = null,
        ?string $to = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $requestType = null,
    ): StreamTraversableResponse|JSONResponse {
        try {
            $filter = UsageExportFilter::fromInput($format, $granularity, $from, $to, $model, $provider, $requestType);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $e->getMessage());
        }

        return $this->download($filter, [$this->requireUserId()], false);
    }

    /**
     * Export the token usage of every user, one user or one group
     *
     * Same rows as the personal export, with a `user` column. Talk guests
     * are recorded under an empty user.
     *
     * @param 'csv'|'json' $format `csv` (RFC 4180, header row) or `json`
     * @param 'request'|'day'|'week'|'month' $granularity One row per request, or sums per UTC day, ISO week or month
     * @param string|null $from Start, inclusive: ISO 8601 date (UTC day) or date-time with offset
     * @param string|null $to End, exclusive; a bare date includes that whole day
     * @param string|null $model Only this model id
     * @param string|null $provider Only this provider id
     * @param 'chat'|'api'|'task'|'coworker'|'talk'|'flow'|null $requestType Only this request type
     * @param string|null $user Only this user id
     * @param string|null $group Only the members of this group
     *
     * 200: The export, streamed as a file download
     * 400: A parameter is invalid, or both user and group were given
     * 404: The group does not exist
     *
     * @return StreamTraversableResponse<Http::STATUS_OK, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[UserRateLimit(limit: 10, period: 60)]
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminExport(
        string $format = 'csv',
        string $granularity = 'request',
        ?string $from = null,
        ?string $to = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $requestType = null,
        ?string $user = null,
        ?string $group = null,
    ): StreamTraversableResponse|JSONResponse {
        try {
            $filter = UsageExportFilter::fromInput($format, $granularity, $from, $to, $model, $provider, $requestType);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $e->getMessage());
        }

        $user = $user === null || $user === '' ? null : $user;
        $group = $group === null || $group === '' ? null : $group;
        if ($user !== null && $group !== null) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, 'Filter by user or by group, not both');
        }

        if ($group !== null) {
            $userIds = $this->export->groupUserIds($group);
            if ($userIds === null) {
                return $this->clientError(Http::STATUS_NOT_FOUND, 'Group not found');
            }
        } elseif ($user !== null) {
            $userIds = [$user];
        } else {
            $userIds = $this->export->allUserIds($filter);
        }

        return $this->download($filter, $userIds, true);
    }

    /**
     * @param list<string> $userIds
     * @return StreamTraversableResponse<Http::STATUS_OK, array{}>
     */
    private function download(UsageExportFilter $filter, array $userIds, bool $includeUser): StreamTraversableResponse {
        $now = $this->time->getTime();
        /** @var StreamTraversableResponse<Http::STATUS_OK, array{}> */
        return new DownloadStreamResponse(
            $this->export->stream($filter, $userIds, $includeUser, $now),
            UsageExportWriter::contentType($filter->format),
            UsageExportService::filename($filter, $now),
        );
    }
}
