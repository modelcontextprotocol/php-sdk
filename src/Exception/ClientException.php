<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

use Mcp\Schema\JsonRpc\Error;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class ClientException extends Exception
{
    public function __construct(
        private readonly Error $error,
    ) {
        parent::__construct($error->message);
    }

    public function getError(): Error
    {
        return $this->error;
    }
}
