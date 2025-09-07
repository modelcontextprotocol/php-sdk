<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Resource;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Resource\DefaultResourceReader;
use Mcp\Exception\RegistryException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\ReadResourceResult;
use PHPUnit\Framework\TestCase;

class DefaultResourceReaderTest extends TestCase
{
    private DefaultResourceReader $resourceReader;
    private ReferenceProviderInterface $referenceProvider;
    private ReferenceHandlerInterface $referenceHandler;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->referenceHandler = $this->createMock(ReferenceHandlerInterface::class);

        $this->resourceReader = new DefaultResourceReader(
            $this->referenceProvider,
            $this->referenceHandler,
        );
    }

    public function testReadResourceSuccessfullyWithStringResult(): void
    {
        $request = new ReadResourceRequest('file://test.txt');
        $resource = $this->createValidResource('file://test.txt', 'test', 'text/plain');
        $resourceReference = new ResourceReference($resource, fn () => 'test content');
        $handlerResult = 'test content';

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('file://test.txt')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'file://test.txt'])
            ->willReturn($handlerResult);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertEquals('test content', $result->contents[0]->text);
        $this->assertEquals('file://test.txt', $result->contents[0]->uri);
        $this->assertEquals('text/plain', $result->contents[0]->mimeType);
    }

    public function testReadResourceSuccessfullyWithArrayResult(): void
    {
        $request = new ReadResourceRequest('api://data');
        $resource = $this->createValidResource('api://data', 'data', 'application/json');
        $resourceReference = new ResourceReference($resource, fn () => ['key' => 'value', 'count' => 42]);
        $handlerResult = ['key' => 'value', 'count' => 42];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('api://data')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'api://data'])
            ->willReturn($handlerResult);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertJsonStringEqualsJsonString(
            json_encode($handlerResult, \JSON_PRETTY_PRINT),
            $result->contents[0]->text,
        );
        $this->assertEquals('api://data', $result->contents[0]->uri);
        $this->assertEquals('application/json', $result->contents[0]->mimeType);
    }

    public function testReadResourceSuccessfullyWithBlobResult(): void
    {
        $request = new ReadResourceRequest('file://image.png');
        $resource = $this->createValidResource('file://image.png', 'image', 'image/png');

        $handlerResult = [
            'blob' => base64_encode('binary data'),
            'mimeType' => 'image/png',
        ];

        $resourceReference = new ResourceReference($resource, fn () => $handlerResult);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('file://image.png')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'file://image.png'])
            ->willReturn($handlerResult);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(BlobResourceContents::class, $result->contents[0]);
        $this->assertEquals(base64_encode('binary data'), $result->contents[0]->blob);
        $this->assertEquals('file://image.png', $result->contents[0]->uri);
        $this->assertEquals('image/png', $result->contents[0]->mimeType);
    }

    public function testReadResourceSuccessfullyWithResourceContentResult(): void
    {
        $request = new ReadResourceRequest('custom://resource');
        $resource = $this->createValidResource('custom://resource', 'resource', 'text/plain');
        $textContent = new TextResourceContents('custom://resource', 'text/plain', 'direct content');
        $resourceReference = new ResourceReference($resource, fn () => $textContent);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('custom://resource')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'custom://resource'])
            ->willReturn($textContent);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertSame($textContent, $result->contents[0]);
    }

    public function testReadResourceSuccessfullyWithMultipleContentResults(): void
    {
        $request = new ReadResourceRequest('multi://content');
        $resource = $this->createValidResource('multi://content', 'content', 'application/json');
        $content1 = new TextResourceContents('multi://content', 'text/plain', 'first content');
        $content2 = new TextResourceContents('multi://content', 'text/plain', 'second content');
        $handlerResult = [$content1, $content2];
        $resourceReference = new ResourceReference($resource, fn () => $handlerResult);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('multi://content')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'multi://content'])
            ->willReturn($handlerResult);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(2, $result->contents);
        $this->assertSame($content1, $result->contents[0]);
        $this->assertSame($content2, $result->contents[1]);
    }

    public function testReadResourceTemplate(): void
    {
        $request = new ReadResourceRequest('users://123');
        $resourceTemplate = $this->createValidResourceTemplate('users://{id}', 'user_template');
        $templateReference = new ResourceTemplateReference(
            $resourceTemplate,
            fn () => ['id' => 123, 'name' => 'Test User'],
        );

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('users://123')
            ->willReturn($templateReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($templateReference, ['uri' => 'users://123'])
            ->willReturn(['id' => 123, 'name' => 'Test User']);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['id' => 123, 'name' => 'Test User'], \JSON_PRETTY_PRINT),
            $result->contents[0]->text,
        );
    }

    public function testReadResourceThrowsExceptionWhenResourceNotFound(): void
    {
        $request = new ReadResourceRequest('nonexistent://resource');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('nonexistent://resource')
            ->willReturn(null);

        $this->referenceHandler
            ->expects($this->never())
            ->method('handle');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource "nonexistent://resource" is not registered.');

        $this->resourceReader->read($request);
    }

    public function testReadResourceThrowsRegistryExceptionWhenHandlerFails(): void
    {
        $request = new ReadResourceRequest('failing://resource');
        $resource = $this->createValidResource('failing://resource', 'failing', 'text/plain');
        $resourceReference = new ResourceReference($resource, fn () => throw new \RuntimeException('Handler failed'));
        $handlerException = new RegistryException('Handler execution failed');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('failing://resource')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'failing://resource'])
            ->willThrowException($handlerException);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Handler execution failed');

        $this->resourceReader->read($request);
    }

    public function testReadResourcePassesCorrectArgumentsToHandler(): void
    {
        $request = new ReadResourceRequest('test://resource');
        $resource = $this->createValidResource('test://resource', 'test', 'text/plain');
        $resourceReference = new ResourceReference($resource, fn () => 'test');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('test://resource')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with(
                $this->identicalTo($resourceReference),
                $this->equalTo(['uri' => 'test://resource']),
            )
            ->willReturn('test');

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
    }

    public function testReadResourceWithEmptyStringResult(): void
    {
        $request = new ReadResourceRequest('empty://resource');
        $resource = $this->createValidResource('empty://resource', 'empty', 'text/plain');
        $resourceReference = new ResourceReference($resource, fn () => '');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('empty://resource')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'empty://resource'])
            ->willReturn('');

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertEquals('', $result->contents[0]->text);
    }

    public function testReadResourceWithEmptyArrayResult(): void
    {
        $request = new ReadResourceRequest('empty://array');
        $resource = $this->createValidResource('empty://array', 'array', 'application/json');
        $resourceReference = new ResourceReference($resource, fn () => []);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('empty://array')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'empty://array'])
            ->willReturn([]);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertEquals('[]', $result->contents[0]->text);
        $this->assertEquals('application/json', $result->contents[0]->mimeType);
    }

    public function testReadResourceWithNullResult(): void
    {
        $request = new ReadResourceRequest('null://resource');
        $resource = $this->createValidResource('null://resource', 'null', 'text/plain');
        $resourceReference = new ResourceReference($resource, fn () => null);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('null://resource')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'null://resource'])
            ->willReturn(null);

        // The formatResult method in ResourceReference should handle null values
        $this->expectException(\RuntimeException::class);

        $this->resourceReader->read($request);
    }

    public function testReadResourceWithDifferentMimeTypes(): void
    {
        $request = new ReadResourceRequest('xml://data');
        $resource = $this->createValidResource('xml://data', 'data', 'application/xml');
        $resourceReference = new ResourceReference($resource, fn () => '<xml><data>value</data></xml>');

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('xml://data')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'xml://data'])
            ->willReturn('<xml><data>value</data></xml>');

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        // The MIME type should be guessed from content since formatResult handles the conversion
        $this->assertEquals('<xml><data>value</data></xml>', $result->contents[0]->text);
    }

    public function testReadResourceWithJsonMimeTypeAndArrayResult(): void
    {
        $request = new ReadResourceRequest('api://json');
        $resource = $this->createValidResource('api://json', 'json', 'application/json');
        $resourceReference = new ResourceReference($resource, fn () => ['formatted' => true, 'data' => [1, 2, 3]]);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('api://json')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'api://json'])
            ->willReturn(['formatted' => true, 'data' => [1, 2, 3]]);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertEquals('application/json', $result->contents[0]->mimeType);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['formatted' => true, 'data' => [1, 2, 3]], \JSON_PRETTY_PRINT),
            $result->contents[0]->text,
        );
    }

    public function testReadResourceCallsFormatResultOnReference(): void
    {
        $request = new ReadResourceRequest('format://test');
        $resource = $this->createValidResource('format://test', 'format', 'text/plain');

        // Create a mock ResourceReference to verify formatResult is called
        $resourceReference = $this
            ->getMockBuilder(ResourceReference::class)
            ->setConstructorArgs([$resource, fn () => 'test', false])
            ->onlyMethods(['formatResult'])
            ->getMock();

        $handlerResult = 'test result';
        $formattedResult = [new TextResourceContents('format://test', 'text/plain', 'formatted content')];

        $this->referenceProvider
            ->expects($this->once())
            ->method('getResource')
            ->with('format://test')
            ->willReturn($resourceReference);

        $this->referenceHandler
            ->expects($this->once())
            ->method('handle')
            ->with($resourceReference, ['uri' => 'format://test'])
            ->willReturn($handlerResult);

        $resourceReference
            ->expects($this->once())
            ->method('formatResult')
            ->with($handlerResult, 'format://test')
            ->willReturn($formattedResult);

        $result = $this->resourceReader->read($request);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertSame($formattedResult, $result->contents);
    }

    private function createValidResource(string $uri, string $name, ?string $mimeType = null): Resource
    {
        return new Resource(
            uri: $uri,
            name: $name,
            description: "Test resource: {$name}",
            mimeType: $mimeType,
            size: null,
            annotations: null,
        );
    }

    private function createValidResourceTemplate(
        string $uriTemplate,
        string $name,
        ?string $mimeType = null,
    ): ResourceTemplate {
        return new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: $name,
            description: "Test resource template: {$name}",
            mimeType: $mimeType,
            annotations: null,
        );
    }
}
