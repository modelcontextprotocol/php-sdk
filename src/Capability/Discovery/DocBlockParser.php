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

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses DocBlocks using phpdocumentor/reflection-docblock.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class DocBlockParser
{
    private DocBlockFactoryInterface $docBlockFactory;

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
        if (false === $docComment || null === $docComment || empty($docComment)) {
            return null;
        }
        try {
            return $this->docBlockFactory->create($docComment);
        } catch (\Throwable $e) {
            // Log error or handle gracefully if invalid DocBlock syntax is encountered
            $this->logger->warning('Failed to parse DocBlock', [
                'error' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Gets the description from a DocBlock (summary + description body).
     */
    public function getDescription(?DocBlock $docBlock): ?string
    {
        if (!$docBlock) {
            return null;
        }
        $summary = trim($docBlock->getSummary());
        $descriptionBody = trim((string) $docBlock->getDescription());

        if ($summary && $descriptionBody) {
            return $summary."\n\n".$descriptionBody;
        }
        if ($summary) {
            return $summary;
        }
        if ($descriptionBody) {
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
        if (!$docBlock) {
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
        return $paramTag ? (trim((string) $paramTag->getDescription()) ?: null) : null;
    }

    /**
     * Gets the type string from a Param tag.
     */
    public function getParamTypeString(?Param $paramTag): ?string
    {
        if ($paramTag && $paramTag->getType()) {
            $typeFromTag = trim((string) $paramTag->getType());
            if (!empty($typeFromTag)) {
                return ltrim($typeFromTag, '\\');
            }
        }

        return null;
    }
}
