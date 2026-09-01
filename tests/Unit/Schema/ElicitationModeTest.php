<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Request\ElicitRequest;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ElicitationModeTest extends TestCase
{
    #[TestDox('form elicitation omits the mode discriminator for older clients')]
    public function testFormOmitsMode(): void
    {
        $request = ElicitRequest::forForm('Your name?', $this->createSchema());

        $params = $this->paramsOf($request);

        $this->assertArrayNotHasKey('mode', $params);
        $this->assertArrayHasKey('requestedSchema', $params);
        $this->assertSame(ElicitationMode::Form, $request->mode);
    }

    #[TestDox('url elicitation carries mode and url, and no schema')]
    public function testUrlCarriesModeAndUrl(): void
    {
        $request = ElicitRequest::forUrl('Finish setup', 'https://example.com/setup');

        $params = $this->paramsOf($request);

        $this->assertSame('url', $params['mode']);
        $this->assertSame('https://example.com/setup', $params['url']);
        $this->assertArrayNotHasKey('requestedSchema', $params);
        $this->assertNull($request->requestedSchema);
    }

    #[TestDox('a request without mode is still read as form elicitation')]
    public function testAbsentModeDefaultsToForm(): void
    {
        $request = $this->requestFromParams([
            'message' => 'Your name?',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'title' => 'Name']],
            ],
        ]);

        $this->assertSame(ElicitationMode::Form, $request->mode);
        $this->assertNotNull($request->requestedSchema);
    }

    #[TestDox('parses an explicit url-mode request')]
    public function testParsesUrlMode(): void
    {
        $request = $this->requestFromParams([
            'message' => 'Finish setup',
            'mode' => 'url',
            'url' => 'https://example.com/setup',
        ]);

        $this->assertSame(ElicitationMode::Url, $request->mode);
        $this->assertSame('https://example.com/setup', $request->url);
    }

    #[TestDox('url mode without a url is rejected')]
    public function testUrlModeRequiresUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->requestFromParams(['message' => 'Finish setup', 'mode' => 'url']);
    }

    #[TestDox('form mode without a schema is rejected')]
    public function testFormModeRequiresSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->requestFromParams(['message' => 'Your name?']);
    }

    #[TestDox('an unknown mode is rejected rather than silently treated as a form')]
    public function testUnknownModeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->requestFromParams(['message' => 'hi', 'mode' => 'telepathy']);
    }

    #[TestDox('an explicit null mode is rejected rather than defaulting to form')]
    public function testNullModeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "mode" parameter');

        $this->requestFromParams([
            'message' => 'Your name?',
            'mode' => null,
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'title' => 'Name']],
            ],
        ]);
    }

    private function createSchema(): ElicitationSchema
    {
        return new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requestFromParams(array $params): ElicitRequest
    {
        return ElicitRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 'request-1',
            'method' => ElicitRequest::getMethod(),
            'params' => $params,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paramsOf(ElicitRequest $request): array
    {
        /** @var array{params?: array<string, mixed>} $encoded */
        $encoded = json_decode(json_encode($request->withId('request-1')) ?: '', true);

        return $encoded['params'] ?? [];
    }
}
