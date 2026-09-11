<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Squelette documenté (décision structurante n°4 du plan, Risque n°1) : ne garantit **aucun**
 * encaissement effectif. Se contente de vérifier qu'un mandat SEPA actif existe pour le bénéficiaire
 * et de marquer la `FacturationNoShow` comme « échéance générée » (`referenceEcheanceSepa`) — la
 * collecte réelle passe par `ReservationEcheanceSepaSource` + le point d'entrée générique
 * `POST /sepa/remises/generer` (module `App\Sepa` partagé, comme `App\Membership\Sepa\SportEcheanceSepaSource`).
 * `FacturationNoShow.statut` reste `à_facturer` tant que la remise SEPA n'a pas été générée et honorée
 * (⚠ point ouvert majeur, spec §8).
 */
final class PrelevementDiffereStrategie implements StrategieFacturationNoShow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function code(): string
    {
        return ModeFacturationNoShow::PrelevementDiffere->value;
    }

    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow
    {
        $reservation = $facturation->getReservation();
        $client = $reservation?->getOrganisateur()?->getClient();
        if ($client === null) {
            return new ResultatFacturationNoShow(false, 'Aucune fiche client CRM rattachée : impossible de résoudre un mandat SEPA.');
        }

        $mandat = $this->em->getRepository(MandatSepa::class)->findOneBy(['client' => $client, 'statut' => StatutMandatSepa::Actif]);
        if ($mandat === null) {
            return new ResultatFacturationNoShow(false, 'Aucun mandat SEPA actif pour ce bénéficiaire (RG-SEPA).');
        }

        // Référence opaque consommée par `ReservationEcheanceSepaSource::echeancesDues()` — la
        // FacturationNoShow elle-même sert d'échéance en attente de remise (squelette, aucun
        // encaissement garanti tant que la remise n'a pas été générée/exécutée par le module SEPA).
        $facturation->setReferenceEcheanceSepa((string) $facturation->getId());
        $this->em->flush();

        return new ResultatFacturationNoShow(true, 'Échéance SEPA générée (squelette) : encaissement effectif non garanti tant que la remise n\'a pas été honorée.');
    }
}
