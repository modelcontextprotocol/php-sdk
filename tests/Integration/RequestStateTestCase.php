<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Tests\Support\RoundRobinHttpClient;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real server processes behind real HTTP, for what a `requestState` does once it left the server.
 *
 * Drives the rounds by hand wherever the test plays an attacker, who is the one client that does not behave.
 *
 * @phpstan-type Call array{method: string, params: array<string, mixed>, name: string}
 * @phpstan-type Answer array{status: int, body: array<string, mixed>, raw: string, session: ?string}
 * @phpstan-type Pending array{state: string, asks: list<string>}
 */
abstract class RequestStateTestCase extends TestCase
{
    protected const OLD_KEY = 'the-key-being-rotated-out-of-the-ring';
    protected const NEW_KEY = 'the-key-being-rotated-into-the-ring-now';

    private const FIXTURE = __DIR__.'/Fixture/request_state.php';
    private const REFUSAL = 'The supplied requestState failed verification.';

    /** @var list<Process> */
    private array $servers = [];

    private ?string $sessions = null;

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            ServerProcess::stop($server);
        }

        $this->servers = [];

        if (null !== $this->sessions) {
            array_map('unlink', glob($this->sessions.'/*') ?: []);
            @rmdir($this->sessions);
            $this->sessions = null;
        }
    }

    /**
     * @param string|list<string> $keys
     */
    protected function startServer(string|array $keys, bool $binder = false, bool $anonymous = false, int $ttl = 600, string $subjectClaim = '', bool $shim = true): string
    {
        $port = ServerProcess::findFreePort();

        // Every request of `php -S` is a process of its own, so a session has to outlive it.
        $this->sessions ??= sys_get_temp_dir().'/mcp-request-state-'.bin2hex(random_bytes(8));

        $server = new Process(['php', '-S', \sprintf('127.0.0.1:%d', $port), self::FIXTURE], null, [
            ...self::environment($keys, $binder, $anonymous, $ttl, $subjectClaim, $shim),
            'MCP_REQUEST_STATE_SESSIONS' => $this->sessions,
        ]);
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                return \sprintf('http://127.0.0.1:%d/', $port);
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The server did not start: %s', $server->getErrorOutput()));
    }

    /**
     * @param string|list<string> $keys
     *
     * @return array<string, string>
     */
    protected static function environment(string|array $keys, bool $binder = false, bool $anonymous = false, int $ttl = 600, string $subjectClaim = '', bool $shim = true): array
    {
        return [
            'MCP_REQUEST_STATE_KEYS' => json_encode($keys, \JSON_THROW_ON_ERROR),
            'MCP_REQUEST_STATE_BINDER' => $binder ? '1' : '',
            'MCP_REQUEST_STATE_ANONYMOUS' => $anonymous ? '1' : '',
            'MCP_REQUEST_STATE_TTL' => (string) $ttl,
            'MCP_REQUEST_STATE_SUBJECT_CLAIM' => $subjectClaim,
            'MCP_REQUEST_STATE_NO_SHIM' => $shim ? '' : '1',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return Call
     */
    protected static function tool(string $name, array $arguments = []): array
    {
        return ['method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments], 'name' => $name];
    }

    /**
     * @return Call
     */
    protected static function resource(string $uri): array
    {
        return ['method' => 'resources/read', 'params' => ['uri' => $uri], 'name' => $uri];
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return Call
     */
    protected static function prompt(string $name, array $arguments): array
    {
        return ['method' => 'prompts/get', 'params' => ['name' => $name, 'arguments' => (object) $arguments], 'name' => $name];
    }

    /**
     * @param Call                 $call
     * @param array<string, mixed> $input `inputResponses` and `requestState`
     * @param array<string, mixed> $meta
     *
     * @return Answer
     */
    protected function send(string $url, ?string $token, array $call, array $input = [], array $meta = [], int $id = 1): array
    {
        $version = ProtocolVersion::V2026_07_28->value;
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: '.$version,
            'Mcp-Method: '.$call['method'],
            'Mcp-Name: '.$call['name'],
        ];

        if (null !== $token) {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        return $this->post($url, $headers, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $call['method'],
            'params' => [
                ...$call['params'],
                ...$input,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => $version,
                    'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => new \stdClass()],
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'request-state-test', 'version' => '1.0.0'],
                    ...$meta,
                ],
            ],
        ]);
    }

    /**
     * Opens a session of the handshake era, as the same token on the same endpoint.
     */
    protected function handshake(string $url, string $token): string
    {
        $session = $this->post($url, self::handshakeHeaders($token), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => ['elicitation' => new \stdClass()],
                'clientInfo' => ['name' => 'request-state-test', 'version' => '1.0.0'],
            ],
        ])['session'];

        $this->assertNotNull($session, 'The server opened no session.');

        return $session;
    }

    /**
     * @param Call                 $call
     * @param array<string, mixed> $input `requestState`
     *
     * @return Answer
     */
    protected function sendOnSession(string $url, string $token, string $session, array $call, array $input = []): array
    {
        return $this->post($url, self::handshakeHeaders($token, $session), [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => $call['method'],
            'params' => [...$call['params'], ...$input],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function handshakeHeaders(string $token, ?string $session = null): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer '.$token,
        ];

        if (null !== $session) {
            $headers[] = 'Mcp-Session-Id: '.$session;
            $headers[] = 'MCP-Protocol-Version: '.ProtocolVersion::V2025_11_25->value;
        }

        return $headers;
    }

    /**
     * @param list<string>         $headers
     * @param array<string, mixed> $message
     *
     * @return Answer
     */
    private function post(string $url, array $headers, array $message): array
    {
        $stream = fopen($url, 'r', false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($message, \JSON_THROW_ON_ERROR, 2048),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));

        $this->assertIsResource($stream, 'No response from the server.');

        /** @var list<string> $responseHeaders */
        $responseHeaders = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $raw = (string) stream_get_contents($stream);
        fclose($stream);

        // The answer closes a stream, behind whatever the handler reported on the way.
        $frames = [];
        $json = preg_match_all('/^data: (.+)$/m', $raw, $frames) > 0 ? (string) end($frames[1]) : $raw;

        $body = json_decode($json, true, 2048);
        $this->assertIsArray($body, \sprintf('The server did not answer with JSON: %s', $raw));

        $session = null;

        foreach ($responseHeaders as $header) {
            if (0 === stripos($header, 'mcp-session-id:')) {
                $session = trim(substr($header, 15));
            }
        }

        return ['status' => (int) (explode(' ', $responseHeaders[0] ?? '')[1] ?? 0), 'body' => $body, 'raw' => $raw, 'session' => $session];
    }

    /**
     * Plays `$token`'s rounds up to the one that hands out a state, and stops short of answering it.
     *
     * @param Call                 $call
     * @param array<string, mixed> $meta
     *
     * @return Pending
     */
    protected function pendingState(string $url, ?string $token, array $call, array $meta = []): array
    {
        $input = [];

        for ($round = 0; $round < 5; ++$round) {
            $answer = $this->send($url, $token, $call, $input, $meta);
            $result = $answer['body']['result'] ?? [];
            $this->assertSame('input_required', $result['resultType'] ?? null, $answer['raw']);

            $asks = array_map('strval', array_keys($result['inputRequests'] ?? []));

            if (isset($result['requestState'])) {
                $this->assertIsString($result['requestState']);

                return ['state' => $result['requestState'], 'asks' => $asks];
            }

            $input = ['inputResponses' => self::answers($asks, (string) $token)];
        }

        $this->fail('The server never handed out a requestState.');
    }

    /**
     * @param Pending              $pending
     * @param Call                 $call
     * @param array<string, mixed> $meta
     *
     * @return Answer
     */
    protected function finish(string $url, ?string $token, array $call, array $pending, array $meta = []): array
    {
        return $this->send($url, $token, $call, [
            'inputResponses' => self::answers($pending['asks'], (string) $token),
            'requestState' => $pending['state'],
        ], $meta);
    }

    /**
     * @param Answer $answer
     */
    protected function assertRefused(array $answer): void
    {
        $this->assertSame(400, $answer['status'], $answer['raw']);
        $this->assertSame(Error::INVALID_PARAMS, $answer['body']['error']['code'] ?? null, $answer['raw']);
        $this->assertSame(self::REFUSAL, $answer['body']['error']['message'] ?? null, $answer['raw']);
    }

    /**
     * @param Answer $answer
     */
    protected function assertCompleted(array $answer, string $expected): void
    {
        $this->assertSame(200, $answer['status'], $answer['raw']);

        $result = $answer['body']['result'] ?? [];
        $text = $result['content'][0]['text'] ?? $result['contents'][0]['text'] ?? $result['messages'][0]['content']['text'] ?? null;

        $this->assertSame($expected, $text, $answer['raw']);
    }

    /**
     * Our own client answering every ask, sending each request to the next of `$endpoints`.
     *
     * @param non-empty-list<string> $endpoints
     */
    protected function client(array $endpoints, ?string $token = null): Client
    {
        $client = Client::builder()
            ->setClientInfo('request-state-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setCapabilities(new ClientCapabilities(elicitation: true))
            ->setRequestTimeout(10)
            ->addRequestHandler(new class implements RequestHandlerInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof ElicitRequest;
                }

                public function handle(Request $request): Response
                {
                    return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, ['v' => 'client']));
                }
            })
            ->build();

        $client->connect(new HttpTransport(
            $endpoints[0],
            null === $token ? [] : ['Authorization' => 'Bearer '.$token],
            new RoundRobinHttpClient($endpoints),
        ));

        return $client;
    }

    protected static function text(CallToolResult $result): string
    {
        $first = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $first);

        return $first->text;
    }

    /**
     * @param list<string> $asks
     *
     * @return array<string, array{action: string, content: array{v: string}}>
     */
    private static function answers(array $asks, string $from): array
    {
        $answers = [];

        foreach ($asks as $ask) {
            $answers[$ask] = ['action' => 'accept', 'content' => ['v' => $from]];
        }

        return $answers;
    }
}
