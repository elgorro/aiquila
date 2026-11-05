<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\Circles\CirclesManager;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Model\Member;
use OCP\IUserManager;
use OCP\Teams\ITeamManager;
use Psr\Container\ContainerInterface;

/**
 * Who belongs to a team, for coworkers and projects a team owns.
 *
 * Circles has no OCP interface for a team's owner and admins, so those come
 * from its manager, resolved at run time because the app may be disabled.
 */
class TeamService {
    public function __construct(
        private readonly ITeamManager $teams,
        private readonly IUserManager $users,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * The teams the user belongs to, directly or through a group.
     *
     * @return list<string>
     */
    public function teamIdsFor(string $userId): array {
        if (!$this->teams->hasTeamSupport()) {
            return [];
        }
        return array_map(
            static fn ($team): string => $team->getId(),
            $this->teams->getTeamsForUser($userId),
        );
    }

    /** Whether the account exists, is enabled and still belongs to the team. */
    public function canActFor(string $teamId, string $userId): bool {
        return $this->users->get($userId)?->isEnabled() === true
            && in_array($teamId, $this->teamIdsFor($userId), true);
    }

    /**
     * The team's owner and then its admins, as local accounts that can run
     * something for it.
     *
     * @return list<string>|null null when the team no longer exists
     * @throws \Throwable when that cannot be told, e.g. Circles is disabled.
     *                    Callers must not take this as the team being gone.
     */
    public function administrators(string $teamId): ?array {
        /** @var CirclesManager $circles */
        $circles = $this->container->get(CirclesManager::class);
        $circles->startSuperSession();
        try {
            $circle = $circles->getCircle($teamId);
            $candidates = [$circle->getOwner()];
            $members = $circle->getMembers();
            usort($members, static fn (Member $a, Member $b): int => $b->getLevel() <=> $a->getLevel());
            foreach ($members as $member) {
                if ($member->getLevel() >= Member::LEVEL_ADMIN) {
                    $candidates[] = $member;
                }
            }
        } catch (CircleNotFoundException) {
            return null;
        } finally {
            $circles->stopSession();
        }

        $accounts = [];
        foreach ($candidates as $member) {
            if ($member->getUserType() === Member::TYPE_USER && $this->canActFor($teamId, $member->getUserId())) {
                $accounts[] = $member->getUserId();
            }
        }
        return array_values(array_unique($accounts));
    }
}
