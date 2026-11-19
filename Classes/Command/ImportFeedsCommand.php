<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Command;

use SpecialistaWeb\RssNewsImport\Domain\Repository\FeedRepository;
use SpecialistaWeb\RssNewsImport\Service\FeedImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Imports the configured RSS/Atom feeds into EXT:news.
 *
 * CLI:        vendor/bin/typo3 rssnewsimport:import [--feed=1,2] [--force] [--dry-run]
 * Scheduler:  task "Execute console commands" → rssnewsimport:import
 *
 * The Scheduler may run often (e.g. every 10 minutes): every feed is contacted only when
 * its "check interval" has passed.
 */
#[AsCommand(
    name: 'rssnewsimport:import',
    description: 'Imports the configured RSS/Atom feeds into EXT:news.'
)]
final class ImportFeedsCommand extends Command
{
    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly FeedImporter $feedImporter,
        private readonly LockFactory $lockFactory,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Uids of the feeds to import, comma separated (default: all active feeds)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore interval, backoff and HTTP cache (download and process anyway)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the import without writing to the database')
            ->setHelp(
                "Downloads the feeds configured as \"RSS feed\" records and creates/updates EXT:news records.\n" .
                "Every feed is contacted only when its interval has passed; after consecutive errors the waiting time doubles (max 24h).\n" .
                "Items already imported are not duplicated; news deleted by the editors are not imported again."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLocker(
            'rssnewsimport',
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        try {
            $acquired = $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
        } catch (\Throwable) {
            $acquired = false;
        }
        if (!$acquired) {
            $io->note('Another import is already running: run skipped.');
            return Command::SUCCESS;
        }

        try {
            $uids = array_values(array_filter(array_map('intval', explode(',', (string)$input->getOption('feed')))));
            $dryRun = (bool)$input->getOption('dry-run');
            $force = (bool)$input->getOption('force');

            $now = time();
            $feeds = array_filter(
                $this->feedRepository->findActive($uids),
                fn(array $feed): bool => $force || $this->feedImporter->isDue($feed, $now)
            );
            if ($feeds === []) {
                $io->writeln('No feed to check at the moment.', OutputInterface::VERBOSITY_VERBOSE);
                return Command::SUCCESS;
            }

            // Backend user _cli_ needed by the DataHandler (initialised only when needed)
            Bootstrap::initializeBackendAuthentication();
            $GLOBALS['LANG'] ??= $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);

            if ($dryRun) {
                $io->note('Dry run: no changes to the database.');
            }

            $rows = [];
            $failed = 0;
            foreach ($feeds as $feed) {
                $result = $this->feedImporter->import($feed, $dryRun, $force);
                $rows[] = [
                    $result->feedUid, mb_strimwidth($result->feedTitle, 0, 40, '…'), $result->getStateLabel(),
                    $result->created, $result->updated, $result->skipped, $result->excluded, $result->images,
                ];
                foreach ($result->errors as $error) {
                    $io->writeln('<comment>[' . $result->feedUid . '] ' . $error . '</comment>');
                }
                $failed += (int)$result->failed;
            }

            $io->table(['Uid', 'Feed', 'Esito', 'Creati', 'Aggiornati', 'Saltati', 'Esclusi', 'Immagini'], $rows);

            if ($failed > 0) {
                $io->warning($failed . ' feed(s) unreachable or invalid: they will be retried with increasing waiting time.');
                // Errors of external sites are not system errors: the Scheduler task does not fail
                // every time, unless all feeds failed.
                return $failed === count($feeds) ? Command::FAILURE : Command::SUCCESS;
            }
            $io->success('Import completed.');
            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
