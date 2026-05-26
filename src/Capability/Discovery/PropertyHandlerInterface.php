<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

/**
 * Base contract for handlers that teach the SDK about a specific PHP class type.
 *
 * A handler declares the class (or base class / interface) it handles via
 * {@see self::supportedClass()}; a parameter, property or return type is
 * dispatched to it when its type is that class or any subtype of it. The
 * concern-specific behaviour lives in the sub-interfaces:
 * {@see PropertyDescriberInterface} (type → JSON Schema),
 * {@see PropertyDenormalizerInterface} (client input → instance) and
 * {@see PropertyNormalizerInterface} (instance → JSON output). A single class
 * may implement any combination of them.
 */
interface PropertyHandlerInterface
{
    /**
     * The class or interface this handler covers. Types that are the class
     * itself or any subtype of it are dispatched to this handler.
     *
     * @return class-string
     */
    public static function supportedClass(): string;
}
