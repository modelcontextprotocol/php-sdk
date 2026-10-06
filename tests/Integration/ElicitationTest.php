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
use Mcp\Exception\RuntimeException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Elicitation, driven all the way around the loop.
 *
 * The round-trip that suspends both sides at once: the tool's Fiber waits on
 * the client while the client's request Fiber waits on the tool.
 *
 * @see Fixture/elicitation.php for the server under test
 */
final class ElicitationTest extends IntegrationTestCase
{
    #[TestDox('an accepted elicitation hands the content back to the tool')]
    public function testAcceptedElicitation(): void
    {
        $client = $this->connect('elicitation', $this->clientAnswering(
            new ElicitResult(ElicitAction::Accept, ['name' => 'Ada']),
        ));

        $result = $client->callTool('ask_name');

        $this->assertFalse($result->isError);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('accept:Ada', $result->content[0]->text);
    }

    #[TestDox('a declined elicitation reaches the tool as a decline, not an error')]
    public function testDeclinedElicitation(): void
    {
        $client = $this->connect('elicitation', $this->clientAnswering(
            new ElicitResult(ElicitAction::Decline),
        ));

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('decline:', $result->content[0]->text);
    }

    #[TestDox('a client that does not advertise elicitation is not asked')]
    public function testCapabilityIsVisibleToTheServer(): void
    {
        // The tool consults supportsElicitation(), which answers from the
        // capabilities this client declared.
        $client = $this->connect('elicitation');

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('unsupported', $result->content[0]->text);
    }

    #[TestDox('a client advertising elicitation without a handler fails the tool call')]
    public function testAdvertisedCapabilityWithoutHandler(): void
    {
        // The client answers "method not found", which the gateway raises inside
        // the tool as a ClientException rather than leaving it waiting.
        $client = $this->connect(
            'elicitation',
            $this->clientBuilder()
                ->setProtocolVersion(ProtocolVersion::V2025_11_25)
                ->setCapabilities(new ClientCapabilities(elicitation: true)),
        );

        $result = $client->callTool('ask_name');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Client does not handle "elicitation/create" requests.', $result->content[0]->text);
    }

    #[TestDox('on the modern era, an ask the client advertised but cannot answer fails the call on the client')]
    public function testAdvertisedCapabilityWithoutHandlerOnTheModernEra(): void
    {
        $client = $this->connect(
            'elicitation',
            $this->clientBuilder()
                ->setProtocolVersion(ProtocolVersion::V2026_07_28)
                ->setCapabilities(new ClientCapabilities(elicitation: true)),
        );

        $this->assertSame(ProtocolVersion::V2026_07_28, $client->getProtocolVersion());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client does not handle "elicitation/create" requests.');

        $client->callTool('ask_name');
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
