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

use Mcp\Schema\Generated\Annotations;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait TextContentFactories
{
    public static function code(string $code, string $language = '', ?Annotations $annotations = null): self
    {
        return new self(text: "```{$language}\n{$code}\n```", annotations: $annotations);
    }
}
