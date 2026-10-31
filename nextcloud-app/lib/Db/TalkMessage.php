<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A recent message in a Talk conversation the AIquila bot is enabled in.
 *
 * @method string getRoomToken()
 * @method void setRoomToken(string $roomToken)
 * @method int getMessageId()
 * @method void setMessageId(int $messageId)
 * @method string getActorName()
 * @method void setActorName(string $actorName)
 * @method bool|null getFromBot()
 * @method void setFromBot(bool $fromBot)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class TalkMessage extends Entity {
    use InsertsFullRow;

    protected string $roomToken = '';
    protected int $messageId = 0;
    protected string $actorName = '';
    protected ?bool $fromBot = false;
    protected string $content = '';
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('roomToken', 'string');
        $this->addType('messageId', 'integer');
        $this->addType('actorName', 'string');
        $this->addType('fromBot', 'boolean');
        $this->addType('content', 'string');
        $this->addType('createdAt', 'integer');
        $this->markFieldsForInsert();
    }
}
