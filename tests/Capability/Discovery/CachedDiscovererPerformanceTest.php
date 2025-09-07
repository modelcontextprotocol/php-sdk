<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Discovery;

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CachedDiscovererPerformanceTest extends TestCase
{
    public function testCachedDiscoveryIsFasterThanUncached(): void
    {
        // Create a temporary directory with some PHP files for testing
        $tempDir = sys_get_temp_dir().'/mcp_discovery_test_'.uniqid();
        mkdir($tempDir, 0755, true);

        // Create some test PHP files with MCP attributes
        $this->createTestFiles($tempDir);

        try {
            // Test uncached discovery
            $registry1 = new Registry(new ReferenceHandler(), null, new NullLogger());
            $discoverer1 = new Discoverer($registry1, new NullLogger());

            $startTime = microtime(true);
            $discoveryState1 = $discoverer1->discover($tempDir, ['.'], []);
            $discoverer1->applyDiscoveryState($discoveryState1);
            $uncachedTime = microtime(true) - $startTime;

            // Test cached discovery (first call - cache miss)
            $registry2 = new Registry(new ReferenceHandler(), null, new NullLogger());
            $discoverer2 = new Discoverer($registry2, new NullLogger());
            $cache = new Psr16Cache(new ArrayAdapter());
            $cachedDiscoverer = new CachedDiscoverer($discoverer2, $cache, new NullLogger());

            $startTime = microtime(true);
            $discoveryState2 = $cachedDiscoverer->discover($tempDir, ['.'], []);
            $discoverer2->applyDiscoveryState($discoveryState2);
            $cachedFirstTime = microtime(true) - $startTime;

            // Test cached discovery (second call - cache hit)
            $registry3 = new Registry(new ReferenceHandler(), null, new NullLogger());
            $discoverer3 = new Discoverer($registry3, new NullLogger());
            $cachedDiscoverer2 = new CachedDiscoverer($discoverer3, $cache, new NullLogger());

            $startTime = microtime(true);
            $discoveryState3 = $cachedDiscoverer2->discover($tempDir, ['.'], []);
            $discoverer3->applyDiscoveryState($discoveryState3);
            $cachedSecondTime = microtime(true) - $startTime;

            // Verify performance improvements
            $this->assertLessThan($uncachedTime, $cachedSecondTime, 'Cached discovery should be faster than uncached');

            // Additional assertions to ensure meaningful performance improvement
            $speedImprovement = $uncachedTime / $cachedSecondTime;
            $this->assertGreaterThan(10, $speedImprovement, 'Cached discovery should be at least 10x faster');

            // Verify that first cached call is also faster than uncached
            $this->assertLessThan($uncachedTime, $cachedFirstTime, 'First cached call should be faster than uncached');
        } finally {
            // Clean up
            $this->removeDirectory($tempDir);
        }
    }

    private function createTestFiles(string $tempDir): void
    {
        $files = [
            'TestTool1.php' => '<?php
use Mcp\Capability\Attribute\McpTool;

class TestTool1 {
    #[McpTool(name: "test_tool_1")]
    public function testTool1(): string {
        return "test";
    }
}',
            'TestTool2.php' => '<?php
use Mcp\Capability\Attribute\McpTool;

class TestTool2 {
    #[McpTool(name: "test_tool_2")]
    public function testTool2(): string {
        return "test";
    }
}',
            'TestResource.php' => '<?php
use Mcp\Capability\Attribute\McpResource;

class TestResource {
    #[McpResource(uri: "test://resource")]
    public function testResource(): string {
        return "test";
    }
}',
        ];

        foreach ($files as $filename => $content) {
            file_put_contents($tempDir.'/'.$filename, $content);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
