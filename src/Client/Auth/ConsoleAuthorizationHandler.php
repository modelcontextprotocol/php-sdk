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
 * Prints the authorization URL and reads the redirected URL back, for a shell without a browser.
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
            throw new AuthorizationException('Paste the whole URL you were redirected to, not just the code: the rest of it is what proves the response belongs to this request.');
        }

        $callback = AuthorizationCallback::parameters($query);

        if (!isset($callback['code']) && !isset($callback['error'])) {
            throw new AuthorizationException('The pasted URL carries neither a code nor an error. Paste the URL you were redirected to after signing in, not the one you opened.');
        }

        return $callback;
    }
}
