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

use Mcp\Schema\Tool;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\RegistryInterface;
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
        // covered by the conformance fixture.
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

    #[TestDox('a Base64-wrapped Mcp-Name is decoded before it is compared')]
    #[DataProvider('wrappedNames')]
    public function testWrappedNameHeaderIsDecoded(string $method, string $member, string $subject): void
    {
        $this->assertNull($this->validator->validate(
            $method,
            [$member => $subject],
            [
                'Mcp-Method' => $method,
                'Mcp-Name' => '=?base64?'.base64_encode($subject).'?=',
            ],
        ));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wrappedNames(): iterable
    {
        yield 'non-ASCII tool name' => ['tools/call', 'name', 'grüße_welt'];
        yield 'CJK prompt name' => ['prompts/get', 'name', '天気予報'];
        yield 'resource URI with a non-ASCII path' => ['resources/read', 'uri', 'file:///projects/münchen/config.json'];
        yield 'value padded with spaces' => ['tools/call', 'name', ' padded '];
        yield 'value matching the sentinel pattern' => ['tools/call', 'name', '=?base64?literal?='];
    }

    #[TestDox('a wrapped Mcp-Name that disagrees with the body is still rejected')]
    public function testWrappedNameHeaderStillHasToMatch(): void
    {
        $this->assertStringContainsString('does not match', (string) $this->validator->validate(
            'tools/call',
            ['name' => 'grüße_welt'],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => '=?base64?'.base64_encode('etwas_anderes').'?='],
        ));
    }

    #[TestDox('a malformed Base64 wrapper on Mcp-Name is refused, not compared raw')]
    public function testMalformedWrappedNameHeaderIsRefused(): void
    {
        $this->assertStringContainsString('well-formed Base64', (string) $this->validator->validate(
            'tools/call',
            ['name' => 'do_thing'],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => '=?base64?SGVsbG8!?='],
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
        yield 'non-string name is ignored' => ['tools/call', ['name' => 42], null];
    }

    #[TestDox('an annotation on a nested property is found through the properties chain')]
    public function testNestedMirroredPropertyIsFound(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'target' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    ],
                ],
                'top' => ['type' => 'string', 'x-mcp-header' => 'Top'],
            ],
        ];

        $this->assertSame(
            ['Region' => ['target', 'region'], 'Top' => ['top']],
            StandardHeaderValidator::mirroredProperties($schema),
        );
    }

    #[TestDox('an annotation the chain cannot reach statically is not mirrored')]
    #[DataProvider('unreachableAnnotations')]
    public function testUnreachableAnnotationsAreNotMirrored(array $properties): void
    {
        $this->assertSame([], StandardHeaderValidator::mirroredProperties([
            'type' => 'object',
            'properties' => $properties,
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreachableAnnotations(): iterable
    {
        yield 'under items' => [['a' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'X']]]];
        yield 'under anyOf' => [['a' => ['anyOf' => [['type' => 'string', 'x-mcp-header' => 'X']]]]];
        yield 'under if' => [['a' => ['if' => ['type' => 'string', 'x-mcp-header' => 'X']]]];
    }

    #[TestDox('an integer is compared numerically, so 42 and 42.0 agree')]
    public function testIntegerParamsCompareNumerically(): void
    {
        $validator = new StandardHeaderValidator(self::registryWithMirroredTool());

        $this->assertNull($validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['retries' => 42]],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Retries' => '42.0'],
        ));

        $this->assertStringContainsString('does not match', (string) $validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['retries' => 42]],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Retries' => '43'],
        ));
    }

    #[TestDox('a numeric-looking string argument keeps its exact-match comparison')]
    public function testNumericLookingStringArgumentIsNotComparedNumerically(): void
    {
        $validator = new StandardHeaderValidator(self::registryWithMirroredTool());

        $this->assertStringContainsString('does not match', (string) $validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['retries' => '042']],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Retries' => '42'],
        ));

        $this->assertNull($validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['retries' => '042']],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Retries' => '042'],
        ));
    }

    #[TestDox('a scientific-notation header is not accepted as a decimal number')]
    public function testScientificNotationHeaderIsRejected(): void
    {
        $validator = new StandardHeaderValidator(self::registryWithMirroredTool());

        $this->assertStringContainsString('does not match', (string) $validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['retries' => 40]],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Retries' => '4e1'],
        ));
    }

    #[TestDox('a nested mirrored argument is read at its exact path')]
    public function testNestedMirroredArgumentIsChecked(): void
    {
        $validator = new StandardHeaderValidator(self::registryWithMirroredTool());

        $this->assertNull($validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['target' => ['region' => 'us-west1']]],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored', 'Mcp-Param-Region' => 'us-west1'],
        ));

        $this->assertStringContainsString('Missing required Mcp-Param-Region', (string) $validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => ['target' => ['region' => 'us-west1']]],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored'],
        ));

        // Absent at that path, so no header is expected.
        $this->assertNull($validator->validate(
            'tools/call',
            ['name' => 'mirrored', 'arguments' => []],
            ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'mirrored'],
        ));
    }

    private static function registryWithMirroredTool(): RegistryInterface
    {
        $registry = new Registry();
        $registry->registerTool(
            new Tool(
                name: 'mirrored',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'retries' => ['type' => 'integer', 'x-mcp-header' => 'Retries'],
                        'target' => [
                            'type' => 'object',
                            'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
                        ],
                    ],
                    'required' => null,
                ],
                description: 'x',
                annotations: null,
            ),
            static fn (): string => 'ok',
        );

        return $registry;
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
        // PHP's decoder returns bytes for most of these; accepting them would
        // let a corrupted header silently compare equal.
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
