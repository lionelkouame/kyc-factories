<?php

declare(strict_types=1);

namespace App\UI\Cli;

use App\Application\Command\RebuildProjection;
use App\Application\Handler\RebuildProjectionHandler;
use App\Application\Projection\KycAuditTrailProjectorPort;
use App\Application\Projection\KycRequestStatusProjectorPort;
use App\Application\Projection\PendingManualReviewProjectorPort;
use App\Application\Projection\ProjectorPort;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande CLI — UC-04 : reconstruction d'une projection depuis l'event store.
 *
 * Usage :
 *   bin/console kyc:projection:rebuild kyc_request_status
 *   bin/console kyc:projection:rebuild kyc_audit_trail
 *   bin/console kyc:projection:rebuild pending_manual_review
 */
#[AsCommand(
    name: 'kyc:projection:rebuild',
    description: 'Reconstruit une projection KYC par rejeu complet de l\'event store (UC-04).',
)]
final class RebuildProjectionCommand extends Command
{
    private const AVAILABLE_PROJECTORS = [
        'kyc_request_status',
        'kyc_audit_trail',
        'pending_manual_review',
    ];

    public function __construct(
        private readonly KycRequestStatusProjectorPort $kycRequestStatusProjector,
        private readonly KycAuditTrailProjectorPort $kycAuditTrailProjector,
        private readonly PendingManualReviewProjectorPort $pendingManualReviewProjector,
        private readonly \App\Domain\KycRequest\Port\EventStorePort $eventStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'projector',
            InputArgument::REQUIRED,
            sprintf(
                'Nom du projecteur à reconstruire. Valeurs disponibles : %s',
                implode(', ', self::AVAILABLE_PROJECTORS),
            ),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $projectorName */
        $projectorName = $input->getArgument('projector');

        $projector = $this->resolveProjector($projectorName);

        if ($projector === null) {
            $io->error(sprintf(
                'Projecteur inconnu "%s". Valeurs disponibles : %s',
                $projectorName,
                implode(', ', self::AVAILABLE_PROJECTORS),
            ));

            return Command::FAILURE;
        }

        $io->title(sprintf('Reconstruction de la projection "%s"', $projectorName));
        $io->text('Réinitialisation et rejeu de l\'event store en cours…');

        $handler = new RebuildProjectionHandler($this->eventStore, $projector);
        $handler->handle(new RebuildProjection($projectorName));

        $io->success(sprintf('Projection "%s" reconstruite avec succès.', $projectorName));

        return Command::SUCCESS;
    }

    private function resolveProjector(string $name): ?ProjectorPort
    {
        return match ($name) {
            'kyc_request_status' => $this->kycRequestStatusProjector,
            'kyc_audit_trail' => $this->kycAuditTrailProjector,
            'pending_manual_review' => $this->pendingManualReviewProjector,
            default => null,
        };
    }
}
