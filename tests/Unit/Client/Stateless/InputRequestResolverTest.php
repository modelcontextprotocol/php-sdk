<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Stateless;

use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Stateless\InputRequestResolver;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class InputRequestResolverTest extends TestCase
{
    #[TestDox('a result with no resultType is complete, not an ask')]
    public function testDefaultResultTypeIsComplete(): void
    {
        $this->assertNull(InputRequestResolver::asked(['content' => []]));
        $this->assertNull(InputRequestResolver::asked(['resultType' => 'complete']));
    }

    #[TestDox('an input_required result is an ask, even with an empty map')]
    public function testInputRequiredIsAnAsk(): void
    {
        $this->assertSame([], InputRequestResolver::asked(['resultType' => 'input_required']));
        $this->assertSame(
            ['confirm' => ['method' => 'elicitation/create']],
            InputRequestResolver::asked([
                'resultType' => 'input_required',
                'inputRequests' => ['confirm' => ['method' => 'elicitation/create']],
            ]),
        );
    }

    #[TestDox('answers each ask under the key the server asked it under')]
    public function testAnswersAreKeyedByTheServersKey(): void
    {
        $responses = $this->resolver()->resolve([
            'confirm' => self::elicitation('Confirm?'),
            'name' => self::elicitation('Your name?'),
        ]);

        $this->assertSame(['confirm', 'name'], array_keys($responses));
        $this->assertSame('accept', $responses['confirm']['action']);
    }

    #[TestDox('refuses an ask the client has no handler for')]
    public function testUnhandledAskIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client does not handle "elicitation/create" requests.');

        (new InputRequestResolver([]))->resolve(['confirm' => self::elicitation('Confirm?')]);
    }

    #[TestDox('refuses an ask that is not a request a client can answer')]
    public function testUnanswerableMethodIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('which is not a request a client can answer');

        $this->resolver()->resolve(['x' => ['method' => 'tools/call', 'params' => []]]);
    }

    #[TestDox('refuses an ask with no method to answer')]
    public function testMethodlessAskIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a method to answer');

        $this->resolver()->resolve(['x' => ['params' => []]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function elicitation(string $message): array
    {
        return [
            'method' => 'elicitation/create',
            'params' => [
                'message' => $message,
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['confirmed' => ['type' => 'boolean']],
                ],
            ],
        ];
    }

    private function resolver(): InputRequestResolver
    {
        return new InputRequestResolver([
            new class implements RequestHandlerInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof ElicitRequest;
                }

                public function handle(Request $request): Response
                {
                    return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, []));
                }
            },
        ]);
    }
}
