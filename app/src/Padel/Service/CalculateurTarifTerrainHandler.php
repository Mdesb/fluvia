<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Crm\Entity\Beneficiaire;
use App\Padel\Dto\TarifResolu;
use App\Padel\Entity\GrilleTarifaireTerrain;
use App\Padel\Entity\ParametragePadel;
use App\Padel\Entity\TerrainPadel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résout `(terrain, plageHoraire(créneau.début), statutJoueur(joueur))` → `prix` (RG-PADEL-02, §1.2 du
 * plan). Le prix réel est ensuite « forcé » sur la `LigneVente` M2 (`prixForce=true`, hors périmètre de
 * ce handler, cf. `App\Padel\State\ReserverTerrainProcessor`) — aucune ligne `GrilleTarifaire` M1 n'est
 * lue pour ce calcul.
 */
final class CalculateurTarifTerrainHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlageHoraireResolver $plageResolver,
        private readonly ResolveurStatutJoueurTarif $statutResolver,
    ) {
    }

    /** Résultat : [prix (string decimal), statutJoueur, plageHoraire]. */
    public function resoudre(TerrainPadel $terrain, Beneficiaire $organisateur, \DateTimeImmutable $debut, int $dureeMinutes): TarifResolu
    {
        $etablissement = $terrain->getRessource()?->getEtablissement();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Terrain sans établissement rattaché.');
        }

        $plage = $this->plageResolver->resoudre($etablissement, $debut);
        if ($plage === null) {
            throw new UnprocessableEntityHttpException('Aucune plage horaire (pleine/creuse) ne couvre ce créneau (RG-PADEL-02) : paramétrage incomplet.');
        }

        $statutJoueur = $this->statutResolver->resoudre($organisateur);

        $grille = $this->em->getRepository(GrilleTarifaireTerrain::class)->findOneBy([
            'terrain' => $terrain,
            'plageHoraire' => $plage,
            'statutJoueur' => $statutJoueur,
            'dureeMinutes' => $dureeMinutes,
        ]);
        if ($grille === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Aucun tarif paramétré pour ce terrain (plage « %s », statut « %s », durée %d min).',
                $plage->getLibelle()->value,
                $statutJoueur->value,
                $dureeMinutes,
            ));
        }

        return new TarifResolu($grille->getPrix(), $statutJoueur, $plage->getLibelle()->value);
    }

    public function parametrage(TerrainPadel $terrain): ?ParametragePadel
    {
        $etablissement = $terrain->getRessource()?->getEtablissement();
        if ($etablissement === null) {
            return null;
        }

        return $this->em->getRepository(ParametragePadel::class)->findOneBy(['etablissement' => $etablissement]);
    }
}
