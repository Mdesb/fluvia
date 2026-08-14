<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rejeu chronologique idempotent des passages hors-ligne (US-L3-08, RG-ACC-05, CA-9). Réutilise le
 * pattern M2 §4 : tri par horodatage d'origine, anti-doublon par `cleIdempotence` (clé déjà présente
 * = no-op), crédits/FMI recalés « en marchant » (chaque rejeu applique le même algorithme atomique
 * que la validation en ligne), conflits (révocation postérieure) détectés et tracés sans annulation
 * rétroactive du passage déjà advenu (hypothèse retenue, §4.6 Risque n°5).
 */
final class SynchroPassageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidationPassageHandler $validation,
        private readonly RecalageFmiHandler $recalage,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lot
     *
     * @return array{inseres: list<string>, doublons: list<string>, conflits: list<string>}
     */
    public function synchroniser(Controleur $controleur, array $lot): array
    {
        usort($lot, static fn (array $a, array $b): int => strcmp((string) ($a['horodatage'] ?? ''), (string) ($b['horodatage'] ?? '')));

        $inseres = [];
        $doublons = [];
        $conflits = [];

        foreach ($lot as $entree) {
            $cle = $this->uuid($entree['cleIdempotence'] ?? null) ?? Uuid::v4();

            $existant = $this->em->getRepository(Passage::class)->findOneBy(['cleIdempotence' => $cle]);
            if ($existant instanceof Passage) {
                $doublons[] = (string) $cle; // double décompte absorbé par idempotence (no-op).
                continue;
            }

            $equipementId = $this->uuid($entree['equipementId'] ?? null);
            if ($equipementId === null) {
                continue;
            }

            $sens = isset($entree['sens']) ? SensPassage::tryFrom((string) $entree['sens']) : null;
            $horodatage = isset($entree['horodatage']) ? new \DateTimeImmutable((string) $entree['horodatage']) : new \DateTimeImmutable();

            $evt = new EvenementPassageDto(
                equipementId: $equipementId,
                identifiantSupport: isset($entree['identifiantSupport']) ? (string) $entree['identifiantSupport'] : null,
                sens: $sens,
                horodatage: $horodatage,
                cleIdempotence: $cle,
                origineHorsLigne: true,
                ignorerRevocationSiPosterieure: true,
            );

            $passage = $this->validation->valider($evt);
            $inseres[] = (string) $passage->getId();
            if ($passage->isEnConflit()) {
                $conflits[] = (string) $passage->getId();
            }
        }

        // Recalage FMI de fin de rejeu (§4.6) : la jauge de l'espace du contrôleur est déjà à jour
        // (chaque replay applique l'UPDATE atomique), ce recalage garantit sa présence/fraîcheur.
        $espace = $controleur->getEspace();
        if ($espace !== null) {
            $this->recalage->recalerApresSynchro($espace);
        }

        return ['inseres' => $inseres, 'doublons' => $doublons, 'conflits' => $conflits];
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }

        return Uuid::isValid($reference) ? Uuid::fromString($reference) : null;
    }
}
