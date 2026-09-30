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
}
