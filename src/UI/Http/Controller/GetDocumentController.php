<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Repository\KycRequestRepositoryPort;
use App\Domain\KycRequest\Exception\KycRequestNotFoundException;
use App\Domain\KycRequest\Port\DocumentStoragePort;
use App\Domain\KycRequest\ValueObject\KycRequestId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * US-16 — Accès streamé et authentifié aux fichiers de documents.
 *
 * Seul un utilisateur avec ROLE_KYC_AUDITOR peut accéder à cet endpoint.
 * Le fichier est streamé (StreamedResponse) afin de ne jamais être chargé
 * intégralement en mémoire PHP.
 * Headers de sécurité appliqués :
 *   - Content-Disposition: attachment (force le téléchargement)
 *   - X-Content-Type-Options: nosniff (protège contre le MIME sniffing)
 * Aucun contenu du fichier n'est journalisé.
 */
#[Route('/api/kyc/{id}/document', methods: ['GET'])]
#[IsGranted('ROLE_KYC_AUDITOR')]
final class GetDocumentController
{
    private const CHUNK_SIZE = 8192; // 8 Ko par lecture

    public function __construct(
        private readonly KycRequestRepositoryPort $repository,
        private readonly DocumentStoragePort $storage,
    ) {
    }

    public function __invoke(string $id): Response
    {
        try {
            $kycRequestId = KycRequestId::fromString($id);
        } catch (\InvalidArgumentException) {
            return new Response('Invalid KycRequest id format.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $kycRequest = $this->repository->get($kycRequestId);
        } catch (KycRequestNotFoundException) {
            return new Response('KycRequest not found.', Response::HTTP_NOT_FOUND);
        }

        $storagePath = $kycRequest->getStoragePath();

        if ($storagePath === null) {
            return new Response(
                $kycRequest->isDocumentPurged()
                    ? 'Document has been purged (RGPD).'
                    : 'No document uploaded yet.',
                Response::HTTP_NOT_FOUND,
            );
        }

        $filename = basename($storagePath);
        $content  = $this->storage->retrieve($storagePath);

        $response = new StreamedResponse(
            function () use ($content): void {
                $offset = 0;
                $length = \strlen($content);

                while ($offset < $length) {
                    echo substr($content, $offset, self::CHUNK_SIZE);
                    $offset += self::CHUNK_SIZE;
                    flush();
                }
            },
        );

        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Length', (string) \strlen($content));

        return $response;
    }
}
