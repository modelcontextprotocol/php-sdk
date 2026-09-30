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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Skills\GetSkillRequest;
use Mcp\Schema\Extension\Skills\GetSkillResult;
use Mcp\Schema\Extension\Skills\Skill;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Server\Handler\Request\Skills\GetSkillHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class GetSkillHandlerTest extends TestCase
{
    private SkillRegistry $registry;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->registry = new SkillRegistry();
        $this->session = new Session(new InMemorySessionStore());
    }

    public function testSupportsGetSkillRequest(): void
    {
        $handler = new GetSkillHandler($this->registry);

        $this->assertTrue($handler->supports($this->request('skill://code-review/SKILL.md')));
    }

    public function testReturnsTheMatchingSkill(): void
    {
        $skill = new Skill('skill://code-review/SKILL.md', new SkillMetadata('code-review', 'Review a PR.'), 'dynamic');
        $this->registry->add($skill);
        $handler = new GetSkillHandler($this->registry);

        $response = $handler->handle($this->request('skill://code-review/SKILL.md'), $this->session);

        /** @var GetSkillResult $result */
        $result = $response->result;
        $this->assertSame($skill, $result->skill);
    }

    public function testThrowsInvalidArgumentForUnknownSkill(): void
    {
        $handler = new GetSkillHandler($this->registry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No skill is served at skill://unknown/SKILL.md');

        $handler->handle($this->request('skill://unknown/SKILL.md'), $this->session);
    }

    private function request(string $uri): GetSkillRequest
    {
        return (new GetSkillRequest($uri))->withId('test-request-id');
    }
}
