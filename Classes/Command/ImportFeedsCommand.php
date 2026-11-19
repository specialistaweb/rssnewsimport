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
 * Importa i feed RSS/Atom configurati in EXT:news.
 *
 * CLI:        vendor/bin/typo3 rssnewsimport:import [--feed=1,2] [--force] [--dry-run]
 * Scheduler:  task "Execute console commands" → rssnewsimport:import
 *
 * Lo Scheduler può girare spesso (es. ogni 10 minuti): ogni feed viene contattato solo quando
 * è trascorso il suo "intervallo di controllo".
 */
#[AsCommand(
    name: 'rssnewsimport:import',
    description: 'Importa i feed RSS/Atom configurati nelle news di EXT:news.'
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
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Uid dei feed da importare, separati da virgola (default: tutti i feed attivi)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora intervallo, backoff e cache HTTP (scarica e rielabora comunque)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula l\'importazione senza scrivere nel database')
            ->setHelp(
                "Scarica i feed configurati come record \"Feed RSS\" e crea/aggiorna le news di EXT:news.\n" .
                "Ogni feed viene contattato solo allo scadere del suo intervallo; dopo errori consecutivi l'attesa raddoppia (max 24h).\n" .
                "Gli elementi già importati non vengono duplicati; le news cancellate dalla redazione non vengono re-importate."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLocker(
            'rss_news_import',
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        try {
            $acquired = $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
        } catch (\Throwable) {
            $acquired = false;
        }
        if (!$acquired) {
            $io->note('Un\'altra importazione è già in corso: esecuzione saltata.');
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
                $io->writeln('Nessun feed da controllare in questo momento.', OutputInterface::VERBOSITY_VERBOSE);
                return Command::SUCCESS;
            }

            // Utente backend _cli_ necessario per DataHandler (inizializzato solo se serve)
            Bootstrap::initializeBackendAuthentication();
            $GLOBALS['LANG'] ??= $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);

            if ($dryRun) {
                $io->note('Modalità simulazione: nessuna modifica al database.');
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
                $io->warning($failed . ' feed non raggiungibili o non validi: verranno ritentati con attesa crescente.');
                // Errori di siti esterni non sono errori del sistema: non si fa fallire il task dello Scheduler
                // ogni volta, a meno che tutti i feed siano falliti.
                return $failed === count($feeds) ? Command::FAILURE : Command::SUCCESS;
            }
            $io->success('Importazione completata.');
            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
