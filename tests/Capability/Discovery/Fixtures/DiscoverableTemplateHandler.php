<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Tests\Capability\Attribute\CompletionProviderFixture;

class DiscoverableTemplateHandler
{
    /**
     * Retrieves product details based on ID and region.
     *
     * @param string $productId the ID of the product
     * @param string $region    the sales region
     *
     * @return array product details
     */
    #[McpResourceTemplate(
        uriTemplate: 'product://{region}/details/{productId}',
        name: 'product_details_template',
        mimeType: 'application/json'
    )]
    public function getProductDetails(
        string $productId,
        #[CompletionProvider(provider: CompletionProviderFixture::class)]
        string $region,
    ): array {
        return [
            'id' => $productId,
            'name' => 'Product '.$productId,
            'region' => $region,
            'price' => ('EU' === $region ? '€' : '$').(hexdec(substr(md5($productId), 0, 4)) / 100),
        ];
    }

    #[McpResourceTemplate(uriTemplate: 'file://{path}/{filename}.{extension}')]
    public function getFileContent(string $path, string $filename, string $extension): string
    {
        return "Content of {$path}/{$filename}.{$extension}";
    }
}
