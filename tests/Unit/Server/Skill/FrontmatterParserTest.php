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
}
