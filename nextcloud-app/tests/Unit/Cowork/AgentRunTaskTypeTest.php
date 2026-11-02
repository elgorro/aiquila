<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\AgentRunTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\Exception\RunCancelledException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentRunTaskTypeTest extends TestCase {
    private $provider;
    private $mcp;
    private $prompts;
    private $rootFolder;
    /** @var list<string> tools the MCP client actually ran */
    private array $executed = [];

    protected function setUp(): void {
        $this->provider = $this->createStub(LLMProviderInterface::class);
        $this->provider->method('getId')->willReturn('anthropic');
        $this->mcp = $this->createStub(McpClientService::class);
        $this->mcp->method('getAllTools')->willReturn([
            'tools' => [self::tool('read_mail'), self::tool('delete_mail')],
            'mapping' => ['read_mail' => ['serverId' => 1, 'originalName' => 'read_mail'], 'delete_mail' => ['serverId' => 1, 'originalName' => 'delete_mail']],
        ]);
        $this->mcp->method('executeTool')->willReturnCallback(function (string $name): array {
            $this->executed[] = $name;
            return ['content' => [['type' => 'text', 'text' => 'ok']]];
        });
        $this->prompts = $this->createStub(PromptMapper::class);
        $this->rootFolder = $this->createStub(IRootFolder::class);
    }

    private static function tool(string $name): array {
        return ['name' => $name, 'description' => $name, 'input_schema' => ['type' => 'object']];
    }

    private function task(): AgentRunTaskType {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($this->provider);
        return new AgentRunTaskType($factory, $this->mcp, $this->prompts, $this->rootFolder, $this->createStub(UsageStatService::class), $this->createStub(LoggerInterface::class));
    }

    private function coworker(array $options = [], ?string $prompt = 'Summarise my unread mail.'): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('agent:run');
        $cw->setCustomPrompt($prompt);
        $cw->setOptions(json_encode($options));
        $cw->setOutputType('none');
        return $cw;
    }

    private static function progress(): callable {
        return static function (): void {
        };
    }

    public function testWithoutToolsThePromptRunsPlain(): void {
        $this->provider->method('chat')->willReturnCallback(function (array $messages): array {
            $this->assertSame([['role' => 'user', 'content' => 'Summarise my unread mail.']], $messages);
            return ['response' => 'Nothing urgent.', 'usage' => []];
        });

        $result = $this->task()->run($this->coworker(), new CoworkerRun(), self::progress());

        $this->assertSame('Nothing urgent.', $result['summary']);
    }

    /**
     * Only allowlisted tools are offered, and the executor refuses any other
     * name even when the model asks for it.
     */
    public function testOnlyAllowlistedToolsAreOfferedOrRun(): void {
        $offered = null;
        $this->provider->method('chatWithTools')->willReturnCallback(function (array $messages, array $tools, callable $execute) use (&$offered): array {
            $offered = array_column($tools, 'name');
            $refused = $execute('delete_mail', ['all' => true]);
            $execute('read_mail', []);
            $this->assertTrue($refused['isError']);
            return ['response' => 'Two unread mails.', 'usage' => []];
        });
        $run = new CoworkerRun();

        $result = $this->task()->run($this->coworker(['tools' => ['read_mail']]), $run, self::progress());

        $this->assertSame(['read_mail'], $offered);
        $this->assertSame(['read_mail'], $this->executed, 'delete_mail never reached the MCP server');
        $this->assertSame('Two unread mails.', $result['summary']);
        $this->assertSame(['Refused a tool outside the allowlist: delete_mail', 'Called read_mail'], array_column($run->getDecodedLog(), 'message'));
    }

    public function testASavedPromptOfTheOwnerIsUsed(): void {
        $saved = new Prompt();
        $saved->setContent('List my meetings.');
        $this->prompts = $this->createMock(PromptMapper::class);
        $this->prompts->expects($this->once())->method('findByIdAndUser')->with(5, 'alice')->willReturn($saved);
        $this->provider->method('chat')->willReturnCallback(fn(array $m): array => ['response' => $m[0]['content']]);
        $coworker = $this->coworker([], null);
        $coworker->setPromptId(5);

        $this->assertSame('List my meetings.', $this->task()->run($coworker, new CoworkerRun(), self::progress())['summary']);
    }

    public function testNoPromptIsAnError(): void {
        $this->expectExceptionMessage('no prompt to run');
        $this->task()->run($this->coworker([], null), new CoworkerRun(), self::progress());
    }

    public function testARefusalFailsTheRunAsSuch(): void {
        $this->provider->method('chat')->willReturn(['error' => 'Declined (cyber).', 'error_type' => 'refused']);

        $this->expectExceptionMessage('Refused: Declined (cyber).');
        $this->task()->run($this->coworker(), new CoworkerRun(), self::progress());
    }

    public function testTheAnswerIsWrittenToTheOutputFile(): void {
        $written = null;
        $folder = $this->createStub(Folder::class);
        $folder->method('newFile')->willReturnCallback(function (string $name, $content) use (&$written): File {
            $written = [$name, $content];
            $file = $this->createStub(File::class);
            $file->method('getPath')->willReturn('/alice/files/Notes/' . $name);
            return $file;
        });
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('nodeExists')->willReturn(false);
        $userFolder->method('get')->willReturn($folder);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $this->provider->method('chat')->willReturn(['response' => '- Nothing urgent']);
        $coworker = $this->coworker();
        $coworker->setOutputType('file');
        $coworker->setOutputPath('/Notes/daily.md');
        $run = new CoworkerRun();

        $this->task()->run($coworker, $run, self::progress());

        $this->assertSame(['daily.md', '- Nothing urgent'], $written);
        $this->assertSame('/Notes/daily.md', $run->getDecodedLog()[0]['item']);
    }

    /** A stop between tool calls refuses further calls and ends the run as cancelled. */
    public function testAStopEndsTheLoopAtTheNextToolCall(): void {
        $this->provider->method('chatWithTools')->willReturnCallback(function (array $m, array $t, callable $execute): array {
            $this->assertTrue($execute('read_mail', [])['isError']);
            return ['response' => 'Stopped early.'];
        });
        $stopped = static function (): void {
            throw new RunCancelledException('stopped');
        };

        $this->expectException(RunCancelledException::class);
        try {
            $this->task()->run($this->coworker(['tools' => ['read_mail']]), new CoworkerRun(), $stopped);
        } finally {
            $this->assertSame([], $this->executed);
        }
    }

    public function testToolNamesMustBeStrings(): void {
        $this->expectException(ValidationException::class);
        $this->task()->validateOptions(['tools' => ['read_mail', 7]]);
    }

    public function testTaskBudgetHasTheApiMinimum(): void {
        $this->expectException(ValidationException::class);
        $this->task()->validateOptions(['taskBudget' => 5000]);
    }
}
