<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Dto\PublicLinkIssued;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Security\DmsScopeGuard;
use App\Dms\Service\PublicLinkTokenGenerator;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /documents/{documentId}/public-links` (corps JSON, RG-DMS-05/06/08) — émission d'un lien
 * public : jeton en clair porté **une seule fois** par le DTO de sortie `PublicLinkIssued`. `read:
 * false` sur l'opération (§2.3 du plan) : le document parent est résolu manuellement ici, avec
 * revérification explicite du périmètre (RG-DMS-02, CA-2) — jamais de confiance dans l'id brut de
 * l'URL.
 *
 * @implements ProcessorInterface<mixed, PublicLinkIssued>
 */
final class IssuePublicLinkProcessor implements ProcessorInterface
{
    private const TENTATIVES_MAX_COLLISION = 2;

    public function __construct(
        private readonly DmsScopeGuard $scopeGuard,
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PublicLinkTokenGenerator $tokenGenerator,
        private readonly EventBus $eventBus,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PublicLinkIssued
    {
        $documentIdRaw = $uriVariables['documentId'] ?? null;
        // API Platform type la variable d'après l'identifiant de l'entité liée (`Uuid`), pas une
        // chaîne — même piège documenté sur `App\Support\State\MessageTicketProcessor`. On accepte
        // les deux plutôt que de dépendre de la forme exacte produite.
        $documentIdString = (\is_string($documentIdRaw) || $documentIdRaw instanceof \Stringable)
            ? (string) $documentIdRaw
            : null;
        if ($documentIdString === null || !Uuid::isValid($documentIdString)) {
            throw new NotFoundHttpException('dms.error.document_not_found');
        }

        /** @var Document|null $document */
        $document = $this->em->find(Document::class, Uuid::fromString($documentIdString));
        $etablissement = $this->scopeGuard->verify($document?->getEstablishment());
        \assert($document instanceof Document);
        \assert($etablissement instanceof Etablissement);

        $corps = $this->lecteur->corps();
        $versionIdRaw = $corps['versionId'] ?? null;
        $expiresInDaysRaw = $corps['expiresInDays'] ?? 7;
        $expiresInDays = \is_int($expiresInDaysRaw)
            ? $expiresInDaysRaw
            : (\is_numeric($expiresInDaysRaw) ? (int) $expiresInDaysRaw : 7);
        if ($expiresInDays < 1 || $expiresInDays > 30) {
            throw new UnprocessableEntityHttpException('dms.error.expires_in_days_out_of_range : 1 à 30 jours (RG-DMS-06).');
        }

        if (\is_string($versionIdRaw) && $versionIdRaw !== '') {
            if (!Uuid::isValid($versionIdRaw)) {
                throw new UnprocessableEntityHttpException('dms.error.version_id_invalid');
            }
            $version = $this->em->find(DocumentVersion::class, Uuid::fromString($versionIdRaw));
            if (!$version instanceof DocumentVersion || $version->getDocument()?->getId()->equals($document->getId()) !== true) {
                throw new UnprocessableEntityHttpException('dms.error.version_not_found');
            }
        } else {
            $version = $document->getCurrentVersion();
        }
        \assert($version instanceof DocumentVersion);

        $acteur = $this->security->getUser();
        if (!$acteur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('dms.error.actor_required');
        }

        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d days', $expiresInDays));

        $token = null;
        $lien = null;
        for ($tentative = 0; $tentative <= self::TENTATIVES_MAX_COLLISION; ++$tentative) {
            $token = $this->tokenGenerator->generateToken();
            $hash = $this->tokenGenerator->hash($token);
            $lien = new DocumentPublicLink($document, $version, $hash, $expiresAt, $acteur);
            $this->em->persist($lien);
            try {
                $this->em->flush();
                break;
            } catch (UniqueConstraintViolationException $e) {
                $this->em->detach($lien);
                $lien = null;
                if ($tentative === self::TENTATIVES_MAX_COLLISION) {
                    throw new \RuntimeException('dms.error.public_link_token_collision', 0, $e);
                }
            }
        }
        \assert($lien instanceof DocumentPublicLink && \is_string($token));

        $this->eventBus->publish(new DomainEvent(
            'document.public_link_issued',
            new EventTenant($etablissement->getId()),
            new EventSubject('Document', (string) $document->getId()),
            [
                'versionId' => (string) $version->getId(),
                'publicLinkId' => (string) $lien->getId(),
                'expiresAt' => $expiresAt->format(DATE_ATOM),
            ],
            new EventActor($acteur->getId()),
        ));

        return new PublicLinkIssued(
            (string) $lien->getId(),
            '/dms/public/' . $token,
            $expiresAt->format(DATE_ATOM),
            (string) $version->getId(),
        );
    }
}
