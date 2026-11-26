<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Discovery;

use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Capability\Discovery\DocBlockParser;
use Mcp\Server\Capability\Discovery\SchemaGenerator;
use Mcp\Server\Exception\InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @author bbdu3
 */
final class SchemaGeneratorExclusiveBoundsTest extends TestCase
{
    /**
     * @param array<string, mixed> $bounds
     * @param list<int|float>      $valid
     * @param list<int|float>      $invalid
     */
    #[DataProvider('bounds')]
    public function testGeneratedBoundsValidateStrictly(\Closure $handler, array $bounds, array $valid, array $invalid): void
    {
        $schema = $this->generate(new \ReflectionFunction($handler));

        $this->assertSame(['type' => 'number', ...$bounds], $schema['properties']['n']);

        $validator = new Validator();
        $validator->parser()->setOption('allowExclusiveMinMaxAsBool', false);
        $decodedSchema = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);

        foreach ($valid as $n) {
            $this->assertTrue($validator->validate((object) ['n' => $n], $decodedSchema)->isValid(), (string) $n);
        }
        foreach ($invalid as $n) {
            $this->assertFalse($validator->validate((object) ['n' => $n], $decodedSchema)->isValid(), (string) $n);
        }
    }

    public static function bounds(): iterable
    {
        yield 'numeric zero lower bound' => [
            static fn (#[Schema(exclusiveMinimum: 0)] float $n): float => $n,
            ['exclusiveMinimum' => 0], [0.5, 1], [-1, 0],
        ];
        yield 'numeric zero upper bound' => [
            static fn (#[Schema(exclusiveMaximum: 0)] float $n): float => $n,
            ['exclusiveMaximum' => 0], [-1, -0.5], [0, 1],
        ];
        yield 'fractional bounds' => [
            static fn (#[Schema(exclusiveMinimum: -1.5, exclusiveMaximum: 2.5)] float $n): float => $n,
            ['exclusiveMinimum' => -1.5, 'exclusiveMaximum' => 2.5], [-1, 0, 2], [-1.5, 2.5],
        ];
        yield 'floating zero' => [
            static fn (#[Schema(exclusiveMinimum: 0.0)] float $n): float => $n,
            ['exclusiveMinimum' => 0.0], [0.5], [0],
        ];
        yield 'legacy true' => [
            static fn (#[Schema(minimum: 0, maximum: 5, exclusiveMinimum: true, exclusiveMaximum: true)] float $n): float => $n,
            ['minimum' => 0, 'maximum' => 5, 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 5], [0.5, 4], [0, 5],
        ];
        yield 'legacy fractional bounds' => [
            static fn (#[Schema(minimum: -1.5, maximum: 2.5, exclusiveMinimum: true, exclusiveMaximum: true)] float $n): float => $n,
            ['minimum' => -1.5, 'maximum' => 2.5, 'exclusiveMinimum' => -1.5, 'exclusiveMaximum' => 2.5], [-1, 2], [-1.5, 2.5],
        ];
        yield 'legacy false keeps inclusive bounds' => [
            static fn (#[Schema(minimum: 0, maximum: 5, exclusiveMinimum: false, exclusiveMaximum: false)] float $n): float => $n,
            ['minimum' => 0, 'maximum' => 5], [0, 5], [-1, 6],
        ];
        yield 'false without bounds' => [
            static fn (#[Schema(exclusiveMinimum: false, exclusiveMaximum: false)] float $n): float => $n,
            [], [-1, 0, 1], [],
        ];
        yield 'null without bounds' => [
            static fn (#[Schema(exclusiveMinimum: null, exclusiveMaximum: null)] float $n): float => $n,
            [], [-1, 0, 1], [],
        ];
        yield 'independent numeric bounds' => [
            static fn (#[Schema(minimum: 2, maximum: 8, exclusiveMinimum: 0, exclusiveMaximum: 10)] float $n): float => $n,
            ['minimum' => 2, 'maximum' => 8, 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 10], [2, 8], [1, 9],
        ];
    }

    public function testBoundsAreResolvedAfterMerging(): void
    {
        $handler = new class {
            #[Schema(properties: ['n' => ['minimum' => 0, 'maximum' => 5]])]
            public function inherit(#[Schema(exclusiveMinimum: true, exclusiveMaximum: true)] float $n): void
            {
            }

            #[Schema(properties: ['n' => ['minimum' => 0, 'maximum' => 5, 'exclusiveMinimum' => true, 'exclusiveMaximum' => true]])]
            public function override(#[Schema(minimum: -2, maximum: 10)] float $n): void
            {
            }

            #[Schema(properties: ['n' => ['minimum' => 0, 'maximum' => 5, 'exclusiveMinimum' => 1, 'exclusiveMaximum' => 4]])]
            public function inclusive(#[Schema(exclusiveMinimum: false, exclusiveMaximum: false)] float $n): void
            {
            }
        };

        $inherited = $this->generate(new \ReflectionMethod($handler, 'inherit'));
        $overridden = $this->generate(new \ReflectionMethod($handler, 'override'));
        $inclusive = $this->generate(new \ReflectionMethod($handler, 'inclusive'));

        $this->assertSame(['type' => 'number', 'minimum' => 0, 'maximum' => 5, 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 5], $inherited['properties']['n']);
        $this->assertSame(['type' => 'number', 'minimum' => -2, 'maximum' => 10, 'exclusiveMinimum' => -2, 'exclusiveMaximum' => 10], $overridden['properties']['n']);
        $this->assertSame(['type' => 'number', 'minimum' => 0, 'maximum' => 5], $inclusive['properties']['n']);
    }

    #[DataProvider('missingBounds')]
    public function testTrueRequiresACorrespondingBound(\Closure $handler, string $exclusive, string $bound): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Schema keyword "%s" set to true requires a numeric "%s".', $exclusive, $bound));

        $this->generate(new \ReflectionFunction($handler));
    }

    public static function missingBounds(): iterable
    {
        yield 'minimum' => [static fn (#[Schema(exclusiveMinimum: true)] float $n): float => $n, 'exclusiveMinimum', 'minimum'];
        yield 'maximum' => [static fn (#[Schema(exclusiveMaximum: true)] float $n): float => $n, 'exclusiveMaximum', 'maximum'];
    }

    public function testMethodLevelAndVariadicSettingsAreNormalized(): void
    {
        $handler = new class {
            #[Schema(minimum: 0, exclusiveMinimum: true, exclusiveMaximum: false)]
            public function handle(#[Schema(maximum: 5, exclusiveMaximum: true)] float ...$n): void
            {
            }
        };

        $schema = $this->generate(new \ReflectionMethod($handler, 'handle'));

        $this->assertSame(0, $schema['exclusiveMinimum']);
        $this->assertArrayNotHasKey('exclusiveMaximum', $schema);
        $this->assertSame(5, $schema['properties']['n']['exclusiveMaximum']);
        $this->assertSame(['type' => 'number'], $schema['properties']['n']['items']);
    }

    public function testCompleteDefinitionsAreNotRewritten(): void
    {
        $handler = new class {
            #[Schema(definition: ['type' => 'object', 'exclusiveMinimum' => true])]
            public function method(): void
            {
            }

            #[Schema(properties: ['n' => ['minimum' => 0, 'exclusiveMinimum' => true]])]
            public function parameter(#[Schema(definition: ['type' => 'number', 'exclusiveMinimum' => false])] float $n = 1): void
            {
            }

            public function variadic(#[Schema(definition: ['type' => 'array', 'exclusiveMaximum' => true])] float ...$n): void
            {
            }
        };

        $this->assertSame(['type' => 'object', 'exclusiveMinimum' => true], $this->generate(new \ReflectionMethod($handler, 'method')));
        $this->assertSame(['type' => 'number', 'exclusiveMinimum' => false, 'default' => 1.0], $this->generate(new \ReflectionMethod($handler, 'parameter'))['properties']['n']);
        $this->assertTrue($this->generate(new \ReflectionMethod($handler, 'variadic'))['properties']['n']['exclusiveMaximum']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(\ReflectionFunctionAbstract $reflection): array
    {
        return (new SchemaGenerator(new DocBlockParser()))->generate($reflection);
    }
}
