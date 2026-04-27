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
use Mcp\Capability\Registry\Loader\ArrayLoader;
use PHPUnit\Framework\TestCase;

class ArrayLoaderToolTitleTest extends TestCase
{
    public function testLoadPropagatesToolTitleToRegisteredTool(): void
    {
        $tools = [
            [
                'handler' => static fn (): string => 'ok',
                'name' => 'weather_lookup',
                'title' => 'Weather Lookup',
                'description' => null,
                'annotations' => null,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => null,
                ],
                'icons' => null,
                'meta' => null,
                'outputSchema' => null,
            ],
        ];

        $loader = new ArrayLoader($tools);
        $registry = new Registry();

        $loader->load($registry);

        $toolRef = $registry->getTool('weather_lookup');
        $this->assertSame('Weather Lookup', $toolRef->tool->title);
    }
}
