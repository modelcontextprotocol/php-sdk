<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Stateless;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Server\Stateless\InputContext;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class InputContextTest extends TestCase
{
    #[TestDox('an elicitation answer comes back typed')]
    public function testElicitResultIsParsed(): void
    {
        $context = new InputContext(['who' => ['action' => 'accept', 'content' => ['name' => 'ada']]]);

        $result = $context->elicitResult('who');

        $this->assertNotNull($result);
        $this->assertSame(ElicitAction::Accept, $result->action);
        $this->assertSame(['name' => 'ada'], $result->content);
    }

    #[TestDox('an accepted url-mode answer needs no content')]
    public function testUrlModeElicitResultNeedsNoContent(): void
    {
        $context = new InputContext(['consent' => ['action' => 'accept']]);

        $this->assertNull($context->elicitResult('consent'));
        $this->assertNotNull($context->elicitResult('consent', ElicitationMode::Url));
    }

    #[TestDox('a sampling answer comes back typed')]
    public function testSamplingResultIsParsed(): void
    {
        $context = new InputContext(['capital' => [
            'role' => 'assistant',
            'content' => ['type' => 'text', 'text' => 'Paris'],
            'model' => 'a-model',
            'stopReason' => 'endTurn',
        ]]);

        $result = $context->samplingResult('capital');

        $this->assertNotNull($result);
        $this->assertSame('a-model', $result->model);
        $blocks = $result->getContentBlocks();
        $this->assertInstanceOf(TextContent::class, $blocks[0]);
        $this->assertSame('Paris', $blocks[0]->text);
    }

    #[TestDox('a roots answer comes back typed')]
    public function testRootsResultIsParsed(): void
    {
        $context = new InputContext(['roots' => ['roots' => [['uri' => 'file:///work', 'name' => 'work']]]]);

        $result = $context->rootsResult('roots');

        $this->assertNotNull($result);
        $this->assertCount(1, $result->roots);
        $this->assertSame('file:///work', $result->roots[0]->uri);
    }

    #[TestDox('an answer that was never given reads as absent')]
    public function testMissingKeyIsNull(): void
    {
        $context = new InputContext();

        $this->assertNull($context->elicitResult('who'));
        $this->assertNull($context->samplingResult('who'));
        $this->assertNull($context->rootsResult('who'));
        $this->assertFalse($context->has('who'));
    }

    #[TestDox('a malformed answer reads as absent, so the handler asks again rather than fails')]
    public function testMalformedAnswerIsNull(): void
    {
        $context = new InputContext([
            'who' => ['action' => 'not-an-action'],
            'capital' => ['role' => 'user', 'content' => []],
            'roots' => ['roots' => 'not a list'],
        ]);

        $this->assertNull($context->elicitResult('who'));
        $this->assertNull($context->samplingResult('capital'));
        $this->assertNull($context->rootsResult('roots'));

        // Still present — the client did answer, just not with something usable.
        $this->assertTrue($context->has('who'));
    }

    #[TestDox('the verified request state is carried alongside the answers')]
    public function testRequestStateIsCarried(): void
    {
        $context = new InputContext(['a' => ['action' => 'cancel']], ['round' => 2]);

        $this->assertSame(['round' => 2], $context->requestState());
        $this->assertSame(['a'], array_keys($context->all()));
    }
}
