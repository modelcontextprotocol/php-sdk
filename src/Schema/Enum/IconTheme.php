<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

/**
 * The background an icon is designed to be displayed against.
 *
 * When absent, the icon is assumed to work against any background.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum IconTheme: string
{
    case Light = 'light';
    case Dark = 'dark';
}
