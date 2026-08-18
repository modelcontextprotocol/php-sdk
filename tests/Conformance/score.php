<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Turns a conformance run's results into a shields.io endpoint badge JSON, so
 * the client/server conformance score can be rendered in the README.
 *
 *   php score.php <server|client> <spec-version> [results-dir]
 *
 * The conformance CLI writes one `checks.json` per scenario into
 * `[results-dir]` (default `results`), relative to this file. A scenario
 * counts as passing when none of its checks has a FAILURE status; the badge
 * message is "<passed>/<total> (<pct>%)" and is written to
 * `<suite>-conformance-<spec-version>.json`, so each revision gets its own
 * badge.
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

(new SingleCommandApplication())
    ->setName('conformance-score')
    ->setDescription('Generates a shields.io endpoint badge from the conformance results')
    ->addArgument('suite', InputArgument::REQUIRED, 'Which conformance suite was run: "server" or "client"')
    ->addArgument('spec-version', InputArgument::REQUIRED, 'The spec revision that was run, e.g. "2025-11-25"')
    ->addArgument('results-dir', InputArgument::OPTIONAL, 'Directory the conformance CLI wrote results into, relative to this file', 'results')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $suite = $input->getArgument('suite');
        $specVersion = $input->getArgument('spec-version');

        if (!in_array($suite, ['server', 'client'], true)) {
            $io->error(sprintf('Suite must be "server" or "client", got "%s".', $suite));

            return Command::INVALID;
        }

        $resultsDir = __DIR__.'/'.$input->getArgument('results-dir');

        if (!is_dir($resultsDir)) {
            $io->error(sprintf('Results directory "%s" does not exist; run the conformance suite with `--output-dir` first.', $resultsDir));

            return Command::FAILURE;
        }

        $total = 0;
        $passed = 0;
        $failures = [];

        foreach (Finder::create()->files()->name('checks.json')->in($resultsDir) as $file) {
            $checks = json_decode($file->getContents(), true);

            if (!is_array($checks)) {
                $io->warning(sprintf('Skipping unreadable result file "%s".', $file->getRelativePathname()));

                continue;
            }

            foreach ($checks as $check) {
                switch ($check['status'] ?? null) {
                    case 'FAILURE':
                        $failures[] = $file->getRelativePath();
                        break;
                    case 'SUCCESS':
                        ++$passed;
                        break;
                    default:
                        continue 2;
                }

                ++$total;
            }
        }

        $pct = $total > 0 ? (int) round($passed / $total * 100) : 0;

        $badge = [
            'schemaVersion' => 1,
            'label' => sprintf('%s %s', $suite, $specVersion),
            'message' => $total > 0 ? sprintf('%d/%d (%d%%)', $passed, $total, $pct) : 'no data',
            'color' => match (true) {
                0 === $total => 'lightgrey',
                $pct >= 95 => 'brightgreen',
                $pct >= 80 => 'green',
                $pct >= 60 => 'yellow',
                default => 'orange',
            },
        ];

        $outputFile = __DIR__.'/'.$suite.'-conformance-'.$specVersion.'.json';

        if (false === file_put_contents($outputFile, json_encode($badge, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n")) {
            $io->error(sprintf('Could not write badge file "%s".', $outputFile));

            return Command::FAILURE;
        }

        if ($failures && $io->isVerbose()) {
            $io->section('Failing scenarios');
            $io->listing($failures);
        }

        $io->success(sprintf('%s: %s', $badge['label'], $badge['message']));

        return Command::SUCCESS;
    })
    ->run();
