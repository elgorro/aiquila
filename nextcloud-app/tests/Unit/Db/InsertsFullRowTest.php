<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\FileUpload;
use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageFile;
use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\ProviderAccessRule;
use OCA\AIquila\Db\UsageStat;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * insert() writes only the fields marked updated, and Entity::setter() does not
 * mark a value equal to the current one. Every non-null field of a new entity
 * must therefore be marked from the start, or a value equal to its default is
 * dropped from the INSERT.
 */
class InsertsFullRowTest extends TestCase {

    /** @return array<string, array{class-string<Entity>}> */
    public static function entities(): array {
        $classes = [
            Conversation::class, Coworker::class, CoworkerRun::class, FileUpload::class,
            McpServer::class, Message::class, MessageFile::class, Project::class,
            ProjectPath::class, Prompt::class, ProviderAccessRule::class, UsageStat::class,
        ];
        return array_combine(
            array_map(fn (string $c) => substr($c, strrpos($c, '\\') + 1), $classes),
            array_map(fn (string $c) => [$c], $classes),
        );
    }

    /** @param class-string<Entity> $class */
    #[DataProvider('entities')]
    public function testNewEntityMarksEveryNonNullField(string $class): void {
        $entity = new $class();
        $updated = $entity->getUpdatedFields();

        foreach (array_keys($entity->getFieldTypes()) as $field) {
            if ($field === 'id') {
                continue;
            }
            $getter = 'get' . ucfirst($field);
            if ($entity->$getter() === null) {
                $this->assertArrayNotHasKey($field, $updated, "$class::$field is null and must stay unmarked");
            } else {
                $this->assertArrayHasKey($field, $updated, "$class::$field would be left out of the INSERT");
            }
        }
    }

    /** @param class-string<Entity> $class */
    #[DataProvider('entities')]
    public function testHydratedEntityStartsClean(string $class): void {
        $entity = $class::fromRow(['id' => 1]);
        $this->assertSame([], $entity->getUpdatedFields());
    }

    public function testProjectPathKeepsDefaultTypeOnInsert(): void {
        $path = new ProjectPath();
        $path->setProjectId(3);
        $path->setPath('/notes.md');
        $path->setPathType('file');

        $this->assertArrayHasKey('pathType', $path->getUpdatedFields());
    }
}
