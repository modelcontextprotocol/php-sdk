<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Exception\RequestException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What a timed-out request leaves behind on the connection.
 *
 * @see Fixture/request_timeout.php for the server under test
 */
final class RequestTimeoutTest extends IntegrationTestCase
{
    #[TestDox('an abandoned response is not handed to the next request')]
    public function testTimedOutRequestDoesNotLeakIntoTheNext(): void
    {
        $client = $this->connect('request_timeout', $this->clientBuilder()->setRequestTimeout(1));

        try {
            $client->callTool('slow');
            $this->fail('The slow tool should have outlived the request timeout.');
        } catch (RequestException) {
        }

        // The server is still executing the abandoned call and cannot answer
        // anything until it returns, so the next request has to wait it out.
        sleep(2);

        // Without the pending request being cleared this answers 'late': the
        // response the client gave up on, handed to the request after it.
        $this->assertSame('quick', $client->callTool('fast')->content[0]->text ?? null);
    }
}
