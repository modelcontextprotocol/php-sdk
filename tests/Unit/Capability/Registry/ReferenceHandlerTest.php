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

use Mcp\Capability\Discovery\PropertyDescriber\UuidPropertyDescriber;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\RegistryException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

final class ReferenceHandlerTest extends TestCase
{
    public function testUpcastsStringArgumentIntoClassInstanceViaDenormalizer(): void
    {
        $handler = new ReferenceHandler(null, [new UuidPropertyDescriber()]);
        $reference = new ElementReference(static fn (Uuid $id): string => $id->toRfc4122());

        $result = $handler->handle($reference, [
            'id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            '_session' => $this->createStub(SessionInterface::class),
        ]);

        $this->assertSame('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $result);
    }

    public function testPassesThroughArgumentAlreadyOfTargetType(): void
    {
        $handler = new ReferenceHandler(null, [new UuidPropertyDescriber()]);
        $uuid = Uuid::v4();
        $reference = new ElementReference(static fn (Uuid $id): Uuid => $id);

        $result = $handler->handle($reference, [
            'id' => $uuid,
            '_session' => $this->createStub(SessionInterface::class),
        ]);

        $this->assertSame($uuid, $result);
    }

    public function testMalformedValueForDenormalizedTypeMapsToInvalidParams(): void
    {
        $handler = new ReferenceHandler(null, [new UuidPropertyDescriber()]);
        $reference = new ElementReference(static fn (Uuid $id): string => $id->toRfc4122());

        try {
            $handler->handle($reference, [
                'id' => 'not-a-uuid',
                '_session' => $this->createStub(SessionInterface::class),
            ]);
            $this->fail('Expected a RegistryException');
        } catch (RegistryException $e) {
            $this->assertSame(Error::INVALID_PARAMS, $e->getCode());
        }
    }

    public function testDenormalizedSubtypeMismatchMapsToInvalidParams(): void
    {
        // A v7 UUID string denormalizes to a UuidV7, which is not a UuidV4; this
        // must surface as invalid params, not a TypeError reported as an internal error.
        $handler = new ReferenceHandler(null, [new UuidPropertyDescriber()]);
        $reference = new ElementReference(static fn (UuidV4 $id): string => (string) $id);

        try {
            $handler->handle($reference, [
                'id' => (string) Uuid::v7(),
                '_session' => $this->createStub(SessionInterface::class),
            ]);
            $this->fail('Expected a RegistryException');
        } catch (RegistryException $e) {
            $this->assertSame(Error::INVALID_PARAMS, $e->getCode());
        }
    }

    public function testBuiltinCastingIsUnaffectedWhenNoHandlerRegistered(): void
    {
        $handler = new ReferenceHandler();
        $reference = new ElementReference(static fn (int $n): int => $n);

        $result = $handler->handle($reference, [
            'n' => '42',
            '_session' => $this->createStub(SessionInterface::class),
        ]);

        $this->assertSame(42, $result);
    }
}
