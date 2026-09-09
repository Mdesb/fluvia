<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\LettrageHandler;
use App\Compta\Service\PaymentLedgerPoster;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Enum\StatutFacture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Règlement d'une facture directe (RG-FACT-06, `plan-facturation.md` §1.5). Trace le règlement dans le
 * domaine Facturation (montant, moyen, référence), **écrit son encaissement au grand livre**, et
 * lettre au moment où le cumul atteint le total TTC (CA-5). Aucun second mécanisme de lettrage n'est
 * créé : la réconciliation reste portée par M6 (`LettrageHandler`).
 *
 * ── CE QUI A CHANGÉ LE 08/09, ET POURQUOI CE N'ÉTAIT PAS VISIBLE ────────────────────────────────
 *
 * Ce service enregistrait le règlement puis lettrait la ligne 411 de la facture — **seule**, contre
 * rien. Aucune écriture ne constatait l'encaissement : le journal `ENC` existait, était semé dans
 * chaque plan comptable, et n'avait jamais reçu une ligne. Mesuré en préproduction le 08/09 :
 * `FA-2026-00001` portait `411000` débit 360,00, ses deux virements étaient bien enregistrés, sa
 * ligne 411 était lettrée — et il n'existait **aucun** débit de trésorerie en face. La créance
 * restait au bilan et l'argent n'entrait jamais dans les comptes.
 *
 * Rien ne pouvait le signaler : l'écran affichait un solde à zéro, le statut passait à `payee`, et
 * un lettrage existait. Les trois marqueurs qu'on regarde disaient « réglé ». Seul le grand livre
 * disait le contraire, et aucun écran ne le lit.
 */
final class ReglementFactureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LettrageHandler $lettrage,
        private readonly PaymentLedgerPoster $encaissements,
        private readonly ResolveurComptesFacturation $comptes,
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

        // ⚠ RÉSOUDRE LE MOYEN AVANT D'ÉCRIRE QUOI QUE CE SOIT. `$moyen` est une chaîne libre côté
        //    contrat d'API ; l'écriture d'encaissement, elle, a besoin de l'entité et de son compte de
        //    trésorerie. Résoudre après le `persist` laisserait un règlement enregistré sans écriture
        //    en cas de code inconnu — exactement la moitié de geste qu'on est en train de corriger.
        $moyenPaiement = $this->em->getRepository(MoyenPaiement::class)->findOneBy(['code' => $moyen]);
        if (!$moyenPaiement instanceof MoyenPaiement) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen de paiement inconnu : « %s ».', $moyen));
        }

        $profil = $facture->getProfilExploitant();
        if (!$profil instanceof ProfilExploitant) {
            throw new UnprocessableEntityHttpException('Facture sans profil exploitant : impossible d\'écrire l\'encaissement.');
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

        // --- L'écriture d'encaissement (G-2) : débit trésorerie du moyen, crédit compte client. ---
        //
        // Le compte crédité est CELUI DE LA LIGNE 411 DE LA FACTURE quand elle existe, et non un 411
        // résolu à neuf : la créance doit s'éteindre sur le compte exact où elle est née, sinon le
        // lettrage groupé rapproche deux lignes de comptes différents et le solde du client ne revient
        // jamais à zéro. Le repli n'existe que pour une facture sans écriture — cas qu'aucun chemin
        // d'émission ne produit aujourd'hui, mais qu'on ne suppose pas absent.
        $ligneClient = $facture->getLigneEcritureClient();
        $compteClient = $ligneClient?->getCompte() ?? $this->comptes->compteClient($profil);
        $destinataire = $facture->getDestinataire();
        $montantCentimes = (int) round(((float) $montant) * 100);

        $ecriture = $this->encaissements->post(
            $profil,
            $moyenPaiement,
            $montantCentimes,
            $reglement->getDateReglement(),
            $compteClient,
            'Encaissement facture ' . ($facture->getNumero() ?? ''),
            counterpartyType: 'crm_client',
            counterpartyId: $destinataire?->getClientRef(),
            counterpartyLabel: $destinataire?->denomination(),
        );
        $reglement->setEcritureEncaissement($ecriture);
        $this->em->flush();

        $solde = (float) $facture->getSoldeDu();
        if ($solde <= 0.001) {
            if ($ligneClient !== null) {
                // ⚠ LETTRAGE GROUPÉ, ET IL DOIT PORTER TOUS LES RÈGLEMENTS, PAS SEULEMENT LE DERNIER.
                //    `lettrerGroupe()` exige Σdébit = Σcrédit : une facture de 360 réglée en 200 puis
                //    160 ne s'équilibre qu'en présentant la ligne débitrice de la facture ET les deux
                //    lignes créditrices. Le chemin fournisseur a buté sur ce même point (« Défaut 5 »).
                //
                //    Ce qui remplaçait ce geste — `lettrer()` sur la seule ligne de la facture —
                //    marquait la créance soldée sans qu'aucune écriture ne la solde : la ligne 411
                //    restait débitrice au grand livre, lettrée contre rien.
                $lignes = [$ligneClient];
                foreach ($facture->getReglements() as $precedent) {
                    $ligne = $this->ligneClientDe($precedent->getEcritureEncaissement(), $compteClient);
                    if ($ligne !== null) {
                        $lignes[] = $ligne;
                    }
                }

                if (\count($lignes) > 1) {
                    $lettrages = $this->lettrage->lettrerGroupe($lignes, $auteur, $reglement->getDateReglement());
                    $code = $lettrages[0]->getReconciliationCode();
                    foreach ($facture->getReglements() as $precedent) {
                        $precedent->setReconciliationCode($code);
                    }
                }
            }
            $facture->setStatut(StatutFacture::Payee);
        } else {
            $facture->setStatut(StatutFacture::PartiellementReglee);
        }
        $this->em->flush();

        return $reglement;
    }

    /** La ligne de l'écriture d'encaissement qui porte le compte client — celle qui se lettre. */
    private function ligneClientDe(?EcritureComptable $ecriture, CompteComptable $compteClient): ?LigneEcriture
    {
        if ($ecriture === null) {
            return null;
        }

        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCompte()?->getId()->equals($compteClient->getId())) {
                return $ligne;
            }
        }

        return null;
    }
}
