<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\CautionMateriel;
use App\Padel\Entity\GrilleRetenueMateriel;
use App\Padel\Entity\LocationMateriel;
use App\Padel\Enum\StatutCautionMateriel;
use App\Padel\Enum\StatutRetourMateriel;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retour de matériel loué (POST /padel/locations/{id}/retour, US-PADEL-08, CA-9). Si `non_rendu`,
 * applique la `GrilleRetenueMateriel` paramétrée (§4.7) sur la caution active, sinon la libère.
 * Corps : { "statutRetour": "rendu"|"non_rendu", "typeArticle"?: string, "motif"?: string }.
 *
 * @implements ProcessorInterface<mixed, LocationMateriel>
 */
final class RetournerMaterielProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationMateriel
    {
        \assert($data instanceof LocationMateriel);

        $corps = $this->lecteur->corps();
        $statut = StatutRetourMateriel::tryFrom(\is_string($corps['statutRetour'] ?? null) ? $corps['statutRetour'] : '') ?? StatutRetourMateriel::Rendu;
        $data->setStatutRetour($statut);

        $caution = $this->em->getRepository(CautionMateriel::class)->findOneBy(['location' => $data, 'locationActive' => $data->getId()]);
        if ($caution instanceof CautionMateriel) {
            if ($statut === StatutRetourMateriel::NonRendu) {
                $etablissement = $data->getReservation()?->getEtablissement();
                $typeArticle = \is_string($corps['typeArticle'] ?? null) ? $corps['typeArticle'] : '';
                $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';
                $grille = $etablissement !== null
                    ? $this->em->getRepository(GrilleRetenueMateriel::class)->findOneBy(['etablissement' => $etablissement, 'typeArticle' => $typeArticle, 'motif' => $motif])
                    : null;
                $montantRetenu = $grille?->getMontantRetenue() ?? $caution->getMontant();
                $caution->setStatut(StatutCautionMateriel::Retenue)
                    ->setMontantRetenu($montantRetenu);
            } else {
                $caution->setStatut(StatutCautionMateriel::Liberee)
                    ->setDateLiberation(new \DateTimeImmutable());
            }
        }

        $this->em->flush();

        return $data;
    }
}
