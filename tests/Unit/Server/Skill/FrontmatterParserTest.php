<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Skill;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Skill\FrontmatterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrontmatterParserTest extends TestCase
{
    public function testParsesFrontmatterAndBody(): void
    {
        $content = "---\nname: code-review\ndescription: Review a PR.\n---\n\n# Heading\n\nBody text.";

        [$frontmatter, $body] = (new FrontmatterParser())->parse($content);

        $this->assertSame(['name' => 'code-review', 'description' => 'Review a PR.'], $frontmatter);
        $this->assertSame("\n# Heading\n\nBody text.", $body);
    }

    public function testDocumentWithoutFrontmatterHasEmptyFrontmatter(): void
    {
        $content = "# Just markdown\n\nNo frontmatter here.";

        [$frontmatter, $body] = (new FrontmatterParser())->parse($content);

        $this->assertSame([], $frontmatter);
        $this->assertSame($content, $body);
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $content = "---\r\nname: refunds\r\n---\r\n\r\n# Refunds\r\n";

        [$frontmatter] = (new FrontmatterParser())->parse($content);

        $this->assertSame('refunds', $frontmatter['name']);
    }

    public function testHandlesByteOrderMark(): void
    {
        $content = "\xEF\xBB\xBF---\nname: refunds\n---\n\nBody.";

        [$frontmatter] = (new FrontmatterParser())->parse($content);

        $this->assertSame('refunds', $frontmatter['name']);
    }

    public function testParsesListsAndMultilineValues(): void
    {
        $content = "---\nname: code-review\ntags:\n  - review\n  - quality\n---\nbody";

        [$frontmatter] = (new FrontmatterParser())->parse($content);

        $this->assertSame(['review', 'quality'], $frontmatter['tags']);
    }

    public function testThrowsWhenFrontmatterIsNotAMapping(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YAML mapping');

        (new FrontmatterParser())->parse("---\n- just\n- a\n- list\n---\nbody");
    }

    public function testParseMetadataReturnsValueObject(): void
    {
        $metadata = (new FrontmatterParser())->parseMetadata("---\nname: refunds\ndescription: Process refunds.\n---\nbody");

        $this->assertSame('refunds', $metadata->name);
        $this->assertSame('Process refunds.', $metadata->description);
    }

    public function testParseMetadataThrowsWhenNameMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FrontmatterParser())->parseMetadata("---\ndescription: no name\n---\nbody");
    }

    public function testKeepsEmptyAndNestedMappingsAsObjects(): void
    {
        $content = "---\nname: a\ndescription: b\nmetadata: {}\nnested:\n  inner:\n    key: value\n---\nbody";

        [$frontmatter] = (new FrontmatterParser())->parse($content);

        $this->assertSame(
            '{"name":"a","description":"b","metadata":{},"nested":{"inner":{"key":"value"}}}',
            json_encode($frontmatter, \JSON_THROW_ON_ERROR),
        );
    }

    public function testKeepsListsAsLists(): void
    {
        [$frontmatter] = (new FrontmatterParser())->parse("---\nname: a\ntags: [x, y]\nempty: []\n---\n");

        $this->assertSame('{"name":"a","tags":["x","y"],"empty":[]}', json_encode($frontmatter, \JSON_THROW_ON_ERROR));
    }

    public function testMetadataSerializesEmptyMappingsAsObjects(): void
    {
        $metadata = (new FrontmatterParser())->parseMetadata("---\nname: a\ndescription: b\nmetadata: {}\n---\n");

        $this->assertSame('{"name":"a","description":"b","metadata":{}}', json_encode($metadata, \JSON_THROW_ON_ERROR));
    }

    public function testRejectsUnquotedYamlDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"released" is a YAML date');

        (new FrontmatterParser())->parse("---\nname: a\nreleased: 2026-01-01\n---\n");
    }

    public function testRejectsYamlDatesInNestedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"metadata.released" is a YAML date');

        (new FrontmatterParser())->parse("---\nname: a\nmetadata:\n  released: 2026-01-01\n---\n");
    }

    public function testAcceptsQuotedDates(): void
    {
        [$frontmatter] = (new FrontmatterParser())->parse("---\nname: a\nreleased: \"2026-01-01\"\n---\n");

        $this->assertSame('2026-01-01', $frontmatter['released']);
    }

    public function testKeepsIntegerKeysWhenSerializingMetadata(): void
    {
        $metadata = (new FrontmatterParser())->parseMetadata("---\nname: a\ndescription: b\n2024: first\n---\n");

        $this->assertSame('{"name":"a","description":"b","2024":"first"}', json_encode($metadata, \JSON_THROW_ON_ERROR));
    }

    public function testRejectsValuesWithoutAJsonForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be sent as JSON');

        (new FrontmatterParser())->parse("---\nname: a\nratio: .inf\n---\n");
    }

    public function testRejectsBinaryValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be sent as JSON');

        (new FrontmatterParser())->parse("---\nname: a\nblob: !!binary /w==\n---\n");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedTags(): iterable
    {
        yield 'object' => ['!php/object "O:8:\"stdClass\":0:{}"'];
        yield 'const' => ['!php/const PHP_INT_MAX'];
        yield 'enum' => ['!php/enum Foo::Bar'];
    }

    #[DataProvider('provideUnsupportedTags')]
    public function testRejectsPhpTagsInsteadOfSilentlyDroppingThem(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid YAML');

        (new FrontmatterParser())->parse("---\nname: a\nvalue: {$value}\n---\n");
    }

    public function testMalformedYamlNamesTheProblem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid YAML');

        (new FrontmatterParser())->parse("---\nname: [unclosed\n---\n");
    }

    public function testParsesALargeFrontmatterBlock(): void
    {
        $content = "---\nname: big\ndescription: Large.\nnotes: \"".str_repeat('x ', 600_000)."\"\n---\nbody";

        [$frontmatter, $body] = (new FrontmatterParser())->parse($content);

        $this->assertSame('big', $frontmatter['name']);
        $this->assertSame('body', $body);
    }

    public function testClosingFenceMustBeAloneOnItsLine(): void
    {
        $content = "---\nname: a\n---extra\nbody";

        [$frontmatter, $body] = (new FrontmatterParser())->parse($content);

        $this->assertSame([], $frontmatter, '`---extra` does not close the block, so there is no frontmatter.');
        $this->assertSame($content, $body);
    }

    public function testAHorizontalRuleInTheBodyIsNotAFence(): void
    {
        [$frontmatter, $body] = (new FrontmatterParser())->parse("---\nname: a\n---\nfirst\n\n---\n\nsecond");

        $this->assertSame(['name' => 'a'], $frontmatter);
        $this->assertSame("first\n\n---\n\nsecond", $body);
    }

    public function testHasFrontmatter(): void
    {
        $parser = new FrontmatterParser();

        $this->assertTrue($parser->hasFrontmatter("---\nname: a\n---\nbody"));
        $this->assertTrue($parser->hasFrontmatter("\xEF\xBB\xBF---\r\nname: a\r\n---\r\nbody"));
        $this->assertFalse($parser->hasFrontmatter("# Just a template\n---\nnot a fence"));
        $this->assertFalse($parser->hasFrontmatter("---\nnever closed"));
    }
}
