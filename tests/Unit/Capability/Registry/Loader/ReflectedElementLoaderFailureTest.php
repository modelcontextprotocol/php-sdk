<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry\Loader;

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\ReflectedElementLoader;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class ReflectedElementLoaderFailureTest extends TestCase
{
    #[TestDox('A placeholder-less URI template costs its own registration, not the whole registry')]
    public function testMalformedResourceTemplateLeavesEveryOtherElementRegistered(): void
    {
        $registry = new Registry(logger: new RecordingLogger(), loader: $this->loaderWithBadTemplate());

        $this->assertCount(1, $registry->getTools());
        $this->assertCount(1, $registry->getResources());
        $this->assertCount(1, $registry->getPrompts());
        $this->assertCount(0, $registry->getResourceTemplates());
    }

    #[TestDox('The skipped template is reported at error level with its handler and URI')]
    public function testSkippedElementIsLogged(): void
    {
        $logger = new RecordingLogger();

        (new ReflectedElementLoader(
            resourceTemplates: [$this->templateData('data://tags')],
            logger: $logger,
        ))->load(new Registry());

        $this->assertCount(1, $logger->errors);
        $this->assertSame('Failed to register manual template', $logger->errors[0]['message']);
        $this->assertSame('data://tags', $logger->errors[0]['context']['uriTemplate']);
        $this->assertArrayHasKey('exception', $logger->errors[0]['context']);
    }

    #[TestDox('A load that skipped an element still counts as loaded, so reads stop retrying it')]
    public function testLoadIsNotRetriedAfterSkippingAnElement(): void
    {
        $logger = new RecordingLogger();
        $registry = new Registry(logger: new RecordingLogger(), loader: new ReflectedElementLoader(
            resourceTemplates: [$this->templateData('data://tags')],
            logger: $logger,
        ));

        $registry->getTools();
        $registry->getResourceTemplates();

        $this->assertCount(1, $logger->errors);
    }

    #[TestDox('An unresolvable handler is skipped for every element type')]
    public function testUnresolvableHandlerIsSkippedForEveryElementType(): void
    {
        $missing = 'Mcp\Tests\Unit\Capability\Registry\Loader\NoSuchHandler';

        $registry = new Registry(logger: new RecordingLogger(), loader: new ReflectedElementLoader(
            tools: [['handler' => $missing, 'name' => 'broken_tool', 'title' => null, 'description' => null, 'annotations' => null, 'icons' => null, 'meta' => null, 'outputSchema' => null]],
            resources: [['handler' => $missing, 'uri' => 'config://broken', 'name' => 'broken_resource', 'title' => null, 'description' => null, 'mimeType' => null, 'size' => null, 'annotations' => null, 'icons' => null, 'meta' => null]],
            resourceTemplates: [['handler' => $missing, 'uriTemplate' => 'config://{broken}', 'name' => 'broken_template', 'title' => null, 'description' => null, 'mimeType' => null, 'annotations' => null, 'meta' => null]],
            prompts: [['handler' => $missing, 'name' => 'broken_prompt', 'title' => null, 'description' => null, 'icons' => null, 'meta' => null]],
        ));

        $this->assertCount(0, $registry->getTools());
        $this->assertCount(0, $registry->getResources());
        $this->assertCount(0, $registry->getResourceTemplates());
        $this->assertCount(0, $registry->getPrompts());
    }

    private function loaderWithBadTemplate(): ReflectedElementLoader
    {
        return new ReflectedElementLoader(
            tools: [[
                'handler' => static fn (): string => 'ok',
                'name' => 'greet',
                'title' => null,
                'description' => null,
                'annotations' => null,
                'icons' => null,
                'meta' => null,
                'outputSchema' => null,
            ]],
            resources: [[
                'handler' => static fn (): string => 'ok',
                'uri' => 'config://app/settings',
                'name' => 'app_settings',
                'title' => null,
                'description' => null,
                'mimeType' => null,
                'size' => null,
                'annotations' => null,
                'icons' => null,
                'meta' => null,
            ]],
            resourceTemplates: [$this->templateData('data://tags')],
            prompts: [[
                'handler' => static fn (): string => 'ok',
                'name' => 'welcome',
                'title' => null,
                'description' => null,
                'icons' => null,
                'meta' => null,
            ]],
            logger: new RecordingLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(string $uriTemplate): array
    {
        return [
            'handler' => static fn (): string => 'ok',
            'uriTemplate' => $uriTemplate,
            'name' => 'all_tags',
            'title' => null,
            'description' => null,
            'mimeType' => null,
            'annotations' => null,
            'meta' => null,
        ];
    }
}

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $errors = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (LogLevel::ERROR === $level) {
            $this->errors[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
