<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Exception\InvalidTransitionException;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande CLI — US-15 : purge RGPD des fichiers de documents bruts.
 *
 * Sélectionne toutes les demandes en état terminal (approved/rejected) dont
 * la décision date de plus de 30 jours, supprime le fichier via
 * DocumentStoragePort et enregistre l'événement DocumentPurged.
 *
 * Idempotente : une seconde exécution est sans effet (le document déjà purgé
 * ne remonte plus dans la projection puisque storagePath = null).
 *
 * Usage :
 *   bin/console kyc:documents:purge
 *   bin/console kyc:documents:purge --days=60
 *
 * CRON recommandé (quotidien à 2h00) :
 *   0 2 * * * php /app/bin/console kyc:documents:purge >> /var/log/kyc_purge.log 2>&1
 */
#[AsCommand(
    name: 'kyc:documents:purge',
    description: 'Supprime les fichiers bruts des demandes KYC décidées depuis plus de N jours (RGPD).',
)]
final class PurgeDocumentsCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private readonly KycRequestStatusProjectorPort $statusProjector,
        private readonly KycRequestRepositoryPort $repository,
        private readonly DocumentStoragePort $storage,
        private readonly DomainEventPublisherPort $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Rétention en jours avant purge (défaut : 30)',
            self::DEFAULT_RETENTION_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawDays = $input->getOption('days');
        $days    = \is_numeric($rawDays) ? (int) $rawDays : self::DEFAULT_RETENTION_DAYS;
        if ($days < 1) {
            $io->error('"days" doit être un entier positif.');

            return Command::FAILURE;
        }

        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));
        $candidates = $this->statusProjector->findTerminalOlderThan($threshold);

        if ($candidates === []) {
            $io->success(sprintf('Aucune demande à purger (seuil : %d jours).', $days));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Purge RGPD — seuil : %d jours — %d candidat(s)', $days, \count($candidates)));

        $purged  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($candidates as $view) {
            try {
                $id = KycRequestId::fromString($view->kycRequestId);
                $kycRequest = $this->repository->get($id);

                // Idempotence : le document est peut-être déjà purgé
                if ($kycRequest->isDocumentPurged()) {
                    ++$skipped;
                    continue;
                }

                $storagePath = $kycRequest->getStoragePath();

                $kycRequest->purgeDocument();

                $events = $kycRequest->peekEvents();
                $this->repository->save($kycRequest);
                $this->publisher->publishAll($events);

                // Suppression physique après persistance réussie
                if ($storagePath !== null) {
                    $this->storage->delete($storagePath);
                }

                ++$purged;
                $io->text(sprintf('  ✓ %s purgé.', $view->kycRequestId));
            } catch (InvalidTransitionException $e) {
                $io->warning(sprintf('  ⚠ %s ignoré : %s', $view->kycRequestId, $e->getMessage()));
                ++$skipped;
            } catch (\Throwable $e) {
                $io->error(sprintf('  ✗ %s — erreur : %s', $view->kycRequestId, $e->getMessage()));
                ++$errors;
            }
        }

        $io->success(sprintf(
            'Purge terminée : %d purgé(s), %d ignoré(s), %d erreur(s).',
            $purged,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
