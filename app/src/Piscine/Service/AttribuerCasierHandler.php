<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Caution\Entity\Caution;
use App\Caution\Service\GestionCaution;
use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\StatutCaution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Attribution d'un casier au bracelet (US-L6-09, CA-9) : casier → occupé, caution encaissée
 * (montant par défaut de `ParametrePiscineEtablissement`, ou fourni explicitement). La consignation
 * elle-même est déléguée au moteur générique `App\Caution\Service\GestionCaution` (refactor caution
 * générique) : `CautionCasier` reste l'entité locale exposée par l'API `/api/caution_casiers`
 * (contrat inchangé), mais mirroir désormais la caution générique `App\Caution\Entity\Caution`
 * (cible `piscine.casier`), source unique de la logique de consignation/restitution/retenue.
 */
final class AttribuerCasierHandler
{
    public const TYPE_CIBLE = 'piscine.casier';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function attribuer(Casier $casier, BraceletEtanche $bracelet, ?string $moyenEncaissement, ?string $montant): CautionCasier
    {
        if ($casier->getEtat() !== EtatCasier::Libre) {
            throw new UnprocessableEntityHttpException('Casier non disponible (déjà occupé ou en retard).');
        }

        $etablissement = $casier->getEtablissement();
        $parametre = $etablissement !== null
            ? $this->em->getRepository(ParametrePiscineEtablissement::class)->findOneBy(['etablissement' => $etablissement])
            : null;
        $montantDefaut = $parametre?->getMontantCautionCasierDefaut() ?? '10.00';
        $montantDecimal = $montant ?? $montantDefaut;

        $casier->setEtat(EtatCasier::Occupe)->setBracelet($bracelet);

        if ($etablissement !== null) {
            $this->gestionCaution->consigner(
                $etablissement,
                self::TYPE_CIBLE,
                $casier->getId(),
                Caution::decimalVersCentimes($montantDecimal),
                $moyenEncaissement,
            );
        }

        $caution = new CautionCasier();
        $caution->setCasier($casier)
            ->setMontant($montantDecimal)
            ->setStatut(StatutCaution::Encaissee)
            ->setMoyenEncaissement($moyenEncaissement)
            ->setDateEncaissement(new \DateTimeImmutable());
        $this->em->persist($caution);

        $this->em->flush();

        return $caution;
    }
}
