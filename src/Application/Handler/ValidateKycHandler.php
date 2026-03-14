<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\ValidateKyc;
use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Aggregate\KycRequest;
use App\Domain\KycRequest\Port\DomainEventPublisherPort;
use App\Domain\KycRequest\ValueObject\FailureReason;
use App\Domain\KycRequest\ValueObject\KycRequestId;

final class ValidateKycHandler
{
    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DomainEventPublisherPort $publisher,
    ) {
    }

    public function handle(ValidateKyc $command): void
    {
        $id = KycRequestId::fromString($command->kycRequestId);
        $kycRequest = $this->repository->get($id);

        $reasons = $this->collectViolations($kycRequest);

        if ([] === $reasons) {
            $kycRequest->approve();
        } else {
            $kycRequest->reject($reasons);
        }

        $events = $kycRequest->peekEvents();
        $this->repository->save($kycRequest);
        $this->publisher->publishAll($events);
    }

    /** @return FailureReason[] */
    private function collectViolations(KycRequest $kycRequest): array
    {
        $today = new \DateTimeImmutable('today');
        $reasons = [];

        // Vérifications bloquantes immédiates (court-circuitent la collecte)

        // Majorité — UNDERAGE_APPLICANT
        $birthDateStr = $kycRequest->getBirthDate();
        if (null === $birthDateStr) {
            return [new FailureReason('E_VAL_UNDERAGE', 'La date de naissance est absente. Nous ne pouvons pas confirmer que vous avez au moins 18 ans.')];
        }

        $birthDate = \DateTimeImmutable::createFromFormat('Y-m-d', $birthDateStr);
        if (false === $birthDate || $today->diff($birthDate)->y < 18) {
            return [new FailureReason('E_VAL_UNDERAGE', 'Vous devez avoir au moins 18 ans pour effectuer cette vérification.')];
        }

        // Expiration — DOCUMENT_EXPIRED
        $expiryDateStr = $kycRequest->getExpiryDate();
        if (null === $expiryDateStr) {
            return [new FailureReason('E_VAL_EXPIRED', 'La date d\'expiration est absente. Veuillez fournir un document en cours de validité.')];
        }

        $expiryDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expiryDateStr);
        if (false === $expiryDate || $expiryDate <= $today) {
            return [new FailureReason('E_VAL_EXPIRED', \sprintf('Votre document est expiré depuis le %s. Veuillez fournir un document en cours de validité.', $expiryDateStr))];
        }

        // Collecte des violations non bloquantes

        // Nom de famille
        $lastName = $kycRequest->getLastName();
        if (!$this->isValidName($lastName)) {
            $reasons[] = new FailureReason('E_VAL_NAME', 'Le nom de famille est invalide (2–50 caractères, lettres et tirets uniquement).');
        }

        // Prénom
        $firstName = $kycRequest->getFirstName();
        if (!$this->isValidName($firstName)) {
            $reasons[] = new FailureReason('E_VAL_NAME', 'Le prénom est invalide (2–50 caractères, lettres et tirets uniquement).');
        }

        // Numéro de document
        $documentId = $kycRequest->getDocumentId();
        if (null === $documentId || !preg_match('/^[A-Za-z0-9]{9,12}$/', $documentId)) {
            $reasons[] = new FailureReason('E_VAL_DOC_ID', 'Le numéro de document est invalide (9–12 caractères alphanumériques).');
        }

        // Code MRZ
        $mrz = $kycRequest->getMrz();
        if (null === $mrz || !$this->isValidMrz($mrz)) {
            $reasons[] = new FailureReason('E_VAL_MRZ', 'Le code MRZ est invalide (2 lignes de 30 ou 44 caractères).');
        }

        return $reasons;
    }

    private function isValidName(?string $name): bool
    {
        if (null === $name) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-zÀ-ÿ\-]{2,50}$/', $name);
    }

    private function isValidMrz(string $mrz): bool
    {
        $lines = explode("\n", trim($mrz));

        if (2 !== \count($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            $len = \strlen(trim($line));
            if (30 !== $len && 44 !== $len) {
                return false;
            }
        }

        return true;
    }
}
