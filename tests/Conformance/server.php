<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Tests\Conformance\Elements;
use Mcp\Tests\Conformance\FileLogger;

chdir(__DIR__);

$logger = new FileLogger(__DIR__.'/logs/conformance.log', true);

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$transport = new StreamableHttpTransport($request, logger: $logger);

$server = Server::builder()
    ->setServerInfo('mcp-conformance-test-server', '1.0.0')
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger($logger)
    // Tools
    ->addTool(fn () => 'This is a simple text response for testing.', 'test_simple_text', 'Tests simple text content response')
    ->addTool(fn () => new ImageContent(Elements::TEST_IMAGE_BASE64, 'image/png'), 'test_image_content', 'Tests image content response')
    ->addTool(fn () => new AudioContent(Elements::TEST_AUDIO_BASE64, 'audio/wav'), 'test_audio_content', 'Tests audio content response')
    ->addTool(fn () => EmbeddedResource::fromText('test://embedded-resource', 'This is an embedded resource content.'), 'test_embedded_resource', 'Tests embedded resource content response')
    ->addTool([Elements::class, 'toolMultipleTypes'], 'test_multiple_content_types', 'Tests response with multiple content types')
    ->addTool([Elements::class, 'toolWithLogging'], 'test_tool_with_logging', 'Tests tool that emits log messages')
    ->addTool([Elements::class, 'toolWithProgress'], 'test_tool_with_progress', 'Tests tool that reports progress notifications')
    ->addTool([Elements::class, 'toolWithSampling'], 'test_sampling', 'Tests server-initiated sampling')
    ->addTool(fn () => CallToolResult::error([new TextContent('This tool intentionally returns an error for testing')]), 'test_error_handling', 'Tests error response handling')
    // Resources
    ->addResource(fn () => 'This is the content of the static text resource.', 'test://static-text', 'static-text', 'A static text resource for testing')
    ->addResource(fn () => fopen('data://image/png;base64,'.Elements::TEST_IMAGE_BASE64, 'r'), 'test://static-binary', 'static-binary', 'A static binary resource (image) for testing')
    ->addResourceTemplate([Elements::class, 'resourceTemplate'], 'test://template/{id}/data', 'template', 'A resource template with parameter substitution', 'application/json')
    // TODO: Handler for resources/subscribe and resources/unsubscribe
    ->addResource(fn () => 'Watched resource content', 'test://watched-resource', 'watched-resource', 'A resource that can be watched')
    // Prompts
    ->addPrompt(fn () => [['role' => 'user', 'content' => 'This is a simple prompt for testing.']], 'test_simple_prompt', 'A simple prompt without arguments')
    ->addPrompt([Elements::class, 'promptWithArguments'], 'test_prompt_with_arguments', 'A prompt with required arguments')
    ->addPrompt([Elements::class, 'promptWithEmbeddedResource'], 'test_prompt_with_embedded_resource', 'A prompt that includes an embedded resource')
    ->addPrompt([Elements::class, 'promptWithImage'], 'test_prompt_with_image', 'A prompt that includes image content')
    ->build();

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
