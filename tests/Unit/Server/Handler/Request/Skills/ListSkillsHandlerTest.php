<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request\Skills;

use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Extension\Skills\ListSkillsRequest;
use Mcp\Schema\Extension\Skills\ListSkillsResult;
use Mcp\Schema\Extension\Skills\Skill;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Server\Handler\Request\Skills\ListSkillsHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class ListSkillsHandlerTest extends TestCase
{
    private SkillRegistry $registry;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new SkillRegistry();
        $this->session = new Session(new InMemorySessionStore());
    }

    public function testSupportsListSkillsRequest(): void
    {
        $handler = new ListSkillsHandler($this->registry);

        $this->assertTrue($handler->supports($this->request()));
    }

    public function testReturnsFirstPage(): void
    {
        $this->addSkills(5);
        $handler = new ListSkillsHandler($this->registry, pageSize: 3);

        $response = $handler->handle($this->request(), $this->session);

        /** @var ListSkillsResult $result */
        $result = $response->result;
        $this->assertCount(3, $result->skills);
        $this->assertNotNull($result->nextCursor);
        $this->assertSame('skill://skill-0/SKILL.md', $result->skills[0]->uri);
        $this->assertSame('skill://skill-2/SKILL.md', $result->skills[2]->uri);
    }

    public function testReturnsSecondPageWithCursor(): void
    {
        $this->addSkills(5);
        $handler = new ListSkillsHandler($this->registry, pageSize: 3);

        $firstPage = $handler->handle($this->request(), $this->session)->result;
        \assert($firstPage instanceof ListSkillsResult);

        $secondPage = $handler->handle($this->request($firstPage->nextCursor), $this->session)->result;
        \assert($secondPage instanceof ListSkillsResult);

        $this->assertCount(2, $secondPage->skills);
        $this->assertNull($secondPage->nextCursor);
        $this->assertSame('skill://skill-3/SKILL.md', $secondPage->skills[0]->uri);
        $this->assertSame('skill://skill-4/SKILL.md', $secondPage->skills[1]->uri);
    }

    public function testHandlesEmptyRegistry(): void
    {
        $handler = new ListSkillsHandler($this->registry);

        $result = $handler->handle($this->request(), $this->session)->result;
        \assert($result instanceof ListSkillsResult);

        $this->assertSame([], $result->skills);
        $this->assertNull($result->nextCursor);
    }

    public function testThrowsForInvalidCursor(): void
    {
        $this->addSkills(5);
        $handler = new ListSkillsHandler($this->registry);

        $this->expectException(InvalidCursorException::class);

        $handler->handle($this->request('not-base64!!'), $this->session);
    }

    public function testThrowsForCursorBeyondBounds(): void
    {
        $this->addSkills(5);
        $handler = new ListSkillsHandler($this->registry);

        $this->expectException(InvalidCursorException::class);

        $handler->handle($this->request(base64_encode('100')), $this->session);
    }

    private function request(?string $cursor = null): ListSkillsRequest
    {
        return (new ListSkillsRequest($cursor))->withId('test-request-id');
    }

    private function addSkills(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->registry->add(new Skill(
                "skill://skill-$i/SKILL.md",
                new SkillMetadata("skill-$i", "Skill number $i."),
                'dynamic',
            ));
        }
    }
}
