<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Suspension;

use Mcp\Schema\JsonRpc\Request;

/**
 * Payload a handler fiber suspends with to send a request to the client
 * and wait for its answer.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RequestSuspension
{
    /**
     * @param int         $timeout  maximum time to wait for the response (seconds)
     * @param string|null $inputKey the name an elicitation's answer is filed under when
     *                              the revision serving the call answers by asking
     *                              ({@see \Mcp\Server\Stateless\ElicitationReplay});
     *                              ignored by every leg that has a live client to ask
     */
    public function __construct(
        public readonly Request $request,
        public readonly string $sessionId,
        public readonly int $timeout = 120,
        public readonly ?string $inputKey = null,
    ) {
    }
}
