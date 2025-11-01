<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

/**
 * The contents of a specific resource or sub-resource.
 *
 * @phpstan-type ResourceContentsData = array{
 *     uri: string,
 *     mimeType?: string|null
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class ResourceContents implements \JsonSerializable
{
    /**
     * @param string      $uri      the URI of the resource or sub-resource
     * @param string|null $mimeType the MIME type of the resource or sub-resource
     * @param ?array      $_meta    Optional metadata
     */
    public function __construct(
        public readonly string $uri,
        public readonly ?string $mimeType = null,
        public readonly ?array $_meta = null,
    ) {
    }

    /**
     * @return ResourceContentsData
     */
    public function jsonSerialize(): array
    {
        $data = ['uri' => $this->uri];
        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->_meta) {
            $data['_meta'] = $this->_meta;
        }

        return $data;
    }
}
