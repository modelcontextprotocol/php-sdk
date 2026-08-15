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

use Mcp\Exception\ClientException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ClientGatewayTest extends TestCase
{
    public function testSupportsRootsReturnsTrueWhenAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['roots' => []]);

        $gateway = new ClientGateway($session);

        $this->assertTrue($gateway->supportsRoots());
    }

    public function testSupportsRootsReturnsFalseWhenNotAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['sampling' => []]);

        $gateway = new ClientGateway($session);

        $this->assertFalse($gateway->supportsRoots());
    }

    public function testSupportsSamplingReturnsTrueWhenAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['sampling' => []]);

        $gateway = new ClientGateway($session);

        $this->assertTrue($gateway->supportsSampling());
    }

    public function testSupportsSamplingReturnsFalseWhenNotAdvertised(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('client_capabilities', [])->willReturn(['roots' => []]);

        $gateway = new ClientGateway($session);

        $this->assertFalse($gateway->supportsSampling());
    }

    public function testSupportsSamplingToolsReflectsTheSubCapability(): void
    {
        $this->assertTrue($this->gatewayFor(['sampling' => ['tools' => []]])->supportsSamplingTools());
        $this->assertFalse($this->gatewayFor(['sampling' => []])->supportsSamplingTools());
        $this->assertFalse($this->gatewayFor([])->supportsSamplingTools());
    }

    public function testSupportsSamplingToolsReadsTheObjectShapeToo(): void
    {
        $capabilities = (new ClientCapabilities(samplingTools: true))->jsonSerialize();

        $this->assertTrue($this->gatewayFor((array) $capabilities)->supportsSamplingTools());
    }

    public function testSupportsElicitationUrlReflectsTheSubCapability(): void
    {
        $this->assertTrue($this->gatewayFor(['elicitation' => ['url' => []]])->supportsElicitationUrl());
        // An `elicitation` naming no mode declares form mode alone.
        $this->assertFalse($this->gatewayFor(['elicitation' => []])->supportsElicitationUrl());
        $this->assertFalse($this->gatewayFor(['elicitation' => ['form' => []]])->supportsElicitationUrl());
    }

    public function testElicitUrlSendsAUrlModeRequest(): void
    {
        $gateway = $this->gatewayFor(['elicitation' => ['url' => []]]);

        $request = null;
        $result = $this->runInFiber(
            static fn (): ElicitResult => $gateway->elicitUrl('Authorize the app', 'https://example.com/consent'),
            // A url-mode accept carries no content — the interaction happened out of band.
            $this->response(['action' => 'accept']),
            ElicitRequest::class,
            $request,
        );

        $this->assertInstanceOf(ElicitRequest::class, $request);
        $this->assertSame(ElicitationMode::Url, $request->mode);
        $this->assertSame('https://example.com/consent', $request->url);
        $this->assertInstanceOf(ElicitResult::class, $result);
        $this->assertSame(ElicitAction::Accept, $result->action);
        $this->assertNull($result->content);
    }

    public function testElicitUrlRejectsAClientThatOnlySupportsForms(): void
    {
        $gateway = $this->gatewayFor(['elicitation' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/elicitation\.url/');

        $gateway->elicitUrl('Authorize the app', 'https://example.com/consent');
    }

    public function testElicitStillSendsFormMode(): void
    {
        $gateway = $this->gatewayFor([]);

        $request = null;
        $result = $this->runInFiber(
            static fn (): ElicitResult => $gateway->elicit('Your name?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')])),
            $this->response(['action' => 'accept', 'content' => ['name' => 'Ada']]),
            ElicitRequest::class,
            $request,
        );

        $this->assertInstanceOf(ElicitRequest::class, $request);
        $this->assertSame(ElicitationMode::Form, $request->mode);
        $this->assertSame(['name' => 'Ada'], $result->content);
    }

    public function testListRootsReturnsRootsFromClient(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $gateway = new ClientGateway($session);

        $response = $this->response([
            'roots' => [
                ['uri' => 'file:///home/user/project', 'name' => 'project'],
            ],
        ]);

        $result = $this->runInFiber(static fn (): ListRootsResult => $gateway->listRoots(), $response);

        $this->assertInstanceOf(ListRootsResult::class, $result);
        $this->assertCount(1, $result->roots);
        $this->assertSame('file:///home/user/project', $result->roots[0]->uri);
    }

    public function testListRootsThrowsClientExceptionOnError(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $gateway = new ClientGateway($session);

        $error = Error::forInternalError('nope', '1');

        $this->expectException(ClientException::class);

        $this->runInFiber(static fn (): ListRootsResult => $gateway->listRoots(), $error);
    }

    /**
     * @param array<string, mixed> $capabilities the client capabilities the session reports
     */
    private function gatewayFor(array $capabilities): ClientGateway
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => 'client_capabilities' === $key ? $capabilities : $default,
        );

        return new ClientGateway($session);
    }

    /**
     * Runs the gateway call inside a Fiber, asserts it suspends with the expected
     * request, then resumes it with the given client response.
     *
     * @param Response<array<string, mixed>>|Error $response
     * @param class-string<Request>                $expectedRequest
     * @param ?Request                             $request         receives the request the gateway suspended with
     *
     * @param-out Request $request
     */
    private function runInFiber(\Closure $call, Response|Error $response, string $expectedRequest = ListRootsRequest::class, mixed &$request = null): mixed
    {
        $fiber = new \Fiber($call);
        $suspend = $fiber->start();

        $this->assertIsArray($suspend);
        $this->assertSame('request', $suspend['type']);
        $this->assertInstanceOf($expectedRequest, $suspend['request']);

        $request = $suspend['request'];

        $fiber->resume($response);

        return $fiber->getReturn();
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return Response<array<string, mixed>>
     */
    private function response(array $result): Response
    {
        return new Response('1', $result);
    }
}
