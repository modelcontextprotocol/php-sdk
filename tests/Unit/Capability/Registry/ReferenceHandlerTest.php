<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Metadata;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReferenceHandlerTest extends TestCase
{
    private ReferenceHandler $handler;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        $this->handler = new ReferenceHandler();
        $this->session = $this->createMock(SessionInterface::class);
    }

    public function testInjectsMetadataIntoTypedParameter(): void
    {
        $fn = function (Metadata $meta): string {
            return (string) $meta->get('securitySchema');
        };

        $ref = new ElementReference($fn);

        $result = $this->handler->handle($ref, [
            '_session' => $this->session,
            '_meta' => ['securitySchema' => 'secure-123'],
        ]);

        $this->assertSame('secure-123', $result);
    }

    public function testNullableMetadataReceivesNullWhenNotProvided(): void
    {
        $fn = function (?Metadata $meta): string {
            return null === $meta ? 'no-meta' : 'has-meta';
        };

        $ref = new ElementReference($fn);

        $result = $this->handler->handle($ref, [
            '_session' => $this->session,
        ]);

        $this->assertSame('no-meta', $result);
    }

    public function testRequiredMetadataThrowsInternalErrorWhenNotProvided(): void
    {
        $fn = function (Metadata $meta): array {
            return $meta->all();
        };

        $ref = new ElementReference($fn);

        $this->expectException(\Mcp\Exception\RegistryException::class);
        $this->expectExceptionMessage('Missing required request metadata');

        $this->handler->handle($ref, [
            '_session' => $this->session,
        ]);
    }
}
