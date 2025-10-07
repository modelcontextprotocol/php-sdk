<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Completion;

use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Result\CompletionCompleteResult;

/**
 * Provides completion options for prompt arguments and resource template variables.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface CompleterInterface
{
    public function complete(CompletionCompleteRequest $request): CompletionCompleteResult;
}
