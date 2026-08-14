<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One access rule for one provider.
 *
 * A rule is a single (provider, allow|block, user|group, principal) tuple; the
 * four lists an admin edits — allowed_users, allowed_groups, blocked_users,
 * blocked_groups — are just the rows of this table grouped by rule/principal
 * type. Storing one row per principal rather than a serialised list keeps
 * "which providers may this user use" answerable in SQL and avoids re-encoding
 * a whole list to add one name.
 *
 * @method string getProviderId()
 * @method void setProviderId(string $providerId)
 * @method string getRuleType()
 * @method void setRuleType(string $ruleType)
 * @method string getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string getPrincipalId()
 * @method void setPrincipalId(string $principalId)
 */
class ProviderAccessRule extends Entity {
    public const RULE_ALLOW = 'allow';
    public const RULE_BLOCK = 'block';
    public const PRINCIPAL_USER = 'user';
    public const PRINCIPAL_GROUP = 'group';

    // All four start empty on purpose. Entity's magic setter only marks a field
    // updated when the value actually changes, and QBMapper::insert() writes
    // only updated fields — so a property defaulted to 'allow' would be dropped
    // from the INSERT whenever the caller set it to 'allow', and the column
    // would go in as NULL.
    protected string $providerId = '';
    protected string $ruleType = '';
    protected string $principalType = '';
    protected string $principalId = '';

    public function __construct() {
        $this->addType('providerId', 'string');
        $this->addType('ruleType', 'string');
        $this->addType('principalType', 'string');
        $this->addType('principalId', 'string');
    }
}
