<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Service\GestionCaution;
use App\Padel\Entity\CautionMateriel;
use App\Padel\Entity\LocationMateriel;
use App\Padel\Enum\StatutCautionMateriel;
use App\Padel\Enum\StatutRetourMateriel;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retour de matériel loué (POST /padel/locations/{id}/retour, US-PADEL-08, CA-9). Si `non_rendu`,
 * applique la grille de retenue générique paramétrée (§4.7, `App\Caution\Entity\GrilleRetenue`,
 * cible `padel.materiel`, sous-cible `typeArticle`) sur la caution active, sinon la libère. Montant
 * résolu/appliqué délégué à `App\Caution\Service\GestionCaution::retenirImmediat()`/`restituer()`
 * (refactor caution générique) — pas de phase de validation séparée côté padel (retenue en un temps,
 * contrairement au patron patinoire).
 * Corps : { "statutRetour": "rendu"|"non_rendu", "typeArticle"?: string, "motif"?: string }.
 *
 * @implements ProcessorInterface<mixed, LocationMateriel>
 */
final class RetournerMaterielProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationMateriel
    {
        \assert($data instanceof LocationMateriel);

        $corps = $this->lecteur->corps();
        $statut = StatutRetourMateriel::tryFrom(\is_string($corps['statutRetour'] ?? null) ? $corps['statutRetour'] : '') ?? StatutRetourMateriel::Rendu;
        $data->setStatutRetour($statut);

        $caution = $this->em->getRepository(CautionMateriel::class)->findOneBy(['location' => $data, 'locationActive' => $data->getId()]);
        $cautionGenerique = $this->gestionCaution->cautionActivePour(LouerMaterielProcessor::TYPE_CIBLE, $data->getId());

        if ($caution instanceof CautionMateriel) {
            if ($statut === StatutRetourMateriel::NonRendu) {
                $typeArticle = \is_string($corps['typeArticle'] ?? null) ? $corps['typeArticle'] : '';
                $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';

                $montantRetenu = $caution->getMontant();
                if ($cautionGenerique instanceof Caution) {
                    $mouvement = $this->gestionCaution->retenirImmediat($cautionGenerique, $motif, $typeArticle);
                    $montantRetenu = $mouvement->getMontantDecimal() ?? $montantRetenu;
                }

                $caution->setStatut(StatutCautionMateriel::Retenue)
                    ->setMontantRetenu($montantRetenu);
            } else {
                if ($cautionGenerique instanceof Caution) {
                    $this->gestionCaution->restituer($cautionGenerique);
                }
                $caution->setStatut(StatutCautionMateriel::Liberee)
                    ->setDateLiberation(new \DateTimeImmutable());
            }
        }

        $this->em->flush();

        return $data;
    }
}
