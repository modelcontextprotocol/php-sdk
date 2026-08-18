<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Server\Stateless\StatelessProtocol;

/**
 * A transport that can carry the modern (SEP-2575) lifecycle as well as the
 * handshake one.
 *
 * {@see \Mcp\Server::run()} hands such a transport both dispatchers; deciding
 * which of them answers is the transport's job, because the evidence is in the
 * request it just read.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface StatelessAwareTransportInterface
{
    public function connectStateless(StatelessProtocol $protocol): void;
}
