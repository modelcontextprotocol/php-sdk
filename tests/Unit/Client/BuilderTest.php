<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client;
use Mcp\Client\Configuration;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    #[TestDox('setClientInfo() forwards the title to the advertised clientInfo')]
    public function testSetClientInfoForwardsTitle(): void
    {
        $client = Client::builder()
            ->setClientInfo('my-client', '1.0.0', 'A test client', 'My Client')
            ->build();

        $clientInfo = $this->extractConfiguration($client)->clientInfo;

        $this->assertSame('my-client', $clientInfo->name);
        $this->assertSame('1.0.0', $clientInfo->version);
        $this->assertSame('A test client', $clientInfo->description);
        $this->assertSame('My Client', $clientInfo->title);
    }

    #[TestDox('setClientInfo() leaves the title absent when it is not given')]
    public function testSetClientInfoTitleIsOptional(): void
    {
        $client = Client::builder()
            ->setClientInfo('my-client', '1.0.0')
            ->build();

        $this->assertNull($this->extractConfiguration($client)->clientInfo->title);
    }

    private function extractConfiguration(Client $client): Configuration
    {
        $config = (new \ReflectionClass($client))->getProperty('config')->getValue($client);
        $this->assertInstanceOf(Configuration::class, $config);

        return $config;
    }
}
