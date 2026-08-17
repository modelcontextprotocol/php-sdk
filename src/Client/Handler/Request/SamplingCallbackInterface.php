<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Handler\Request;

use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

/**
 * Contract for callbacks used by SamplingRequestHandler.
 *
 * Implementations perform the actual LLM sampling when requested by the server.
 *
 * @deprecated Deprecated as of protocol revision 2026-07-28 (SEP-2577). Still functional for at
 * least twelve months. Integrate with an LLM provider's API directly instead.
 */
interface SamplingCallbackInterface
{
    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult;
}
