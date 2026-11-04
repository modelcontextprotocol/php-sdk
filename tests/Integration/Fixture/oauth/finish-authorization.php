<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\RedirectAuthorizationHandler;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$auth = OAuth::forApplication('Integration Test Client')
    ->setAuthorizationHandler(new RedirectAuthorizationHandler())
    ->setCredentialStorage(new FileCredentialStorage($argv[1]))
    ->build();
$auth->finishAuthorization(json_decode($argv[2], true, flags: \JSON_THROW_ON_ERROR));

echo 'authorized';
