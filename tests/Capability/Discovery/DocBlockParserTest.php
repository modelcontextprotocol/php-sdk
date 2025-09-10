<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Discovery;

use ReflectionMethod;
use Mcp\Capability\Discovery\DocBlockParser;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use PHPUnit\Framework\TestCase;

class DocBlockParserTest extends TestCase
{
    private DocBlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DocBlockParser();
    }

    public function testGetSummaryReturnsCorrectSummary(): void
    {
        $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryOnly');
        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->parser->parseDocBlock($docComment);
        $this->assertEquals('Simple summary line.', $this->parser->getSummary($docBlock));

        $method2 = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryAndDescription');
        $docComment2 = $method2->getDocComment() ?: null;
        $docBlock2 = $this->parser->parseDocBlock($docComment2);
        $this->assertEquals('Summary line here.', $this->parser->getSummary($docBlock2));
    }

    public function testGetDescriptionReturnsCorrectDescription(): void
    {
        $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryAndDescription');
        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->parser->parseDocBlock($docComment);
        $expectedDesc = "Summary line here.\n\nThis is a longer description spanning\nmultiple lines.\nIt might contain *markdown* or `code`.";
        $this->assertEquals($expectedDesc, $this->parser->getDescription($docBlock));

        $method2 = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryOnly');
        $docComment2 = $method2->getDocComment() ?: null;
        $docBlock2 = $this->parser->parseDocBlock($docComment2);
        $this->assertEquals('Simple summary line.', $this->parser->getDescription($docBlock2));
    }

    public function testGetParamTagsReturnsStructuredParamInfo(): void
    {
        $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithParams');
        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->parser->parseDocBlock($docComment);
        $params = $this->parser->getParamTags($docBlock);

        $this->assertCount(6, $params);
        $this->assertArrayHasKey('$param1', $params);
        $this->assertArrayHasKey('$param2', $params);
        $this->assertArrayHasKey('$param3', $params);
        $this->assertArrayHasKey('$param4', $params);
        $this->assertArrayHasKey('$param5', $params);
        $this->assertArrayHasKey('$param6', $params);

        $this->assertInstanceOf(Param::class, $params['$param1']);
        $this->assertEquals('param1', $params['$param1']->getVariableName());
        $this->assertEquals('string', $this->parser->getParamTypeString($params['$param1']));
        $this->assertEquals('description for string param', $this->parser->getParamDescription($params['$param1']));

        $this->assertInstanceOf(Param::class, $params['$param2']);
        $this->assertEquals('param2', $params['$param2']->getVariableName());
        $this->assertEquals('int|null', $this->parser->getParamTypeString($params['$param2']));
        $this->assertEquals('description for nullable int param', $this->parser->getParamDescription($params['$param2']));

        $this->assertInstanceOf(Param::class, $params['$param3']);
        $this->assertEquals('param3', $params['$param3']->getVariableName());
        $this->assertEquals('bool', $this->parser->getParamTypeString($params['$param3']));
        $this->assertEquals('nothing to say', $this->parser->getParamDescription($params['$param3']));

        $this->assertInstanceOf(Param::class, $params['$param4']);
        $this->assertEquals('param4', $params['$param4']->getVariableName());
        $this->assertEquals('mixed', $this->parser->getParamTypeString($params['$param4']));
        $this->assertEquals('Missing type', $this->parser->getParamDescription($params['$param4']));

        $this->assertInstanceOf(Param::class, $params['$param5']);
        $this->assertEquals('param5', $params['$param5']->getVariableName());
        $this->assertEquals('array<string,mixed>', $this->parser->getParamTypeString($params['$param5']));
        $this->assertEquals('array description', $this->parser->getParamDescription($params['$param5']));

        $this->assertInstanceOf(Param::class, $params['$param6']);
        $this->assertEquals('param6', $params['$param6']->getVariableName());
        $this->assertEquals('stdClass', $this->parser->getParamTypeString($params['$param6']));
        $this->assertEquals('object param', $this->parser->getParamDescription($params['$param6']));
    }

    public function testGetTagsByNameReturnsSpecificTags(): void
    {
        $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithMultipleTags');
        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->parser->parseDocBlock($docComment);

        $this->assertInstanceOf(DocBlock::class, $docBlock);

        $throwsTags = $docBlock->getTagsByName('throws');
        $this->assertCount(1, $throwsTags);
        $this->assertInstanceOf(Throws::class, $throwsTags[0]);
        $this->assertEquals('\\RuntimeException', (string) $throwsTags[0]->getType());
        $this->assertEquals('if processing fails', $throwsTags[0]->getDescription()->render());

        $deprecatedTags = $docBlock->getTagsByName('deprecated');
        $this->assertCount(1, $deprecatedTags);
        $this->assertInstanceOf(Deprecated::class, $deprecatedTags[0]);
        $this->assertEquals('use newMethod() instead', $deprecatedTags[0]->getDescription()->render());

        $seeTags = $docBlock->getTagsByName('see');
        $this->assertCount(1, $seeTags);
        $this->assertInstanceOf(See::class, $seeTags[0]);
        $this->assertStringContainsString('DocBlockTestFixture::newMethod()', (string) $seeTags[0]->getReference());

        $nonExistentTags = $docBlock->getTagsByName('nosuchtag');
        $this->assertEmpty($nonExistentTags);
    }

    public function testHandlesMethodWithNoDocblockGracefully(): void
    {
        $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithNoDocBlock');
        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->parser->parseDocBlock($docComment);

        $this->assertNull($docBlock);
        $this->assertNull($this->parser->getSummary($docBlock));
        $this->assertNull($this->parser->getDescription($docBlock));
        $this->assertEmpty($this->parser->getParamTags($docBlock));
    }
}
