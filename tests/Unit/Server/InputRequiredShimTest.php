<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\InputRequiredShim;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\InputContext;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The shim that serves a modern-era handler to a handshake-era client.
 *
 * Driven at the fiber boundary the way the transport drives it: the shim
 * suspends to send each embedded request, and the test resumes it with the
 * client's answer.
 */
final class InputRequiredShimTest extends TestCase
{
    #[TestDox('a handler that asks for nothing is handed straight back')]
    public function testPassesThroughAResultThatIsNotAnAsk(): void
    {
        $handler = self::handler(static fn (): Response => new Response(1, new CallToolResult([new TextContent('done')])));
        $expected = $handler->handle(self::request(), self::session());

        $answer = $this->drive($handler, $expected, self::session());

        $this->assertSame('done', self::text($answer));
    }

    #[TestDox('an ask is sent to the client and the handler re-entered with the answer')]
    public function testOneRoundTrip(): void
    {
        $entries = 0;
        $handler = self::handler(static function (Request $request, SessionInterface $session) use (&$entries): Response {
            ++$entries;
            $answer = self::inputContext($session)?->elicitResult('who');

            if (null === $answer) {
                return new Response(1, new InputRequiredResult(['who' => self::ask()]));
            }

            return new Response(1, new CallToolResult([new TextContent('Hello, '.($answer->content['name'] ?? '?').'!')]));
        });

        $answer = $this->drive($handler, $handler->handle(self::request(), $session = self::session()), $session, [
            new Response(7, ['action' => 'accept', 'content' => ['name' => 'Ada']]),
        ]);

        $this->assertSame('Hello, Ada!', self::text($answer));
        $this->assertSame(2, $entries, 're-entry is re-execution: the handler runs once per round');
    }

    #[TestDox('a client that declines is an answer, and the handler decides what it means')]
    public function testDeclineReachesTheHandler(): void
    {
        $handler = self::handler(static function (Request $request, SessionInterface $session): Response {
            $answer = self::inputContext($session)?->elicitResult('who');

            if (null === $answer) {
                return new Response(1, new InputRequiredResult(['who' => self::ask()]));
            }

            return new Response(1, new CallToolResult([new TextContent($answer->isDeclined() ? 'declined' : 'accepted')]));
        });

        $answer = $this->drive($handler, $handler->handle(self::request(), $session = self::session()), $session, [
            new Response(7, ['action' => 'decline']),
        ]);

        $this->assertSame('declined', self::text($answer));
    }

    #[TestDox('a handler that never stops asking is failed rather than looped forever')]
    public function testRoundLimit(): void
    {
        $handler = self::handler(static fn (): Response => new Response(1, new InputRequiredResult(['who' => self::ask()])));

        $answer = $this->drive(
            $handler,
            $handler->handle(self::request(), $session = self::session()),
            $session,
            array_fill(0, 3, new Response(7, ['action' => 'accept', 'content' => ['name' => 'Ada']])),
            new InputRequiredShim(maxRounds: 2),
        );

        $this->assertInstanceOf(Error::class, $answer);
        $this->assertStringContainsString('more than 2 times', $answer->message);
    }

    #[TestDox('an ask the client never declared it could answer is refused, and nothing is sent')]
    public function testUndeclaredCapabilityIsRefused(): void
    {
        $handler = self::handler(static fn (): Response => new Response(1, new InputRequiredResult(['who' => self::ask()])));

        // No `elicitation` in the session's declared capabilities.
        $session = self::session(declares: []);

        $answer = $this->drive($handler, $handler->handle(self::request(), $session), $session);

        $this->assertInstanceOf(Error::class, $answer);
        $this->assertSame(-32021, $answer->jsonSerialize()['error']['code']);
    }

    /**
     * Runs the shim the way the transport does: inside a fiber, answering each
     * suspension with the next queued client response.
     *
     * @param RequestHandlerInterface<mixed> $handler
     * @param Response<mixed>|Error          $first
     * @param list<Response<mixed>|Error>    $answers
     *
     * @return Response<mixed>|Error
     */
    private function drive(
        RequestHandlerInterface $handler,
        Response|Error $first,
        SessionInterface $session,
        array $answers = [],
        ?InputRequiredShim $shim = null,
    ): Response|Error {
        $shim ??= new InputRequiredShim();
        $request = self::request();

        $fiber = new \Fiber(static fn (): Response|Error => $shim->fulfill($first, $handler, $request, $session, null));

        $suspended = $fiber->start();

        while ($fiber->isSuspended()) {
            $this->assertIsArray($suspended);
            $this->assertSame('request', $suspended['type'], 'the shim only ever suspends to send a client request');
            $this->assertNotEmpty($answers, 'the shim sent more requests than the test queued answers for');

            $suspended = $fiber->resume(array_shift($answers));
        }

        /** @var Response<mixed>|Error $return */
        $return = $fiber->getReturn();

        return $return;
    }

    private static function ask(): ElicitRequest
    {
        return new ElicitRequest('Who?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']));
    }

    private static function request(): CallToolRequest
    {
        return (new CallToolRequest('greet', []))->withId(1);
    }

    /**
     * @param array<string, mixed> $declares
     */
    private static function session(array $declares = ['elicitation' => []]): SessionInterface
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('client_capabilities', $declares);

        return $session;
    }

    private static function inputContext(SessionInterface $session): ?InputContext
    {
        $context = $session->get(InputContext::class);

        return $context instanceof InputContext ? $context : null;
    }

    /**
     * @param \Closure(Request, SessionInterface): (Response<mixed>|Error) $handle
     *
     * @return RequestHandlerInterface<mixed>
     */
    private static function handler(\Closure $handle): RequestHandlerInterface
    {
        return new class($handle) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $handle)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response|Error
            {
                return ($this->handle)($request, $session);
            }
        };
    }

    /**
     * @param Response<mixed>|Error $result
     */
    private static function text(Response|Error $result): string
    {
        self::assertInstanceOf(Response::class, $result);
        self::assertInstanceOf(CallToolResult::class, $result->result);
        $first = $result->result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $first);

        return $first->text;
    }
}
