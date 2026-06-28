<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\OpenMetrics;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\OpenMetrics\ConversationsExporter;
use OCA\AIquila\OpenMetrics\McpServerStatusExporter;
use OCA\AIquila\OpenMetrics\TasksExporter;
use OCA\AIquila\OpenMetrics\TokensUsedExporter;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use PHPUnit\Framework\TestCase;

class ExportersTest extends TestCase {

    /**
     * @param iterable<Metric> $metrics
     * @return Metric[]
     */
    private function collect(iterable $metrics): array {
        return iterator_to_array($metrics, false);
    }

    public function testTokensUsedExporter(): void {
        $mapper = $this->createMock(UsageStatMapper::class);
        $mapper->method('sumTokensByModelAndType')->willReturn([
            ['model' => 'claude-opus-4-8', 'request_type' => 'chat', 'input_tokens' => 1000, 'output_tokens' => 500],
        ]);

        $exporter = new TokensUsedExporter($mapper);
        $this->assertSame('aiquila_tokens_used_total', $exporter->name());
        $this->assertSame(MetricType::counter, $exporter->type());

        $metrics = $this->collect($exporter->metrics());
        $this->assertCount(2, $metrics);
        $this->assertSame(1000, $metrics[0]->value);
        $this->assertSame('input', $metrics[0]->label('direction'));
        $this->assertSame('claude-opus-4-8', $metrics[0]->label('model'));
        $this->assertSame(500, $metrics[1]->value);
        $this->assertSame('output', $metrics[1]->label('direction'));
    }

    public function testTasksExporter(): void {
        $mapper = $this->createMock(CoworkerRunMapper::class);
        $mapper->method('countByStatus')->willReturn(['success' => 7, 'error' => 2]);

        $exporter = new TasksExporter($mapper);
        $this->assertSame('aiquila_tasks_total', $exporter->name());
        $this->assertSame(MetricType::counter, $exporter->type());

        $metrics = $this->collect($exporter->metrics());
        $this->assertCount(2, $metrics);
        $this->assertSame(7, $metrics[0]->value);
        $this->assertSame('success', $metrics[0]->label('status'));
    }

    public function testConversationsExporter(): void {
        $mapper = $this->createMock(ConversationMapper::class);
        $mapper->method('countAll')->willReturn(42);

        $exporter = new ConversationsExporter($mapper);
        $this->assertSame('aiquila_conversations_total', $exporter->name());

        $metrics = $this->collect($exporter->metrics());
        $this->assertCount(1, $metrics);
        $this->assertSame(42, $metrics[0]->value);
    }

    public function testMcpServerStatusExporter(): void {
        $up = $this->makeServer(1, 'Calendars', true, 'ok');
        $down = $this->makeServer(2, 'Mail', true, 'error');
        $disabled = $this->makeServer(3, 'Notes', false, 'ok');

        $mapper = $this->createMock(McpServerMapper::class);
        $mapper->method('findAll')->willReturn([$up, $down, $disabled]);

        $exporter = new McpServerStatusExporter($mapper);
        $this->assertSame('aiquila_mcp_server_status', $exporter->name());
        $this->assertSame(MetricType::gauge, $exporter->type());

        $metrics = $this->collect($exporter->metrics());
        $this->assertCount(3, $metrics);
        $this->assertSame(1, $metrics[0]->value);
        $this->assertSame('Calendars', $metrics[0]->label('server'));
        $this->assertSame(0, $metrics[1]->value); // enabled but failing
        $this->assertSame(0, $metrics[2]->value); // disabled
    }

    private function makeServer(int $id, string $name, bool $enabled, string $status): McpServer {
        $server = new McpServer();
        $server->setId($id);
        $server->setDisplayName($name);
        $server->setIsEnabled($enabled);
        $server->setLastStatus($status);
        return $server;
    }
}
