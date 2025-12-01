<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Resource;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\ReadResourceHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReadResourceHandlerTest extends TestCase
{
    private ReadResourceHandler $handler;
    private RegistryInterface&MockObject $registry;
    private ReferenceHandlerInterface&MockObject $referenceHandler;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(RegistryInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->handler = new ReadResourceHandler($this->registry, $this->referenceHandler);
    }

    public function testSupportsReadResourceRequest(): void
    {
        $request = $this->createReadResourceRequest('file://test.txt');

        $this->assertTrue($this->handler->supports($request));
    }

    public function testHandleSuccessfulResourceRead(): void
    {
        $uri = 'file://documents/readme.txt';
        $request = $this->createReadResourceRequest($uri);
        $expectedContent = new TextResourceContents(
            uri: $uri,
            mimeType: 'text/plain',
            text: 'This is the content of the readme file.',
        );
        $expectedResult = new ReadResourceResult([$expectedContent]);

        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'text/plain'), []])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
            ->willReturn('test');

        $resourceReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('test', $uri, 'text/plain')
            ->willReturn([$expectedContent]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleResourceReadWithBlobContent(): void
    {
        $uri = 'file://images/logo.png';
        $request = $this->createReadResourceRequest($uri);
        $expectedContent = new BlobResourceContents(
            uri: $uri,
            mimeType: 'image/png',
            blob: base64_encode('fake-image-data'),
        );
        $expectedResult = new ReadResourceResult([$expectedContent]);

        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'image/png'), []])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
            ->willReturn('fake-image-data');

        $resourceReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('fake-image-data', $uri, 'image/png')
            ->willReturn([$expectedContent]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleResourceReadWithMultipleContents(): void
    {
        $uri = 'app://data/mixed-content';
        $request = $this->createReadResourceRequest($uri);
        $textContent = new TextResourceContents(
            uri: $uri,
            mimeType: 'text/plain',
            text: 'Text part of the resource',
        );
        $blobContent = new BlobResourceContents(
            uri: $uri,
            mimeType: 'application/octet-stream',
            blob: base64_encode('binary-data'),
        );
        $expectedResult = new ReadResourceResult([$textContent, $blobContent]);

        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'application/octet-stream'), []])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
            ->willReturn('binary-data');

        $resourceReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('binary-data', $uri, 'application/octet-stream')
            ->willReturn([$textContent, $blobContent]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleResourceNotFoundExceptionReturnsSpecificError(): void
    {
        $uri = 'file://nonexistent/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceNotFoundException($uri);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals('Resource not found for uri: "'.$uri.'".', $response->message);
    }

    public function testHandleResourceReadExceptionReturnsActualErrorMessage(): void
    {
        $uri = 'file://corrupted/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceReadException('Failed to read resource: corrupted data');

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Failed to read resource: corrupted data', $response->message);
    }

    public function testHandleGenericExceptionReturnsGenericError(): void
    {
        $uri = 'file://problematic/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new \RuntimeException('Internal database connection failed');

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::INTERNAL_ERROR, $response->code);
        $this->assertEquals('Error while reading resource', $response->message);
    }

    public function testHandleResourceReadWithDifferentUriSchemes(): void
    {
        $uriSchemes = [
            'file://local/path/file.txt',
            'http://example.com/resource',
            'https://secure.example.com/api/data',
            'ftp://files.example.com/document.pdf',
            'app://internal/resource/123',
            'custom-scheme://special/resource',
        ];

        foreach ($uriSchemes as $uri) {
            $request = $this->createReadResourceRequest($uri);
            $expectedContent = new TextResourceContents(
                uri: $uri,
                mimeType: 'text/plain',
                text: "Content for {$uri}",
            );
            $expectedResult = new ReadResourceResult([$expectedContent]);

            $resourceReference = $this->getMockBuilder(ResourceReference::class)
                ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'text/plain'), []])
                ->getMock();

            $this->registry
                ->expects($this->once())
                ->method('getResource')
                ->with($uri)
                ->willReturn($resourceReference);

            $this->referenceHandler
                ->expects($this->once())
                ->method('handle')
                ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
                ->willReturn('test');

            $resourceReference
                ->expects($this->once())
                ->method('formatResult')
                ->with('test', $uri, 'text/plain')
                ->willReturn([$expectedContent]);

            $response = $this->handler->handle($request, $this->session);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals($expectedResult, $response->result);

            // Reset the mock for next iteration
            $this->registry = $this->createMock(RegistryInterface::class);
            $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
            $this->handler = new ReadResourceHandler($this->registry, $this->referenceHandler);
        }
    }

    public function testHandleResourceReadWithEmptyContent(): void
    {
        $uri = 'file://empty/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $expectedContent = new TextResourceContents(
            uri: $uri,
            mimeType: 'text/plain',
            text: '',
        );
        $expectedResult = new ReadResourceResult([$expectedContent]);

        $resourceReference = $this->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([new Resource($uri, 'test', mimeType: 'text/plain'), []])
            ->getMock();

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
            ->willReturn('');

        $resourceReference
            ->expects($this->once())
            ->method('formatResult')
            ->with('', $uri, 'text/plain')
            ->willReturn([$expectedContent]);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($expectedResult, $response->result);
    }

    public function testHandleResourceReadWithDifferentMimeTypes(): void
    {
        $mimeTypes = [
            'text/plain',
            'text/html',
            'application/json',
            'application/xml',
            'image/png',
            'image/jpeg',
            'application/pdf',
            'video/mp4',
            'audio/mpeg',
            'application/octet-stream',
        ];

        foreach ($mimeTypes as $i => $mimeType) {
            $uri = "file://test/file{$i}";
            $request = $this->createReadResourceRequest($uri);

            if (str_starts_with($mimeType, 'text/') || str_starts_with($mimeType, 'application/json')) {
                $expectedContent = new TextResourceContents(
                    uri: $uri,
                    mimeType: $mimeType,
                    text: "Content for {$mimeType}",
                );
            } else {
                $expectedContent = new BlobResourceContents(
                    uri: $uri,
                    mimeType: $mimeType,
                    blob: base64_encode("binary-content-for-{$mimeType}"),
                );
            }
            $expectedResult = new ReadResourceResult([$expectedContent]);

            $resourceReference = $this->getMockBuilder(ResourceReference::class)
                ->setConstructorArgs([new Resource($uri, 'test', mimeType: $mimeType), []])
                ->getMock();

            $this->registry
                ->expects($this->once())
                ->method('getResource')
                ->with($uri)
                ->willReturn($resourceReference);

            $this->referenceHandler
                ->expects($this->once())
                ->method('handle')
                ->with($resourceReference, ['uri' => $uri, '_session' => $this->session, '_request' => $request])
                ->willReturn($expectedContent);

            $resourceReference
                ->expects($this->once())
                ->method('formatResult')
                ->with($expectedContent, $uri, $mimeType)
                ->willReturn([$expectedContent]);

            $response = $this->handler->handle($request, $this->session);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals($expectedResult, $response->result);

            // Reset the mock for next iteration
            $this->registry = $this->createMock(RegistryInterface::class);
            $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
            $this->handler = new ReadResourceHandler($this->registry, $this->referenceHandler);
        }
    }

    public function testHandleResourceNotFoundWithCustomMessage(): void
    {
        $uri = 'file://custom/missing.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceNotFoundException($uri);

        $this->registry
            ->expects($this->once())
            ->method('getResource')
            ->with($uri)
            ->willThrowException($exception);

        $response = $this->handler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals('Resource not found for uri: "'.$uri.'".', $response->message);
    }

    private function createReadResourceRequest(string $uri): ReadResourceRequest
    {
        return ReadResourceRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => ReadResourceRequest::getMethod(),
            'id' => 'test-request-'.uniqid(),
            'params' => [
                'uri' => $uri,
            ],
        ]);
    }
}
