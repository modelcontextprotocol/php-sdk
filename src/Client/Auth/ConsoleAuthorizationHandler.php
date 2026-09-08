<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Mcp\Exception\AuthorizationException;

/**
 * Prints the authorization URL and reads back whatever the browser landed on.
 *
 * The fallback for environments where nothing can listen on a loopback port -- a remote
 * shell, a container without a browser, a redirect URI the authorization server insists
 * must be a real https address. The user pastes the whole redirected URL rather than
 * just the code, so `state` and `iss` survive the round trip and can still be checked --
 * a bare code would leave both with nothing to compare against, and is refused.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ConsoleAuthorizationHandler implements AuthorizationHandlerInterface
{
    /**
     * @param resource $input  stream the redirected URL is read from
     * @param resource $output stream the prompt is written to
     */
    public function __construct(
        private readonly mixed $input = \STDIN,
        private readonly mixed $output = \STDERR,
    ) {
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        fwrite($this->output, \sprintf(
            "\nOpen this URL to authorize the application:\n\n  %s\n\nThen paste the full URL you were redirected to: ",
            $authorizationUrl,
        ));

        $answer = fgets($this->input);

        if (false === $answer || '' === trim($answer)) {
            throw new AuthorizationException('No authorization response was provided.');
        }

        $answer = trim($answer);
        $query = parse_url($answer, \PHP_URL_QUERY);

        if (!\is_string($query) || '' === $query) {
            // A bare code cannot carry the state and issuer the response is checked
            // against, so accepting one would quietly skip both checks.
            throw new AuthorizationException('Paste the whole URL you were redirected to, not just the code: the rest of it is what proves the response belongs to this request.');
        }

        parse_str($query, $parameters);

        return array_map(strval(...), array_filter($parameters, is_scalar(...)));
    }
}
