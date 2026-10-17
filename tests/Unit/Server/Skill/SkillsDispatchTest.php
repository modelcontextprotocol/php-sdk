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

use Mcp\Exception\LogicException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ServerCapabilities;
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

    #[TestDox('the resources capability is declared even when the skills directory holds no skill')]
    public function testResourcesCapabilityIsDeclaredWithoutSkills(): void
    {
        $dir = sys_get_temp_dir().'/mcp-skill-dispatch-test-'.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        try {
            $protocol = Server::builder()
                ->setServerInfo('test-server', '1.0.0')
                ->addSkillsFromDirectory($dir)
                ->buildStateless([ProtocolVersion::V2026_07_28]);

            $answer = self::call($protocol, 'server/discover');
        } finally {
            rmdir($dir);
        }

        $capabilities = $answer['body']['result']['capabilities'];
        $this->assertArrayHasKey('resources', $capabilities);
        $this->assertArrayHasKey('io.modelcontextprotocol/skills', (array) $capabilities['extensions']);
    }

    #[TestDox('the resources capability is declared when the capabilities are set explicitly')]
    public function testResourcesCapabilityIsDeclaredWithExplicitCapabilities(): void
    {
        $protocol = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->setCapabilities(new ServerCapabilities(tools: false, prompts: false))
            ->addSkillsFromDirectory(self::FIXTURES)
            ->buildStateless([ProtocolVersion::V2026_07_28]);

        $answer = self::call($protocol, 'server/discover');

        $this->assertArrayHasKey('resources', $answer['body']['result']['capabilities']);
    }

    #[TestDox('explicit capabilities without resources are rejected, since skills are served as resources')]
    public function testExplicitCapabilitiesWithoutResourcesAreRejected(): void
    {
        $builder = Server::builder()
            ->setServerInfo('test-server', '1.0.0')
            ->setCapabilities(new ServerCapabilities(resources: false))
            ->addSkillsFromDirectory(self::FIXTURES);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must declare "resources"');

        $builder->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    #[TestDox('an empty frontmatter mapping reaches the wire as an object, not as a list')]
    public function testEmptyFrontmatterMappingIsSentAsObject(): void
    {
        $dir = sys_get_temp_dir().'/mcp-skill-dispatch-test-'.bin2hex(random_bytes(8));
        mkdir($dir.'/mapped', 0777, true);
        file_put_contents($dir.'/mapped/SKILL.md', "---\nname: mapped\ndescription: Has an empty mapping.\nmetadata: {}\n---\nbody");

        try {
            $protocol = Server::builder()
                ->setServerInfo('test-server', '1.0.0')
                ->addSkillsFromDirectory($dir)
                ->buildStateless([ProtocolVersion::V2026_07_28]);

            $json = self::callRaw($protocol, 'skills/get', ['uri' => 'skill://mapped/SKILL.md']);
        } finally {
            unlink($dir.'/mapped/SKILL.md');
            rmdir($dir.'/mapped');
            rmdir($dir);
        }

        $this->assertStringContainsString('"metadata":{}', $json);
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

    /**
     * @param array<string, mixed> $params
     */
    private static function callRaw(StatelessProtocol $protocol, string $method, array $params = []): string
    {
        $params['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
        ];

        return $protocol->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR),
            [
                'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
                'Mcp-Method' => $method,
            ],
        )->toJson();
    }
}
