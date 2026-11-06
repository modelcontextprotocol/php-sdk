<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Schema\Annotations;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait TextContentFactories
{
    public static function code(string $code, string $language = '', ?Annotations $annotations = null): self
    {
        return new self(text: "```{$language}\n{$code}\n```", annotations: $annotations);
    }

    /**
     * Arrays and objects become their JSON encoding, everything else its string form.
     */
    private static function stringifyText(mixed $text): string
    {
        return \is_array($text) || \is_object($text) ? (string) json_encode($text) : (string) $text;
    }
}
