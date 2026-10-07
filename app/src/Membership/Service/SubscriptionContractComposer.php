<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Crm\Entity\Client;
use App\Membership\Entity\Membership;

/**
 * Compose le TEXTE FACTUEL du contrat d'abonnement à partir des termes gelés de l'abonnement.
 *
 * ⚠ FACTUEL, PAS JURIDIQUE. On ne rédige AUCUNE clause (résiliation, pause, RGPD, droit de
 * rétractation…) : on reprend ce qui est réellement convenu — parties, formule, montant, périodicité,
 * engagement, préavis, mandat. Les clauses juridiques exactes sont à finaliser (Maxime) et viendront
 * s'insérer ici ; inventer un texte contractuel serait engager la structure sur des termes que
 * personne n'a validés.
 *
 * Le texte produit est DÉTERMINISTE : c'est lui qui sera haché et signé, puis réaffiché tel quel.
 */
final class SubscriptionContractComposer
{
    public function compose(Membership $subscription): string
    {
        $cents = $subscription->getMontantCentimes();
        $amount = number_format($cents / 100, 2, ',', ' ') . ' €';

        return implode("\n", [
            'CONTRAT D\'ABONNEMENT',
            '',
            'Payeur : ' . $this->clientName($subscription->getPayeur()),
            'Adhérent : ' . $this->clientName($subscription->getAdherent()?->getClient()),
            'Formule (référence) : ' . ($subscription->getFormuleId() ?? '(non renseignée)'),
            'Périodicité de prélèvement : ' . $subscription->getPeriodicite()->value,
            'Montant par échéance : ' . $amount,
            'Engagement : du ' . $subscription->getDateDebutEngagement()->format('d/m/Y')
                . ' au ' . $subscription->getDateFinEngagement()->format('d/m/Y'),
            'Préavis de résiliation : ' . $subscription->getPreavisResiliationJours() . ' jours',
            'Mandat SEPA (RUM) : ' . ($subscription->getMandatSepa()?->getRum() ?? '(mandat absent)'),
        ]);
    }

    /** Le nom lisible d'un client : la raison sociale si c'en est un, sinon prénom + nom. */
    public function clientName(?Client $client): string
    {
        if ($client === null) {
            return '(inconnu)';
        }
        $raisonSociale = trim((string) $client->getRaisonSociale());
        if ($raisonSociale !== '') {
            return $raisonSociale;
        }
        $nom = trim(trim((string) $client->getPrenom()) . ' ' . trim((string) $client->getNom()));

        return $nom !== '' ? $nom : '(inconnu)';
    }
}
