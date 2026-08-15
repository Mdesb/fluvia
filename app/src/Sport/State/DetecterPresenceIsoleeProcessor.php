<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\EspaceAcces;
use App\Sport\Entity\AlertePresenceIsolee;
use App\Sport\Service\DetecteurPresenceIsoleeHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/espaces/{id}/detecter-presence-isolee (US-SPORT-09, CA-11). Point d'extension : hook de
 * détection déclenché manuellement ici (⚠ en production, appelé par un capteur/comptage entrées-sorties
 * réel — hors périmètre logiciel de ce lot).
 *
 * @implements ProcessorInterface<mixed, AlertePresenceIsolee>
 */
final class DetecterPresenceIsoleeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DetecteurPresenceIsoleeHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AlertePresenceIsolee
    {
        $espaceId = $this->uuid($uriVariables['id'] ?? null);
        $espace = $espaceId !== null ? $this->em->getRepository(EspaceAcces::class)->find($espaceId) : null;
        if (!$espace instanceof EspaceAcces) {
            throw new NotFoundHttpException('Espace d\'accès introuvable.');
        }

        $alerte = $this->handler->detecter($espace);
        if ($alerte === null) {
            throw new UnprocessableEntityHttpException('Aucune présence isolée détectée (occupation ≠ 1).');
        }

        return $alerte;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        // Cf. commentaire équivalent dans `DeclencherSosProcessor` : `{id}` est auto-casté par API
        // Platform vers le type Uuid de l'identifiant hôte, même s'il référence une autre entité.
        if ($reference instanceof Uuid) {
            return $reference;
        }
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
