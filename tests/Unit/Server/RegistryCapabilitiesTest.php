<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Schema\Tool;
use Mcp\Server\Capability\Registry;
use Mcp\Server\Capability\Registry\LazyRegistry;
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\PartialLoadException;
use Mcp\Server\RegistryCapabilities;
use PHPUnit\Framework\TestCase;

final class RegistryCapabilitiesTest extends TestCase
{
    public function testAdvertisesOnlyWhatTheRegistryHolds(): void
    {
        $registry = new Registry();
        $registry->registerTool($this->tool(), static fn (): string => 'ok');

        $capabilities = (new RegistryCapabilities($registry, false))->detect();

        $this->assertTrue($capabilities->tools);
        $this->assertFalse($capabilities->resources);
        $this->assertFalse($capabilities->prompts);
    }

    public function testRunsAPendingLoadAndAdvertisesItsResult(): void
    {
        $tool = $this->tool();
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('load')->willReturnCallback(static function (RegistryInterface $registry) use ($tool): void {
            $registry->registerTool($tool, static fn (): string => 'ok');
        });

        $capabilities = (new RegistryCapabilities(new LazyRegistry(new Registry(), $loader), false))->detect();

        $this->assertTrue($capabilities->tools);
        $this->assertFalse($capabilities->prompts);
    }

    public function testFollowsTheRegistryAcrossRequests(): void
    {
        $registry = new Registry();
        $detector = new RegistryCapabilities($registry, false);

        $this->assertFalse($detector->detect()->tools);

        $registry->registerTool($this->tool(), static fn (): string => 'ok');

        $this->assertTrue($detector->detect()->tools);
    }

    public function testAdvertisesAllKindsWhileAnotherFiberIsLoading(): void
    {
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('load')->willReturnCallback(static function (): void {
            \Fiber::suspend();
        });

        $registry = new LazyRegistry(new Registry(), $loader);
        $fiber = new \Fiber($registry->load(...));
        $fiber->start();

        $capabilities = (new RegistryCapabilities($registry, false))->detect();

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->prompts);
    }

    public function testAdvertisesAllKindsWhileTheSuppliedRegistryIsIncomplete(): void
    {
        $unavailable = $this->createStub(LoaderInterface::class);
        $unavailable->method('load')->willThrowException(new PartialLoadException());
        $tool = $this->tool();
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('load')->willReturnCallback(static function (RegistryInterface $registry) use ($tool): void {
            $registry->registerTool($tool, static fn (): string => 'ok');
        });

        $registry = new LazyRegistry(new Registry(loader: $unavailable), $loader);
        $capabilities = (new RegistryCapabilities($registry, false))->detect();

        $this->assertFalse($registry->isLoaded());
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->prompts);
    }

    private function tool(): Tool
    {
        return new Tool(name: 'alpha', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: null, annotations: null);
    }
}
