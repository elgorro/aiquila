<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What the Anthropic SDK writes on the wire for the values the app decodes
 * with `json_decode(..., true)`.
 *
 * ClaudeSDKService replays a `tool_use` block with the `input` it decoded, so
 * an argument-less call carries `input: []`. The Messages API requires an
 * object there, and the SDK's ToolUseBlockParam serializes an empty input as
 * `{}`; the service relies on that rather than repairing it itself. Tool
 * schemas get no such help (`properties: []` stays a list), which is why
 * McpClientService and ChatWithToolsProvider normalize them with JsonShape
 * before they reach any provider.
 */
class AnthropicSdkEncodingTest extends TestCase {
    public function testAnEmptyToolUseInputIsSentAsAnObject(): void {
        $transport = new class implements ClientInterface {
            public string $body = '';
            public function sendRequest(RequestInterface $request): ResponseInterface {
                $this->body = (string)$request->getBody();
                return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                    'id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn', 'stop_sequence' => null,
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]));
            }
        };

        (new Client(apiKey: 'test'))->messages->create(
            maxTokens: 16,
            model: 'm',
            messages: [
                ['role' => 'user', 'content' => 'What time is it?'],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'get_local_time', 'input' => []]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '12:00']]],
            ],
            requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
        );

        $this->assertStringContainsString('"name":"get_local_time","input":{}', $transport->body);
    }
}
