<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Usage: php tools/schema-generator/bin/generate.php [--check]
//   --check  regenerate into a temporary directory and fail if the committed classes differ

use Mcp\Tools\SchemaGenerator\Generator;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

$check = in_array('--check', $argv, true);
$generator = Generator::fromConfigFile(dirname(__DIR__).'/config.php');
$files = $generator->generate();

$target = $check ? sys_get_temp_dir().'/mcp-schema-generator-'.bin2hex(random_bytes(4)) : $generator->outputDirectory();
$generator->write($target, $files);

$fixer = sprintf(
    '%s fix --quiet --using-cache=no --config=%s --path-mode=override %s',
    escapeshellarg($root.'/vendor/bin/php-cs-fixer'),
    escapeshellarg($root.'/.php-cs-fixer.dist.php'),
    escapeshellarg($target),
);
passthru($fixer, $exitCode);
if (0 !== $exitCode) {
    fwrite(\STDERR, "php-cs-fixer failed.\n");
    exit(1);
}

if (!$check) {
    printf("Generated %d files into %s\n", count($files), $target);
    exit(0);
}

$outdated = [];
foreach (array_keys($files) as $path) {
    $committed = @file_get_contents($generator->outputDirectory().'/'.$path);
    if ($committed !== file_get_contents($target.'/'.$path)) {
        $outdated[] = $path;
    }
}
$committedFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($generator->outputDirectory(), FilesystemIterator::SKIP_DOTS));
foreach ($committedFiles as $file) {
    $path = substr($file->getPathname(), strlen($generator->outputDirectory()) + 1);
    if (!isset($files[$path])) {
        $outdated[] = $path.' (no longer generated)';
    }
}

$temporary = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($temporary as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($target);

if ([] !== $outdated) {
    fwrite(\STDERR, "Generated schema classes are out of date, run `make schema-generate`:\n  ".implode("\n  ", $outdated)."\n");
    exit(1);
}

echo "Generated schema classes are up to date.\n";
