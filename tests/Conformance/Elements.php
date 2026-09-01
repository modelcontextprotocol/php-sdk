<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Conformance;

use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\MultiSelectEnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Elicitation\TitledEnumSchemaDefinition;
use Mcp\Schema\Elicitation\TitledMultiSelectEnumSchemaDefinition;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Protocol;
use Mcp\Server\RequestContext;

final class Elements
{
    public const TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
    public const TEST_AUDIO_BASE64 = 'UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAA=';

    /**
     * The focal `inputSchema` the `json-schema-2020-12` scenario expects a
     * server to advertise verbatim.
     *
     * It is handed to `addTool()` raw rather than generated from a signature,
     * because the point is that the SDK passes an author-supplied 2020-12
     * schema through `tools/list` untouched: `$schema`/`$defs`/
     * `additionalProperties` (SEP-1613) and the composition, conditional and
     * `$anchor` keywords (SEP-2106) must all survive.
     *
     * Mirrors `JSON_SCHEMA_2020_12_FIXTURE` in the conformance suite; keep the
     * two in sync.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema2020_12Fixture(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            '$defs' => [
                'address' => [
                    '$anchor' => 'addressDef',
                    'type' => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                    ],
                ],
            ],
            'properties' => [
                'name' => ['type' => 'string'],
                'address' => ['$ref' => '#/$defs/address'],
                'contactMethod' => ['type' => 'string', 'enum' => ['phone', 'email']],
                'phone' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'allOf' => [['anyOf' => [['required' => ['phone']], ['required' => ['email']]]]],
            'if' => [
                'properties' => ['contactMethod' => ['const' => 'phone']],
                'required' => ['contactMethod'],
            ],
            'then' => ['required' => ['phone']],
            'else' => ['required' => ['email']],
            'additionalProperties' => false,
        ];
    }

    public function toolMultipleTypes(): CallToolResult
    {
        return new CallToolResult([
            new TextContent('Multiple content types test:'),
            new ImageContent(self::TEST_IMAGE_BASE64, 'image/png'),
            EmbeddedResource::fromText(
                'test://mixed-content-resource',
                '{ "test" = "data", "value" = 123 }',
                'application/json',
            ),
        ]);
    }

    public function toolWithLogging(RequestContext $context): string
    {
        $logger = $context->getClientLogger();

        $logger->info('Tool execution started');
        $logger->info('Tool processing data');
        $logger->info('Tool execution completed');

        return 'Tool with logging executed successfully';
    }

    public function toolWithProgress(RequestContext $context): ?string
    {
        $client = $context->getClientGateway();

        $client->progress(0, 100, 'Completed step 0 of 100');
        $client->progress(50, 100, 'Completed step 50 of 100');
        $client->progress(100, 100, 'Completed step 100 of 100');

        $meta = $context->getSession()->get(Protocol::SESSION_ACTIVE_REQUEST_META, []);

        return $meta['progressToken'] ?? null;
    }

    /**
     * @param string $prompt The prompt to send to the LLM
     */
    public function toolWithSampling(RequestContext $context, string $prompt): string
    {
        $result = $context->getClientGateway()->sample($prompt, 100);

        return \sprintf(
            'LLM response: %s',
            $result->content instanceof TextContent ? trim((string) $result->content->text) : ''
        );
    }

    /**
     * @param string $message The message to display to the user
     */
    public function toolWithElicitation(RequestContext $context, string $message): string
    {
        $schema = new ElicitationSchema(
            properties: [
                'username' => new StringSchemaDefinition('Username'),
                'email' => new StringSchemaDefinition('Email'),
            ],
        );

        $context->getClientGateway()->elicit($message, $schema);

        return 'ok';
    }

    public function toolWithElicitationDefaults(RequestContext $context): string
    {
        $schema = new ElicitationSchema(
            properties: [
                'name' => new StringSchemaDefinition('Name', default: 'John Doe'),
                'age' => new NumberSchemaDefinition('Age', integerOnly: true, default: 30),
                'score' => new NumberSchemaDefinition('Score', default: 95.5),
                'status' => new EnumSchemaDefinition('Status', enum: ['active', 'inactive', 'pending'], default: 'active'),
                'verified' => new BooleanSchemaDefinition('Verified', default: true),
            ],
        );

        $context->getClientGateway()->elicit('Provide profile information', $schema);

        return 'ok';
    }

    public function toolWithElicitationEnums(RequestContext $context): string
    {
        $schema = new ElicitationSchema(
            properties: [
                'untitledSingle' => new EnumSchemaDefinition('Untitled Single', enum: ['option1', 'option2', 'option3']),
                'titledSingle' => new TitledEnumSchemaDefinition('Titled Single', oneOf: [
                    ['const' => 'value1', 'title' => 'Label 1'],
                    ['const' => 'value2', 'title' => 'Label 2'],
                    ['const' => 'value3', 'title' => 'Label 3'],
                ]),
                'legacyEnum' => new EnumSchemaDefinition('Legacy Enum', enum: ['opt1', 'opt2', 'opt3'], enumNames: ['Option 1', 'Option 2', 'Option 3']),
                'untitledMulti' => new MultiSelectEnumSchemaDefinition('Untitled Multi', enum: ['option1', 'option2', 'option3']),
                'titledMulti' => new TitledMultiSelectEnumSchemaDefinition('Titled Multi', anyOf: [
                    ['const' => 'value1', 'title' => 'Label 1'],
                    ['const' => 'value2', 'title' => 'Label 2'],
                    ['const' => 'value3', 'title' => 'Label 3'],
                ]),
            ],
        );

        $context->getClientGateway()->elicit('Select options', $schema);

        return 'ok';
    }

    public function resourceTemplate(string $id): TextResourceContents
    {
        return new TextResourceContents(
            uri: \sprintf('test://template/%s/data', $id),
            mimeType: 'application/json',
            text: json_encode([
                'id' => $id,
                'templateTest' => true,
                'data' => \sprintf('Data for ID: %s', $id),
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param string $arg1 First test argument
     * @param string $arg2 Second test argument
     *
     * @return PromptMessage[]
     */
    public function promptWithArguments(string $arg1, string $arg2): array
    {
        return [
            new PromptMessage(Role::User, new TextContent(\sprintf('Prompt with arguments: arg1="%s", arg2="%s"', $arg1, $arg2))),
        ];
    }

    /**
     * @param string $resourceUri URI of the resource to embed
     *
     * @return PromptMessage[]
     */
    public function promptWithEmbeddedResource(string $resourceUri): array
    {
        return [
            new PromptMessage(Role::User, EmbeddedResource::fromText($resourceUri, 'Embedded resource content for testing.')),
            new PromptMessage(Role::User, new TextContent('Please process the embedded resource above.')),
        ];
    }

    /**
     * @return PromptMessage[]
     */
    public function promptWithImage(): array
    {
        return [
            new PromptMessage(Role::User, new ImageContent(self::TEST_IMAGE_BASE64, 'image/png')),
            new PromptMessage(Role::User, new TextContent('Please analyze the image above.')),
        ];
    }
}
