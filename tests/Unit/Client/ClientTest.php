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
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\ClientCapabilities;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testSendRootsListChangedSendsNotificationWhenCapabilityDeclared(): void
    {
        $sent = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(static function (string $data) use (&$sent): void {
            $sent[] = $data;
        });

        $client = Client::builder()
            ->setClientInfo('Roots Test', '1.0.0')
            ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true))
            ->build();

        $client->connect($transport);
        $client->sendRootsListChanged();

        $this->assertCount(1, $sent);
        $decoded = json_decode($sent[0], true);
        $this->assertSame('notifications/roots/list_changed', $decoded['method']);
    }

    public function testSendRootsListChangedThrowsWhenCapabilityNotDeclared(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->never())->method('send');

        $client = Client::builder()
            ->setClientInfo('Roots Test', '1.0.0')
            ->setCapabilities(new ClientCapabilities(roots: true))
            ->build();

        $client->connect($transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('roots.listChanged');

        $client->sendRootsListChanged();
    }
}
