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

use Mcp\Server\Stateless\StandardHeaderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class StandardHeaderValidatorTest extends TestCase
{
    private StandardHeaderValidator $validator;

    protected function setUp(): void
    {
        // No registry: the Mcp-Param dimension needs tool definitions and is
        // covered by the conformance fixture, which has real ones. Everything
        // here is registry-independent.
        $this->validator = new StandardHeaderValidator();
    }

    #[TestDox('a request whose headers agree with its body passes')]
    public function testConsistentRequestPasses(): void
    {
        $this->assertNull($this->validator->validate(
            'tools/call',
            ['name' => 'do_thing', 'arguments' => []],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'do_thing'],
        ));
    }

    #[TestDox('a missing Mcp-Method is rejected')]
    public function testMissingMethodHeaderRejected(): void
    {
        $this->assertStringContainsString(
            'Mcp-Method',
            (string) $this->validator->validate('tools/list', null, []),
        );
    }

    #[TestDox('an Mcp-Method that disagrees with the body is rejected')]
    public function testMismatchedMethodHeaderRejected(): void
    {
        $this->assertStringContainsString(
            'does not match',
            (string) $this->validator->validate('tools/list', null, ['Mcp-Method' => 'prompts/list']),
        );
    }

    #[TestDox('header names compare case-insensitively')]
    #[DataProvider('headerCasings')]
    public function testHeaderNameCasingIsIgnored(string $name): void
    {
        $this->assertNull($this->validator->validate('tools/list', null, [$name => 'tools/list']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerCasings(): iterable
    {
        yield 'canonical' => ['Mcp-Method'];
        yield 'lowercase' => ['mcp-method'];
        yield 'uppercase' => ['MCP-METHOD'];
        yield 'mixed' => ['mCp-MeThOd'];
    }

    #[TestDox('surrounding whitespace is not part of a header value (RFC 9110 §5.5)')]
    public function testOptionalWhitespaceIsTrimmed(): void
    {
        $this->assertNull($this->validator->validate(
            'tools/call',
            ['name' => 'do_thing'],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => '   do_thing  '],
        ));
    }

    #[TestDox('Mcp-Name is required when the body carries a name, and rejected when it disagrees')]
    public function testNameHeaderMustMatchBody(): void
    {
        $body = ['name' => 'do_thing'];

        $this->assertStringContainsString('Missing required Mcp-Name', (string) $this->validator->validate(
            'tools/call',
            $body,
            ['Mcp-Method' => 'tools/call'],
        ));

        $this->assertStringContainsString('does not match', (string) $this->validator->validate(
            'tools/call',
            $body,
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'something_else'],
        ));
    }

    #[TestDox('a method that carries no name does not require the header')]
    public function testNamelessMethodNeedsNoNameHeader(): void
    {
        $this->assertNull($this->validator->validate('tools/list', null, ['Mcp-Method' => 'tools/list']));
    }

    #[TestDox('each method names its subject through its own params member')]
    #[DataProvider('nameSources')]
    public function testNameIsReadFromTheRightMember(string $method, array $params, ?string $expected): void
    {
        $this->assertSame($expected, StandardHeaderValidator::nameFor($method, $params));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, ?string}>
     */
    public static function nameSources(): iterable
    {
        yield 'tools/call uses name' => ['tools/call', ['name' => 'a'], 'a'];
        yield 'prompts/get uses name' => ['prompts/get', ['name' => 'b'], 'b'];
        yield 'resources/read uses uri' => ['resources/read', ['uri' => 'test://c'], 'test://c'];
        yield 'tasks/get uses taskId' => ['tasks/get', ['taskId' => 'd'], 'd'];
        yield 'tools/list has no subject' => ['tools/list', [], null];
        // A non-string value is not a name: treating it as one would compare a
        // header against something that was never a header value.
        yield 'non-string name is ignored' => ['tools/call', ['name' => 42], null];
    }

    #[TestDox('a plain header value decodes to itself')]
    public function testPlainValuePassesThroughDecode(): void
    {
        $this->assertSame('Hello', StandardHeaderValidator::decode('Hello'));
    }

    #[TestDox('a well-formed Base64 wrapper decodes to its contents')]
    public function testValidBase64Decodes(): void
    {
        $this->assertSame('Hello', StandardHeaderValidator::decode('=?base64?'.base64_encode('Hello').'?='));
    }

    #[TestDox('malformed Base64 is refused rather than salvaged')]
    #[DataProvider('malformedBase64')]
    public function testMalformedBase64IsRefused(string $encoded): void
    {
        // PHP's decoder will happily return bytes for most of these. Accepting
        // them would let a corrupted header silently compare equal — or, worse,
        // silently unequal — instead of being reported as the transport-level
        // fault it is.
        $this->assertNull(StandardHeaderValidator::decode('=?base64?'.$encoded.'?='));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBase64(): iterable
    {
        yield 'missing padding' => ['SGVsbG8'];
        yield 'out-of-alphabet character' => ['SGVsbG8!'];
        yield 'stray whitespace' => ['SGVs bG8='];
    }
}
