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

use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\Role;
use Mcp\Server\Protocol;
use Mcp\Server\RequestContext;

final class Elements
{
    public const TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
    public const TEST_AUDIO_BASE64 = 'UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAA=';

    /**
     * @return Content[]
     */
    public function toolMultipleTypes(): array
    {
        return [
            new TextContent('Multiple content types test:'),
            new ImageContent(self::TEST_IMAGE_BASE64, 'image/png'),
            EmbeddedResource::fromText(
                'test://mixed-content-resource',
                '{ "test" = "data", "value" = 123 }',
                'application/json',
            ),
        ];
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

    public function resourceTemplate(string $id): TextResourceContents
    {
        return new TextResourceContents(
            uri: 'test://template/{id}/data',
            mimeType: 'application/json',
            text: json_encode([
                'id' => $id,
                'templateTest' => true,
                'data' => \sprintf('Data for ID: %s', $id),
            ]),
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
