<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Server\RequestHandler;

use Mcp\Capability\Resource\ResourceReaderInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\RequestHandler\ReadResourceHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReadResourceHandlerTest extends TestCase
{
    private ReadResourceHandler $handler;
    private ResourceReaderInterface|MockObject $resourceReader;

    protected function setUp(): void
    {
        $this->resourceReader = $this->createMock(ResourceReaderInterface::class);

        $this->handler = new ReadResourceHandler($this->resourceReader);
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

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertSame($expectedResult, $response->result);
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

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
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

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertCount(2, $response->result->contents);
    }

    public function testHandleResourceNotFoundExceptionReturnsSpecificError(): void
    {
        $uri = 'file://nonexistent/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceNotFoundException($request);

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willThrowException($exception);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals($request->getId(), $response->id);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals('Resource not found for uri: "'.$uri.'".', $response->message);
    }

    public function testHandleResourceReadExceptionReturnsGenericError(): void
    {
        $uri = 'file://corrupted/file.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceReadException(
            $request,
            new \RuntimeException('Failed to read resource: corrupted data'),
        );

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willThrowException($exception);

        $response = $this->handler->handle($request);

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

            $this->resourceReader
                ->expects($this->once())
                ->method('read')
                ->with($request)
                ->willReturn($expectedResult);

            $response = $this->handler->handle($request);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame($expectedResult, $response->result);

            // Reset the mock for next iteration
            $this->resourceReader = $this->createMock(ResourceReaderInterface::class);
            $this->handler = new ReadResourceHandler($this->resourceReader);
        }
    }

    public function testHandleResourceReadWithSpecialCharactersInUri(): void
    {
        $uri = 'file://path/with spaces/äöü-file-ñ.txt';
        $request = $this->createReadResourceRequest($uri);
        $expectedContent = new TextResourceContents(
            uri: $uri,
            mimeType: 'text/plain',
            text: 'Content with unicode characters: äöü ñ 世界 🚀',
        );
        $expectedResult = new ReadResourceResult([$expectedContent]);

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
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

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertInstanceOf(TextResourceContents::class, $response->result->contents[0]);
        $this->assertEquals('', $response->result->contents[0]->text);
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

            $this->resourceReader
                ->expects($this->once())
                ->method('read')
                ->with($request)
                ->willReturn($expectedResult);

            $response = $this->handler->handle($request);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame($expectedResult, $response->result);
            $this->assertEquals($mimeType, $response->result->contents[0]->mimeType);

            // Reset the mock for next iteration
            $this->resourceReader = $this->createMock(ResourceReaderInterface::class);
            $this->handler = new ReadResourceHandler($this->resourceReader);
        }
    }

    public function testHandleResourceNotFoundWithCustomMessage(): void
    {
        $uri = 'file://custom/missing.txt';
        $request = $this->createReadResourceRequest($uri);
        $exception = new ResourceNotFoundException($request);

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willThrowException($exception);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertEquals(Error::RESOURCE_NOT_FOUND, $response->code);
        $this->assertEquals('Resource not found for uri: "'.$uri.'".', $response->message);
    }

    public function testHandleResourceReadWithEmptyResult(): void
    {
        $uri = 'file://empty/resource';
        $request = $this->createReadResourceRequest($uri);
        $expectedResult = new ReadResourceResult([]);

        $this->resourceReader
            ->expects($this->once())
            ->method('read')
            ->with($request)
            ->willReturn($expectedResult);

        $response = $this->handler->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($expectedResult, $response->result);
        $this->assertCount(0, $response->result->contents);
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
