<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Fiber;

use Mcp\Exception\RuntimeException;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Tests\Unit\Fixtures\RecordingFiberContext;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @author bbdu3 <ergouyang854@gamil.com>
 */
final class FiberContextTest extends TestCase
{
    #[DataProvider('httpModes')]
    public function testContextSurvivesInterleavedRequestsAndErrors(string $mode, bool $fail): void
    {
        $context = new RecordingFiberContext();
        $seen = [];
        $builder = $this->builder($context)->addTool(static function (RequestContext $request) use ($context, &$seen, $fail): string {
            $seen[] = $context->current();
            $request->getClientGateway()->progress(1, 2);
            $seen[] = $context->current();
            if ($fail) {
                throw new RuntimeException('handler failed');
            }

            return $context->current();
        }, name: 'probe');
        $server = 'standalone' === $mode ? $builder->buildStateless() : $builder->build();
        $session = 'legacy' === $mode ? $this->post($server, $this->initialize())->getHeaderLine('Mcp-Session-Id') : null;
        $created = $context->created;
        $released = $context->released;

        $context->parent = 'first';
        $first = $this->post($server, $this->call('legacy' !== $mode), $session);
        $this->assertSame('first', $context->current());
        $context->parent = 'second';
        $second = $this->post($server, $this->call('legacy' !== $mode), $session);
        $this->assertSame(['first', 'second'], $seen);
        $this->assertSame($released, $context->released);

        $context->parent = 'outside';
        $secondBody = $this->consume($second);
        $firstBody = $this->consume($first);
        $this->assertSame(['first', 'second', 'second', 'first'], $seen);
        $this->assertSame('outside', $context->current());
        $this->assertSame($created + 2, $context->created);
        $this->assertSame($released + 2, $context->released);
        if ($fail) {
            $this->assertStringContainsString('"code":-32603', $firstBody);
            $this->assertStringContainsString('"code":-32603', $secondBody);
        } else {
            $this->assertStringContainsString('"text":"first"', $firstBody);
            $this->assertStringContainsString('"text":"second"', $secondBody);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function httpModes(): iterable
    {
        foreach (['legacy', 'modern', 'standalone'] as $mode) {
            yield $mode => [$mode, false];
            yield $mode.' error' => [$mode, true];
        }
    }

    #[DataProvider('httpModes')]
    public function testContextIsReleasedWithoutSuspension(string $mode, bool $fail): void
    {
        $context = new RecordingFiberContext();
        $builder = $this->builder($context)->addTool(static function () use ($context, $fail): string {
            if ($fail) {
                throw new RuntimeException($context->current());
            }

            return $context->current();
        }, name: 'probe');
        $server = 'standalone' === $mode ? $builder->buildStateless() : $builder->build();
        $session = 'legacy' === $mode ? $this->post($server, $this->initialize())->getHeaderLine('Mcp-Session-Id') : null;
        $body = $this->consume($this->post($server, $this->call('legacy' !== $mode), $session));

        $this->assertStringContainsString($fail ? '"code":-32603' : '"text":"parent"', $body);
        $this->assertSame($context->created, $context->released);
        $this->assertSame('parent', $context->current());
    }

    public function testContextIsReleasedWhenAStreamIsAbandoned(): void
    {
        $context = new RecordingFiberContext();
        $server = $this->builder($context)->addTool(static function (RequestContext $request) use ($context): string {
            $request->getClientGateway()->progress(1, 2);

            return $context->current();
        }, name: 'probe')->buildStateless();
        $response = $this->post($server, $this->call(true));
        $this->assertSame(1, $context->created);
        $this->assertSame(0, $context->released);
        unset($response);

        $this->assertSame(1, $context->released);
        $this->assertSame('parent', $context->current());
    }

    public function testContextIsReleasedWhenElicitationDiscardsTheFiber(): void
    {
        $context = new RecordingFiberContext();
        $seen = null;
        $server = $this->builder($context)->setRequestState(str_repeat('k', 32))
            ->addTool(static function (RequestContext $request) use ($context, &$seen): string {
                $seen = $context->current();
                $request->getClientGateway()->elicit('Name?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));

                return 'unreachable';
            }, name: 'probe')->buildStateless();
        $message = $this->call(true);
        $message['params']['_meta'][RequestMeta::CLIENT_CAPABILITIES] = ['elicitation' => ['form' => new \stdClass()]];
        $body = $this->consume($this->post($server, $message));

        $this->assertStringContainsString('"resultType":"input_required"', $body);
        $this->assertSame('parent', $seen);
        $this->assertSame(1, $context->released);
        $this->assertSame('parent', $context->current());
    }

    #[DataProvider('eras')]
    public function testStdioUsesTheFactory(bool $modern): void
    {
        $context = new RecordingFiberContext();
        $builder = $this->builder($context)->addTool(static function (RequestContext $request) use ($context): string {
            $before = $context->current();
            $request->getClientGateway()->progress(1, 2);

            return $before.':'.$context->current();
        }, name: 'probe');
        $input = fopen('php://temp', 'r+');
        $this->assertNotFalse($input);
        $path = tempnam(sys_get_temp_dir(), 'mcp-fiber');
        $this->assertNotFalse($path);
        $output = fopen($path, 'w');
        $this->assertNotFalse($output);
        if (!$modern) {
            fwrite($input, json_encode($this->initialize(), \JSON_THROW_ON_ERROR)."\n");
        }
        fwrite($input, json_encode($this->call($modern), \JSON_THROW_ON_ERROR)."\n");
        rewind($input);
        try {
            $builder->build()->run(new StdioTransport($input, $output));
            $written = file_get_contents($path);
            $this->assertNotFalse($written);
            $this->assertStringContainsString('"text":"parent:parent"', $written);
            $this->assertStringContainsString('"progressToken":"p"', $written);
            $this->assertSame($context->created, $context->released);
        } finally {
            unlink($path);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function eras(): iterable
    {
        yield 'legacy' => [false];
        yield 'modern' => [true];
    }

    private function builder(RecordingFiberContext $context): Builder
    {
        return Server::builder()->setServerInfo('context-test', '1')->setFiberContext($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function call(bool $modern): array
    {
        $meta = ['progressToken' => 'p'];
        if ($modern) {
            $meta[RequestMeta::PROTOCOL_VERSION] = '2026-07-28';
            $meta[RequestMeta::CLIENT_CAPABILITIES] = new \stdClass();
        }

        return ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'probe', '_meta' => $meta]];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(Server|StatelessProtocol $server, array $message, ?string $session = null): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($factory->createStream(json_encode($message, \JSON_THROW_ON_ERROR)));
        if (null !== $session) {
            $request = $request->withHeader('Mcp-Session-Id', $session)->withHeader('MCP-Protocol-Version', '2025-11-25');
        } elseif (isset($message['params']['_meta'][RequestMeta::PROTOCOL_VERSION])) {
            $request = $request->withHeader('MCP-Protocol-Version', '2026-07-28')
                ->withHeader('Mcp-Method', 'tools/call')->withHeader('Mcp-Name', 'probe');
        }

        return $server instanceof Server
            ? $server->run(new StreamableHttpTransport($request, $factory, $factory))
            : (new StatelessHttpTransport($server, $factory, $factory))->handle($request);
    }

    private function consume(ResponseInterface $response): string
    {
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $body = $response->getBody()->getContents();
        } finally {
            ob_end_flush();
        }

        return $output.$body;
    }
}
