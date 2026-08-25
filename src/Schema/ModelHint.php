<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

/**
 * Hints to use for model selection.
 *
 * Keys not declared here are currently left unspecified by the spec and are up to the client to interpret.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
class ModelHint implements \JsonSerializable
{
    /**
     * @param string|null $name A hint for a model name.
     *
     * The client SHOULD treat this as a substring of a model name; for example:
     *  - `claude-3-5-sonnet` should match `claude-3-5-sonnet-20241022`
     *  - `sonnet` should match `claude-3-5-sonnet-20241022`, `claude-3-sonnet-20240229`, etc.
     *  - `claude` should match any Claude model
     *
     * The client MAY also map the string to a different provider's model name or a different model family, as long as it fills a similar niche; for example:
     *  - `gemini-1.5-flash` could match `claude-3-haiku-20240307`
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }

    /**
     * @return array{name: string}|array{}
     */
    public function jsonSerialize(): array
    {
        if (null === $this->name) {
            return [];
        }

        return ['name' => $this->name];
    }
}
