<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class MiddlewareTestCase extends TestCase
{
    protected Psr17Factory $factory;
    protected RequestHandlerInterface $passthroughHandler;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->passthroughHandler = $this->handlerReturning(200);
    }

    /**
     * @param array<string, string> $headers extra headers to set on the response (already-set CORS headers etc.)
     */
    protected function handlerReturning(int $status, array $headers = []): RequestHandlerInterface
    {
        return new class($this->factory, $status, $headers) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(
                private ResponseFactoryInterface $factory,
                private int $status,
                private array $headers,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->factory->createResponse($this->status);
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }
}
