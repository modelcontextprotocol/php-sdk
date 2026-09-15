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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `skills/list` and `skills/get` through the real dispatch path
 * (Builder -> MessageFactory -> StatelessProtocol -> Rev2026Codec), rather
 * than calling the handlers directly, so a wiring mistake in
 * {@see \Mcp\Schema\Extension\Skills\McpSkills::getMessages()} or in
 * {@see Server\Wire\Rev2026Codec::CACHEABLE_METHODS} would fail a test.
 */
class SkillsDispatchTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/skills';

    #[TestDox('skills/list is served with a cacheable envelope')]
    public function testSkillsListIsServed(): void
    {
        $answer = self::call(self::protocol(), 'skills/list');

        $this->assertSame(200, $answer['status']);
        $this->assertSame('complete', $answer['body']['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $answer['body']['result']);
        $this->assertArrayHasKey('cacheScope', $answer['body']['result']);
        $uris = array_column($answer['body']['result']['skills'], 'uri');
        $this->assertContains('skill://code-review/SKILL.md', $uris);
        $this->assertContains('skill://acme/billing/refunds/SKILL.md', $uris);
    }

    #[TestDox('skills/get returns the matching skill with a cacheable envelope')]
    public function testSkillsGetIsServed(): void
    {
        $answer = self::call(self::protocol(), 'skills/get', ['uri' => 'skill://code-review/SKILL.md']);

        $this->assertSame(200, $answer['status']);
        $this->assertSame('complete', $answer['body']['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $answer['body']['result']);
        $this->assertArrayHasKey('cacheScope', $answer['body']['result']);
        $this->assertSame('skill://code-review/SKILL.md', $answer['body']['result']['skill']['uri']);
        $this->assertSame('code-review', $answer['body']['result']['skill']['frontmatter']['name']);
    }

    #[TestDox('skills/get on an unknown uri answers Invalid params')]
    public function testSkillsGetUnknownUriIsInvalidParams(): void
    {
        $answer = self::call(self::protocol(), 'skills/get', ['uri' => 'skill://unknown/SKILL.md']);

        $this->assertSame(400, $answer['status']);
        $this->assertSame(-32602, $answer['body']['error']['code']);
    }

    #[TestDox('the extension is advertised under capabilities.extensions')]
    public function testSkillsExtensionIsAdvertised(): void
    {
        $answer = self::call(self::protocol(), 'server/discover');

        $this->assertArrayHasKey('io.modelcontextprotocol/skills', (array) $answer['body']['result']['capabilities']['extensions']);
    }

    private static function protocol(): StatelessProtocol
    {
        return Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->addSkillsFromDirectory(self::FIXTURES)
            ->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function call(StatelessProtocol $protocol, string $method, array $params = []): array
    {
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ];

        $result = $protocol->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR),
            [
                'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
                'Mcp-Method' => $method,
            ],
        );

        return [
            'status' => $result->httpStatus,
            'body' => json_decode($result->toJson(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
