<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\JsonRpc\Request;

/**
 * A request from the server to elicit additional information from the user.
 *
 * Comes in two modes. In `form` mode the client presents the message alongside
 * the requested schema and returns the values the user filled in. In `url` mode
 * it sends the user to a URL to complete the interaction out of band, and the
 * result carries only the user's action — no content.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ElicitRequest extends Request
{
    /**
     * @param string             $message         A human-readable message describing what information is needed
     * @param ?ElicitationSchema $requestedSchema The schema defining the fields to elicit; required in form mode
     * @param ElicitationMode    $mode            how the client should collect the information
     * @param ?string            $url             the URL to send the user to; required in url mode
     */
    public function __construct(
        public readonly string $message,
        public readonly ?ElicitationSchema $requestedSchema = null,
        public readonly ElicitationMode $mode = ElicitationMode::Form,
        public readonly ?string $url = null,
    ) {
        if (ElicitationMode::Form === $mode && null === $requestedSchema) {
            throw new InvalidArgumentException('Form elicitation requires a "requestedSchema".');
        }

        if (ElicitationMode::Url === $mode && (null === $url || '' === $url)) {
            throw new InvalidArgumentException('URL elicitation requires a non-empty "url".');
        }
    }

    /**
     * Elicit values from the user through a form built from $requestedSchema.
     */
    public static function forForm(string $message, ElicitationSchema $requestedSchema): self
    {
        return new self($message, $requestedSchema);
    }

    /**
     * Send the user to $url to complete the interaction out of band.
     */
    public static function forUrl(string $message, string $url): self
    {
        return new self($message, null, ElicitationMode::Url, $url);
    }

    public static function getMethod(): string
    {
        return 'elicitation/create';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['message']) || !\is_string($params['message'])) {
            throw new InvalidArgumentException('Missing or invalid "message" parameter for elicitation/create.');
        }

        // `mode` is absent on requests from servers predating URL elicitation,
        // where the form shape was the only one available.
        $mode = ElicitationMode::Form;
        if (isset($params['mode'])) {
            if (!\is_string($params['mode']) || null === $mode = ElicitationMode::tryFrom($params['mode'])) {
                throw new InvalidArgumentException('Invalid "mode" parameter for elicitation/create.');
            }
        }

        if (ElicitationMode::Url === $mode) {
            if (!isset($params['url']) || !\is_string($params['url'])) {
                throw new InvalidArgumentException('Missing or invalid "url" parameter for url-mode elicitation/create.');
            }

            return new self($params['message'], null, $mode, $params['url']);
        }

        if (!isset($params['requestedSchema']) || !\is_array($params['requestedSchema'])) {
            throw new InvalidArgumentException('Missing or invalid "requestedSchema" parameter for elicitation/create.');
        }

        return new self($params['message'], ElicitationSchema::fromArray($params['requestedSchema']), $mode);
    }

    /**
     * @return array{
     *     message: string,
     *     mode?: string,
     *     requestedSchema?: ElicitationSchema,
     *     url?: string,
     * }
     */
    protected function getParams(): array
    {
        if (ElicitationMode::Url === $this->mode) {
            return [
                'message' => $this->message,
                'mode' => $this->mode->value,
                'url' => $this->url,
            ];
        }

        // `mode` is left off for form elicitation: it is optional on that shape,
        // and omitting it keeps the request readable by clients predating the
        // mode discriminator.
        return [
            'message' => $this->message,
            'requestedSchema' => $this->requestedSchema,
        ];
    }
}
