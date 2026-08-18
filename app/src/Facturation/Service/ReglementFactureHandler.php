<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Service\LettrageHandler;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Enum\StatutFacture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Règlement d'une facture directe (RG-FACT-06, `plan-facturation.md` §1.5). Trace le règlement dans le
 * domaine Facturation (montant, moyen, référence) et n'appelle `LettrageHandler::lettrer()` (M6)
 * **qu'une seule fois**, quand le cumul atteint le total TTC (CA-5) — aucun second mécanisme de
 * lettrage n'est créé, la réconciliation finale de la ligne d'écriture reste portée par M6.
 */
final class ReglementFactureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LettrageHandler $lettrage,
    ) {
    }

    public function enregistrer(Facture $facture, string $montant, string $moyen, ?string $reference, Utilisateur $auteur): ReglementFacture
    {
        if (!\in_array($facture->getStatut(), [StatutFacture::EnAttentePaiement, StatutFacture::PartiellementReglee], true)) {
            throw new ConflictHttpException('Règlement impossible : facture non en attente de paiement (RG-FACT-06).');
        }
        if ((float) $montant <= 0.0) {
            throw new UnprocessableEntityHttpException('Le montant du règlement doit être positif.');
        }

        $soldeAvant = (float) $facture->getSoldeDu();
        if ((float) $montant > $soldeAvant + 0.001) {
            throw new UnprocessableEntityHttpException('Le règlement dépasse le solde restant dû.');
        }

        $reglement = new ReglementFacture();
        $reglement->setFacture($facture);
        $reglement->setMontant($montant);
        $reglement->setMoyen($moyen);
        $reglement->setReference($reference);
        $reglement->setAuteur($auteur);
        $facture->addReglement($reglement);
        $this->em->persist($reglement);
        $this->em->flush();

        $solde = (float) $facture->getSoldeDu();
        if ($solde <= 0.001) {
            $ligneClient = $facture->getLigneEcritureClient();
            if ($ligneClient !== null) {
                $this->lettrage->lettrer($ligneClient, $auteur);
            }
            $facture->setStatut(StatutFacture::Payee);
        } else {
            $facture->setStatut(StatutFacture::PartiellementReglee);
        }
        $this->em->flush();

        return $reglement;
    }
}
