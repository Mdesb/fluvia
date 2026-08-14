<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\ComptageNonNominatifHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Comptage non nominatif « +1 » (POST /acces/passages/non-nominatif, US-L3-04, CA-5). Corps :
 *   { "equipement": iri|uuid, "motif": string, "sens"?: "entree"|"sortie" }
 *
 * @implements ProcessorInterface<mixed, Passage>
 */
final class PassageNonNominatifProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ComptageNonNominatifHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Passage
    {
        $corps = $this->lecteur->corps();

        $equipementId = $this->uuid($corps['equipement'] ?? null);
        if ($equipementId === null) {
            throw new UnprocessableEntityHttpException('Référence d\'équipement obligatoire.');
        }
        $equipement = $this->em->getRepository(Equipement::class)->find($equipementId);
        if (!$equipement instanceof Equipement) {
            throw new UnprocessableEntityHttpException('Équipement introuvable.');
        }

        $motif = (string) ($corps['motif'] ?? '');
        $sens = isset($corps['sens']) ? SensPassage::tryFrom((string) $corps['sens']) : SensPassage::Entree;

        return $this->handler->compter($equipement, $sens ?? SensPassage::Entree, $motif);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
