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
 * Upcasts an incoming client value into a class-typed argument.
 *
 * The counterpart to {@see PropertyDescriberInterface}: where the describer
 * teaches the schema what a class type looks like on the wire, the
 * denormalizer turns the value the client actually sent back into a PHP
 * instance of that type before it is passed to the tool method. Without it, a
 * tool like `getTownShopList(Uuid $id)` would receive the raw string and fail.
 */
interface PropertyDenormalizerInterface extends PropertyHandlerInterface
{
    /**
     * @param mixed        $value the JSON-decoded value received from the client
     * @param class-string $class the concrete parameter type to produce (the class
     *                            itself or a subtype of {@see self::supportedClass()})
     *
     * @return mixed an instance of $class
     */
    public function denormalize(mixed $value, string $class): mixed;
}
