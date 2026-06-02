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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/**
 * A protected MCP tool. Reaching it proves the request carried a valid access
 * token issued by this server's own authorization endpoints.
 */
final class McpElements
{
    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'whoami',
        description: 'Return the authenticated subject and scopes from the access token.'
    )]
    public function whoami(RequestContext $context): array
    {
        $meta = $context->getRequest()->getMeta() ?? [];
        $oauth = isset($meta['oauth']) && \is_array($meta['oauth']) ? $meta['oauth'] : [];
        $claims = isset($oauth['oauth.claims']) && \is_array($oauth['oauth.claims']) ? $oauth['oauth.claims'] : [];
        $scopes = isset($oauth['oauth.scopes']) && \is_array($oauth['oauth.scopes']) ? $oauth['oauth.scopes'] : [];

        return [
            'authenticated' => true,
            'subject' => $oauth['oauth.subject'] ?? ($claims['sub'] ?? null),
            'client_id' => $oauth['oauth.client_id'] ?? ($claims['client_id'] ?? null),
            'scopes' => $scopes,
            'issuer' => $claims['iss'] ?? null,
        ];
    }
}
