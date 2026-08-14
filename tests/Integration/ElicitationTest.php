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

use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Exception\ClientException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Server\Builder as ServerBuilder;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Elicitation, driven all the way around the loop.
 *
 * This is the round-trip that suspends both sides at once: the tool's Fiber
 * waits on the client while the client's request Fiber waits on the tool.
 */
final class ElicitationTest extends IntegrationTestCase
{
    #[TestDox('an accepted elicitation hands the content back to the tool')]
    public function testAcceptedElicitation(): void
    {
        $client = $this->connect(
            $this->serverWithElicitingTool(),
            $this->clientAnswering(new ElicitResult(ElicitAction::Accept, ['name' => 'Ada'])),
        );

        $result = $client->callTool('ask_name');

        $this->assertFalse($result->isError);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('accept:Ada', $result->content[0]->text);
    }

    #[TestDox('a declined elicitation reaches the tool as a decline, not an error')]
    public function testDeclinedElicitation(): void
    {
        $client = $this->connect(
            $this->serverWithElicitingTool(),
            $this->clientAnswering(new ElicitResult(ElicitAction::Decline)),
        );

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('decline:', $result->content[0]->text);
    }

    #[TestDox('a client that does not advertise elicitation is not asked')]
    public function testCapabilityIsVisibleToTheServer(): void
    {
        // The tool checks supportsElicitation() before asking, and that answer
        // comes from the capabilities this client sent during the handshake.
        $client = $this->connect($this->serverWithElicitingTool());

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('unsupported', $result->content[0]->text);
    }

    #[TestDox('a client advertising elicitation without a handler fails the tool call')]
    public function testAdvertisedCapabilityWithoutHandler(): void
    {
        // The client answers "method not found", which the gateway raises inside
        // the tool as a ClientException. Nothing hangs: the refusal travels the
        // same path a result would, and the tool decides what to do with it.
        $client = $this->connect(
            $this->serverWithElicitingTool(),
            $this->clientBuilder()->setCapabilities(new ClientCapabilities(elicitation: true)),
        );

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Client does not handle "elicitation/create" requests.', $result->content[0]->text);
    }

    private function serverWithElicitingTool(): ServerBuilder
    {
        return $this->serverBuilder()->addTool(
            static function (RequestContext $context): string {
                $gateway = $context->getClientGateway();

                if (!$gateway->supportsElicitation()) {
                    return 'unsupported';
                }

                try {
                    $result = $gateway->elicit('What is your name?', new ElicitationSchema([
                        'name' => new StringSchemaDefinition(title: 'Name'),
                    ]));
                } catch (ClientException $e) {
                    return $e->getMessage();
                }

                return \sprintf('%s:%s', $result->action->value, $result->content['name'] ?? '');
            },
            name: 'ask_name',
            description: 'Asks the client for a name.',
        );
    }

    private function clientAnswering(ElicitResult $answer): ClientBuilder
    {
        $callback = new class($answer) implements ElicitationCallbackInterface {
            public function __construct(private readonly ElicitResult $answer)
            {
            }

            public function __invoke(ElicitRequest $request): ElicitResult
            {
                return $this->answer;
            }
        };

        return $this->clientBuilder()
            ->setCapabilities(new ClientCapabilities(elicitation: true))
            ->addRequestHandler(new ElicitationRequestHandler($callback));
    }
}
