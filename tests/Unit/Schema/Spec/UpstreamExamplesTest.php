<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Spec;

use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\ContentBlockFactory;
use Mcp\Schema\Content\TextResourceContents;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips the specification's own examples through the generated classes and validates the output.
 */
final class UpstreamExamplesTest extends TestCase
{
    private const SPEC_DIR = __DIR__.'/../../../../tools/schema-generator/spec';
    private const SCHEMA_ID = 'https://modelcontextprotocol.io/schema/';

    /**
     * Generated types nested inside examples of types that are not generated (yet).
     */
    private const NESTED = [
        'CallToolResultResponse' => ['result' => 'CallToolResult'],
        'GetPromptResult' => ['messages[].content' => 'ContentBlock'],
        'GetPromptResultResponse' => ['result.messages[].content' => 'ContentBlock'],
        'ListToolsResult' => ['tools[]' => 'Tool'],
        'ListToolsResultResponse' => ['result.tools[]' => 'Tool'],
        'ReadResourceResult' => ['contents[]' => 'ResourceContents'],
        'ReadResourceResultResponse' => ['result.contents[]' => 'ResourceContents'],
    ];

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function provideExamples(): iterable
    {
        $config = self::config();
        $latest = $config['revisions'][\count($config['revisions']) - 1];

        foreach (glob(self::SPEC_DIR.'/'.$latest.'/examples/*/*.json') ?: [] as $file) {
            $exampleType = basename(\dirname($file));
            $paths = isset($config['types'][$exampleType]) ? ['' => $exampleType] : (self::NESTED[$exampleType] ?? []);
            $example = json_decode((string) file_get_contents($file), false, flags: \JSON_THROW_ON_ERROR);

            foreach ($paths as $path => $type) {
                foreach (self::extract($example, '' === $path ? [] : explode('.', $path)) as $index => $value) {
                    yield \sprintf('%s/%s %s#%d', $exampleType, basename($file), $type, $index) => [$type, $value];
                }
            }
        }
    }

    #[DataProvider('provideExamples')]
    public function testRoundTripsExample(string $type, mixed $example): void
    {
        $expected = $this->withoutOmitted($type, $example);

        $actual = json_decode(json_encode($this->decode($type, $example), \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        // A default the class always sends is not a difference when the example leaves it out.
        foreach (self::config()['defaults'] as $path => $default) {
            [$owner, $field] = explode('.', $path, 2);
            if ($owner === $type && !isset($example->{$field}) && ($actual->{$field} ?? null) === $default) {
                unset($actual->{$field});
            }
        }

        $this->assertEquals($expected, $actual);
    }

    #[DataProvider('provideExamples')]
    public function testEncodesValidData(string $type, mixed $example): void
    {
        $config = self::config();
        $latest = $config['revisions'][\count($config['revisions']) - 1];

        $encoded = json_decode(json_encode($this->decode($type, $example), \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        // Omitted fields belong to the wire codecs, so they are added back the way a codec would.
        foreach ($this->omittedFields($type) as $field) {
            if (isset($example->{$field})) {
                $encoded->{$field} = $example->{$field};
            }
        }

        $validator = new Validator();
        $validator->resolver()?->registerFile(self::SCHEMA_ID.$latest, self::SPEC_DIR.'/'.$latest.'/schema.json');
        $definition = 'ResourceContents' === $type ? (isset($example->text) ? 'TextResourceContents' : 'BlobResourceContents') : $type;

        $result = $validator->validate($encoded, (object) ['$ref' => self::SCHEMA_ID.$latest.'#/$defs/'.$definition]);

        $error = $result->error();
        $this->assertNull($error, null === $error ? '' : (string) json_encode((new ErrorFormatter())->format($error)));
    }

    private function decode(string $type, mixed $example): \JsonSerializable
    {
        $data = json_decode(json_encode($example, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        if ('ContentBlock' === $type) {
            return ContentBlockFactory::fromArray($data);
        }
        if ('ResourceContents' === $type) {
            return isset($data['text']) ? TextResourceContents::fromArray($data) : BlobResourceContents::fromArray($data);
        }

        $class = self::config()['namespace'].'\\'.self::config()['types'][$type];
        $this->assertTrue(method_exists($class, 'fromArray'));

        return $class::fromArray($data);
    }

    private function withoutOmitted(string $type, mixed $example): mixed
    {
        $expected = json_decode(json_encode($example, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        foreach ($this->omittedFields($type) as $field) {
            unset($expected->{$field});
        }

        return $expected;
    }

    /**
     * @return list<string>
     */
    private function omittedFields(string $type): array
    {
        $fields = [];
        foreach (self::config()['omit'] as $path) {
            [$owner, $field] = explode('.', $path, 2);
            if ($owner === $type) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $segments
     *
     * @return list<mixed>
     */
    private static function extract(mixed $value, array $segments): array
    {
        if ([] === $segments) {
            return [$value];
        }

        $segment = array_shift($segments);
        $isList = str_ends_with($segment, '[]');
        $key = $isList ? substr($segment, 0, -2) : $segment;
        if (!$value instanceof \stdClass || !isset($value->{$key})) {
            return [];
        }

        $children = $isList ? (array) $value->{$key} : [$value->{$key}];
        $values = [];
        foreach ($children as $child) {
            $values = [...$values, ...self::extract($child, $segments)];
        }

        return $values;
    }

    /**
     * @return array{revisions: list<string>, namespace: string, types: array<string, string>, omit: list<string>, defaults: array<string, scalar>}
     */
    private static function config(): array
    {
        return require __DIR__.'/../../../../tools/schema-generator/config.php';
    }
}
