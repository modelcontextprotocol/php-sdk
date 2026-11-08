<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Exception\SessionStoreException;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TransportShutdownTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testInMemoryTransportSurvivesAStoreOutageWhenListening(): void
    {
        $transport = new InMemoryTransport(logger: $this->logger);
        $this->failToEndSession($transport);

        $transport->listen();

        $this->assertStoreOutageIsLogged();
    }

    public function testInMemoryTransportSurvivesAStoreOutageWhenClosing(): void
    {
        $transport = new InMemoryTransport(logger: $this->logger);
        $this->failToEndSession($transport);

        $transport->close();

        $this->assertStoreOutageIsLogged();
    }

    public function testStdioTransportSurvivesAStoreOutageWhenListening(): void
    {
        $pipes = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pipes);
        fclose($pipes[0]);
        $transport = new StdioTransport($pipes[1], $this->stream(), $this->logger);
        $this->failToEndSession($transport);

        $this->assertSame(0, $transport->listen());

        $this->assertStoreOutageIsLogged();
    }

    public function testStdioTransportClosesItsStreamsDespiteAStoreOutage(): void
    {
        $input = $this->stream();
        $output = $this->stream();
        $transport = new StdioTransport($input, $output, $this->logger);
        $this->failToEndSession($transport);

        $transport->close();

        $this->assertStoreOutageIsLogged();
        $this->assertFalse(\is_resource($input));
        $this->assertFalse(\is_resource($output));
    }

    /**
     * @param TransportInterface<mixed> $transport
     */
    private function failToEndSession(TransportInterface $transport): void
    {
        $transport->setSessionId(Uuid::v4());
        $transport->onSessionEnd(static function (): bool {
            throw new SessionStoreException('Session store is unavailable.');
        });
    }

    private function assertStoreOutageIsLogged(): void
    {
        $this->assertSame(['Session store is unavailable, the session was not ended.'], $this->logger->messagesAt('error'));
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);

        return $stream;
    }
}
