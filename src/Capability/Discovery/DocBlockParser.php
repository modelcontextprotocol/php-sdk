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

namespace Mcp\Capability\Discovery;

use Throwable;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses DocBlocks using phpdocumentor/reflection-docblock.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class DocBlockParser
{
    private readonly DocBlockFactoryInterface $docBlockFactory;

    public function __construct(
        ?DocBlockFactoryInterface $docBlockFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->docBlockFactory = $docBlockFactory ?? DocBlockFactory::createInstance();
    }

    /**
     * Safely parses a DocComment string into a DocBlock object.
     */
    public function parseDocBlock(string|false|null $docComment): ?DocBlock
    {
        if (false === $docComment || null === $docComment || ('' === $docComment || '0' === $docComment)) {
            return null;
        }
        try {
            return $this->docBlockFactory->create($docComment);
        } catch (Throwable $e) {
            // Log error or handle gracefully if invalid DocBlock syntax is encountered
            $this->logger->warning('Failed to parse DocBlock', [
                'error' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Gets the summary line from a DocBlock.
     */
    public function getSummary(?DocBlock $docBlock): ?string
    {
        if (!$docBlock instanceof DocBlock) {
            return null;
        }
        $summary = trim($docBlock->getSummary());

        return $summary ?: null; // Return null if empty after trimming
    }

    /**
     * Gets the description from a DocBlock (summary + description body).
     */
    public function getDescription(?DocBlock $docBlock): ?string
    {
        if (!$docBlock instanceof DocBlock) {
            return null;
        }
        $summary = trim($docBlock->getSummary());
        $descriptionBody = trim((string) $docBlock->getDescription());

        if ($summary && $descriptionBody) {
            return $summary."\n\n".$descriptionBody;
        }
        if ('' !== $summary && '0' !== $summary) {
            return $summary;
        }
        if ('' !== $descriptionBody && '0' !== $descriptionBody) {
            return $descriptionBody;
        }

        return null;
    }

    /**
     * Extracts "@param" tag information from a DocBlock, keyed by variable name (e.g., '$paramName').
     *
     * @return array<string, Param>
     */
    public function getParamTags(?DocBlock $docBlock): array
    {
        if (!$docBlock instanceof DocBlock) {
            return [];
        }

        /** @var array<string, Param> $paramTags */
        $paramTags = [];
        foreach ($docBlock->getTagsByName('param') as $tag) {
            if ($tag instanceof Param && $tag->getVariableName()) {
                $paramTags['$'.$tag->getVariableName()] = $tag;
            }
        }

        return $paramTags;
    }

    /**
     * Gets the description string from a Param tag.
     */
    public function getParamDescription(?Param $paramTag): ?string
    {
        return $paramTag instanceof Param ? (trim((string) $paramTag->getDescription()) ?: null) : null;
    }

    /**
     * Gets the type string from a Param tag.
     */
    public function getParamTypeString(?Param $paramTag): ?string
    {
        if ($paramTag instanceof Param && $paramTag->getType() instanceof Type) {
            $typeFromTag = trim((string) $paramTag->getType());
            if ('' !== $typeFromTag && '0' !== $typeFromTag) {
                return ltrim($typeFromTag, '\\');
            }
        }

        return null;
    }
}
