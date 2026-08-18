<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\SchemaComplexityGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class SchemaComplexityGuardTest extends TestCase
{
    private SchemaComplexityGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SchemaComplexityGuard();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function ordinarySchemas(): iterable
    {
        yield 'empty' => [[]];
        yield 'flat object' => [[
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
            'required' => ['a'],
        ]];
        yield 'nested objects' => [[
            'type' => 'object',
            'properties' => ['outer' => ['type' => 'object', 'properties' => ['inner' => ['type' => 'string']]]],
        ]];
        yield 'array with items' => [['type' => 'array', 'items' => ['type' => 'string']]];
        yield 'modest composition' => [[
            'type' => 'object',
            'properties' => ['v' => ['anyOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]]],
        ]];
        yield 'local $ref through $defs' => [[
            '$defs' => ['name' => ['type' => 'string', 'minLength' => 1]],
            'type' => 'object',
            'properties' => ['first' => ['$ref' => '#/$defs/name'], 'last' => ['$ref' => '#/$defs/name']],
        ]];
        yield 'if/then/else' => [[
            'type' => 'object',
            'if' => ['properties' => ['kind' => ['const' => 'a']]],
            'then' => ['required' => ['x']],
            'else' => ['required' => ['y']],
        ]];
        yield 'a property literally named $ref' => [[
            'type' => 'object',
            'properties' => ['$ref' => ['type' => 'string']],
        ]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('ordinarySchemas')]
    #[TestDox('an ordinary schema passes untouched')]
    public function testOrdinarySchemasPass(array $schema): void
    {
        $this->assertNull($this->guard->check($schema));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function externalRefs(): iterable
    {
        yield 'https' => ['https://evil.example/schema.json'];
        yield 'http' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'relative document' => ['common.json#/$defs/name'];
        yield 'protocol-relative' => ['//evil.example/schema.json'];
    }

    #[DataProvider('externalRefs')]
    #[TestDox('a reference outside the document is refused, and nothing is fetched')]
    public function testExternalRefIsRefused(string $ref): void
    {
        $reason = $this->guard->check([
            'type' => 'object',
            'properties' => ['a' => ['$ref' => $ref]],
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('non-local reference', $reason);
        $this->assertStringContainsString($ref, $reason);
    }

    #[TestDox('a same-document reference is not mistaken for an external one')]
    public function testLocalRefIsAllowed(): void
    {
        $this->assertNull($this->guard->check([
            '$defs' => ['n' => ['type' => 'integer']],
            '$ref' => '#/$defs/n',
        ]));
    }

    #[TestDox('nesting past the depth ceiling is refused')]
    public function testExcessiveDepthIsRefused(): void
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < 60; ++$i) {
            $schema = ['type' => 'object', 'properties' => ['n' => $schema]];
        }

        $this->assertStringContainsString('nests deeper', (string) $this->guard->check($schema));
    }

    #[TestDox('an expanded composition bomb is refused')]
    public function testExpandedCompositionBombIsRefused(): void
    {
        // Fourteen levels: 2^14 branches, but only 28 levels of nesting, so it
        // is the subschema budget and not the depth ceiling that refuses it.
        $branch = ['type' => 'string'];
        for ($i = 0; $i < 14; ++$i) {
            $branch = ['anyOf' => [$branch, $branch]];
        }

        $this->assertStringContainsString('subschemas', (string) $this->guard->check($branch));
    }

    #[TestDox('the same bomb written with $defs — a few hundred bytes — is refused too')]
    public function testRefCompressedCompositionBombIsRefused(): void
    {
        // Each level doubles by referencing the level below twice. Linear on the
        // wire, exponential to walk: this is the shape a size cap cannot catch.
        $defs = ['a0' => ['type' => 'string']];
        for ($i = 1; $i <= 20; ++$i) {
            $defs['a'.$i] = ['anyOf' => [['$ref' => '#/$defs/a'.($i - 1)], ['$ref' => '#/$defs/a'.($i - 1)]]];
        }

        $schema = ['$defs' => $defs, '$ref' => '#/$defs/a20'];

        $this->assertLessThan(2048, \strlen((string) json_encode($schema)));
        $this->assertStringContainsString('subschemas', (string) $this->guard->check($schema));
    }

    #[TestDox('a long chain of local references is flat, not deep')]
    public function testLongLocalRefChainIsAllowed(): void
    {
        // Following a reference is not nesting: this is 60 links and costs 60
        // steps, which a depth ceiling applied to resolution would refuse.
        $defs = ['a0' => ['type' => 'string']];
        for ($i = 1; $i <= 60; ++$i) {
            $defs['a'.$i] = ['$ref' => '#/$defs/a'.($i - 1)];
        }

        $this->assertNull($this->guard->check(['$defs' => $defs, '$ref' => '#/$defs/a60']));
    }

    #[TestDox('a recursive schema is allowed: how far it unrolls is the data\'s doing')]
    public function testRecursiveSchemaIsAllowed(): void
    {
        $this->assertNull($this->guard->check([
            '$defs' => [
                'node' => [
                    'type' => 'object',
                    'properties' => [
                        'value' => ['type' => 'string'],
                        'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']],
                    ],
                ],
            ],
            '$ref' => '#/$defs/node',
        ]));
    }

    #[TestDox('an oversized property map is refused')]
    public function testOversizedPropertyMapIsRefused(): void
    {
        $properties = [];
        for ($i = 0; $i < 1_500; ++$i) {
            $properties['p'.$i] = ['type' => 'string'];
        }

        $this->assertStringContainsString('entries under "properties"', (string) $this->guard->check([
            'type' => 'object',
            'properties' => $properties,
        ]));
    }

    #[TestDox('an unresolvable local pointer is left for the validator to report')]
    public function testUnresolvableLocalPointerPasses(): void
    {
        $this->assertNull($this->guard->check(['$ref' => '#/$defs/missing']));
    }

    #[TestDox('the bounds are configurable')]
    public function testBoundsAreConfigurable(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]],
        ];

        $this->assertNull((new SchemaComplexityGuard())->check($schema));
        $this->assertStringContainsString('nests deeper', (string) (new SchemaComplexityGuard(maxDepth: 1))->check($schema));
        $this->assertStringContainsString('subschemas', (string) (new SchemaComplexityGuard(maxSubschemas: 2))->check($schema));
    }

    #[TestDox('an object schema is accepted as well as an array one')]
    public function testObjectSchemaIsAccepted(): void
    {
        $schema = json_decode('{"type":"object","properties":{"a":{"$ref":"https://evil.example/s.json"}}}');

        $this->assertStringContainsString('non-local reference', (string) $this->guard->check($schema));
    }
}
