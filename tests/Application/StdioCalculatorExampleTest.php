<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Application;

use Mcp\Tests\Application\ApplicationTestCase;

final class StdioCalculatorExampleTest extends ApplicationTestCase
{
    /**
     * @throws \JsonException
     */
    public function testToolsListMatchesSnapshot(): void
    {
        $toolsListId = uniqid('t_');

        $responses = $this->runServer([
            $this->initializeMessage(),
            $this->jsonRequest('tools/list', id: $toolsListId),
        ]);

        $this->assertArrayHasKey($toolsListId, $responses);
        $toolsList = $responses[$toolsListId];
        $this->assertResponseMatchesSnapshot($toolsList, 'tools/list');
    }

    /**
     * @throws \JsonException
     */
    public function testPromptsListMatchesSnapshot(): void
    {
        $promptsListId = uniqid('t_');

        $responses = $this->runServer([
            $this->initializeMessage(),
            $this->jsonRequest('prompts/list', id: $promptsListId),
        ]);

        $this->assertArrayHasKey($promptsListId, $responses);
        $promptsList = $responses[$promptsListId];
        $this->assertResponseMatchesSnapshot($promptsList, 'prompts/list');
    }

    /**
     * @throws \JsonException
     */
    public function testResourcesListMatchesSnapshot(): void
    {
        $resourcesListId = uniqid('t_');

        $responses = $this->runServer([
            $this->initializeMessage(),
            $this->jsonRequest('resources/list', id: $resourcesListId),
        ]);

        $this->assertArrayHasKey($resourcesListId, $responses);
        $resourcesList = $responses[$resourcesListId];
        $this->assertResponseMatchesSnapshot($resourcesList, 'resources/list');
    }

    /**
     * @throws \JsonException
     */
    public function testResourceTemplatesListMatchesSnapshot(): void
    {
        $templatesListId = uniqid('t_');

        $responses = $this->runServer([
            $this->initializeMessage(),
            $this->jsonRequest('resources/templates/list', id: $templatesListId),
        ]);

        $this->assertArrayHasKey($templatesListId, $responses);
        $templatesList = $responses[$templatesListId];
        $this->assertResponseMatchesSnapshot($templatesList, 'resources/templates/list');
    }

    protected function getServerScript(): string
    {
        return \dirname(__DIR__, 2).'/examples/stdio-discovery-calculator/server.php';
    }
}
