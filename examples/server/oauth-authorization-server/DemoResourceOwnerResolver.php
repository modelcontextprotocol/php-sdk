<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthAuthorizationServer;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server\Transport\Http\OAuth\ResourceOwner;
use Mcp\Server\Transport\Http\OAuth\ResourceOwnerResolverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Demo-only resource owner resolver.
 *
 * A real host authenticates the user against its own session/firewall. To keep
 * this example fully scriptable, an unauthenticated request is "logged in" as a
 * fixed demo user by setting a cookie and redirecting back to the authorize URL.
 *
 * DO NOT use this in production.
 */
final class DemoResourceOwnerResolver implements ResourceOwnerResolverInterface
{
    private const COOKIE = 'demo_user';

    private ResponseFactoryInterface $responseFactory;

    public function __construct(
        private readonly string $demoUserId = 'demo-user',
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
    }

    public function resolve(ServerRequestInterface $request): ?ResourceOwner
    {
        $user = $request->getCookieParams()[self::COOKIE] ?? null;
        if (!\is_string($user) || '' === $user) {
            return null;
        }

        return new ResourceOwner($user, ['name' => 'Demo User']);
    }

    public function onUnauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        // A real implementation renders/redirects to a login form. Here we
        // auto-login the demo user and resume the authorization request.
        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', (string) $request->getUri())
            ->withHeader('Set-Cookie', self::COOKIE.'='.rawurlencode($this->demoUserId).'; Path=/; HttpOnly; SameSite=Lax')
            ->withHeader('Cache-Control', 'no-store');
    }
}
