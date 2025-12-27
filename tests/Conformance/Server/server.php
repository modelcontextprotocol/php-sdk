<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\ImageContent;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

// Sample base64 encoded 1x1 red PNG pixel for testing
const TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
// Sample base64 encoded minimal WAV file for testing
const TEST_AUDIO_BASE64 = 'UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAA=';

$server = Server::builder()
    ->setServerInfo('mcp-conformance-test-server', '1.0.0')
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->addTool(fn () => 'This is a simple text response for testing.', 'test_simple_text', 'Tests simple text content response')
    ->addTool(fn () => new ImageContent(TEST_IMAGE_BASE64, 'image/png'), 'test_image_content', 'Tests image content response')
    ->addTool(fn () => new AudioContent(TEST_AUDIO_BASE64, 'audio/wav'), 'test_audio_content', 'Tests audio content response')
    ->build();

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
);

$result = $server->run($transport);

(new SapiEmitter())->emit($result);
