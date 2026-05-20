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
use PHPUnit\Framework\TestCase;

class ReflectedElementLoaderResourceTitleTest extends TestCase
{
    public function testLoadPropagatesResourceTitleToRegisteredResource(): void
    {
        $resources = [
            [
                'handler' => static fn (): string => 'ok',
                'uri' => 'config://app/settings',
                'name' => 'app_settings',
                'title' => 'Application Settings',
                'description' => null,
                'mimeType' => null,
                'size' => null,
                'annotations' => null,
                'icons' => null,
                'meta' => null,
            ],
        ];

        $loader = new ReflectedElementLoader([], $resources);
        $registry = new Registry();

        $loader->load($registry);

        $resourceRef = $registry->getResource('config://app/settings');
        $this->assertSame('Application Settings', $resourceRef->resource->title);
    }

    public function testLoadPropagatesResourceTemplateTitleToRegisteredTemplate(): void
    {
        $resourceTemplates = [
            [
                'handler' => static fn (): string => 'ok',
                'uriTemplate' => 'user://{userId}/profile',
                'name' => 'user_profile',
                'title' => 'User Profile',
                'description' => null,
                'mimeType' => null,
                'annotations' => null,
                'meta' => null,
            ],
        ];

        $loader = new ReflectedElementLoader([], [], $resourceTemplates);
        $registry = new Registry();

        $loader->load($registry);

        $templateRef = $registry->getResourceTemplate('user://{userId}/profile');
        $this->assertSame('User Profile', $templateRef->resourceTemplate->title);
    }
}
