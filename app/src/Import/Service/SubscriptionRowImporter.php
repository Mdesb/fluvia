<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Import\Dto\ParsedImportRow;
use App\Import\Enum\ImportType;
use App\Offre\Entity\Produit;

/**
 * Reprise des ABONNEMENTS EN COURS (`subscribers`, #348) : un abonnement de l'ancien logiciel devient
 * une carte et un droit d'accès valable jusqu'à sa date de fin.
 *
 * Colonnes : `externalRef` ; `supportIdentifiant` (numéro de la carte) ; `produit` (code du produit
 * Fluvia correspondant) ; `dateFin` (obligatoire) ; `dateDebut` (facultative, aujourd'hui sinon) ;
 * `typeSupport` (facultatif : RFID ou QR).
 *
 * « En cours » se vérifie : une date de fin passée est refusée. Reprendre un abonnement échu
 * produirait une carte refusée au tourniquet, sans que la reprise l'ait signalé.
 *
 * Hors champ : l'abonnement à prélèvement SEPA (`Membership`), qui exige un mandat signé et un
 * échéancier — sa reprise est une autre affaire, notée dans la PR.
 */
final class SubscriptionRowImporter extends AccessRightRowImporter
{
    public function type(): ImportType
    {
        return ImportType::Subscribers;
    }

    protected function rightType(): TypeDroitAcces
    {
        return TypeDroitAcces::Abonnement;
    }

    protected function validateSpecific(ParsedImportRow $row, Produit $produit, \DateTimeImmutable $today): ?string
    {
        if ($produit->getCarte() !== null) {
            return sprintf('Le produit « %s » est une carte à entrées : reprenez-la en « soldes de cartes ».', $produit->getCode());
        }
        $fin = $this->date($row, 'datefin');
        $debut = $this->date($row, 'datedebut');

        return match (true) {
            $fin === null => 'dateFin est obligatoire : la date jusqu\'à laquelle l\'abonnement est payé.',
            $fin === false => sprintf('dateFin illisible : « %s » (attendu AAAA-MM-JJ ou JJ/MM/AAAA).', $this->col($row, 'datefin')),
            $debut === false => sprintf('dateDebut illisible : « %s » (attendu AAAA-MM-JJ ou JJ/MM/AAAA).', $this->col($row, 'datedebut')),
            $fin < $today => sprintf('Abonnement échu le %s : seuls les abonnements en cours se reprennent.', $fin->format('d/m/Y')),
            $debut instanceof \DateTimeImmutable && $debut > $fin => 'dateDebut est postérieure à dateFin.',
            default => null,
        };
    }

    protected function hydrateRight(DroitAcces $droit, ParsedImportRow $row, \DateTimeImmutable $today): void
    {
        $debut = $this->date($row, 'datedebut');
        $fin = $this->date($row, 'datefin');
        $droit->setFenetreDebut($debut instanceof \DateTimeImmutable ? $debut : $today);
        // Valable toute la journée de fin, comme un titre vendu.
        $droit->setFenetreFin($fin instanceof \DateTimeImmutable ? $fin->setTime(23, 59, 59) : null);
        $droit->setCreditRestant(null);
    }
}
