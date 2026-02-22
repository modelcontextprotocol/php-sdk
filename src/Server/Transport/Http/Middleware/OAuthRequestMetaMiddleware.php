<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Copies OAuth request attributes to JSON-RPC request meta.
 *
 * This middleware bridges HTTP-layer authorization attributes (oauth.*) to
 * JSON-RPC `_meta.oauth` so tools can access them via RequestContext.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class OAuthRequestMetaMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?StreamFactoryInterface $streamFactory = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return $handler->handle($request);
        }

        $oauthMeta = $this->extractOAuthAttributes($request);
        if ([] === $oauthMeta) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();
        if ('' === trim($body)) {
            return $handler->handle($request);
        }

        try {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $handler->handle($request);
        }

        $updatedPayload = $this->injectOauthMeta($payload, $oauthMeta);
        if (null === $updatedPayload) {
            return $handler->handle($request);
        }

        try {
            $updatedBody = json_encode($updatedPayload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return $handler->handle($request);
        }

        $request = $request->withBody($this->getStreamFactory()->createStream($updatedBody));

        return $handler->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractOAuthAttributes(ServerRequestInterface $request): array
    {
        $result = [];
        foreach ($request->getAttributes() as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'oauth.')) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $oauthMeta
     */
    private function injectOauthMeta(mixed $payload, array $oauthMeta): mixed
    {
        if (!\is_array($payload)) {
            return null;
        }

        if (array_is_list($payload)) {
            $updated = [];
            foreach ($payload as $entry) {
                if (!\is_array($entry)) {
                    $updated[] = $entry;
                    continue;
                }

                $updated[] = $this->injectIntoMessage($entry, $oauthMeta);
            }

            return $updated;
        }

        return $this->injectIntoMessage($payload, $oauthMeta);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $oauthMeta
     *
     * @return array<string, mixed>
     */
    private function injectIntoMessage(array $message, array $oauthMeta): array
    {
        $params = $message['params'] ?? [];
        if (!\is_array($params)) {
            return $message;
        }

        $meta = $params['_meta'] ?? [];
        if (!\is_array($meta)) {
            $meta = [];
        }

        $existingOAuth = $meta['oauth'] ?? [];
        if (!\is_array($existingOAuth)) {
            $existingOAuth = [];
        }

        $meta['oauth'] = array_merge($existingOAuth, $oauthMeta);
        $params['_meta'] = $meta;
        $message['params'] = $params;

        return $message;
    }

    private function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
    }
}
