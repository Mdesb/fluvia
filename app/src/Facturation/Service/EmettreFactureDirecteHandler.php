<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Nf525\ScellementFactureHandler;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Émission d'une **facture directe** (RG-FACT-03.2, cœur du module, `plan-facturation.md` §0.1/§0.2).
 * Aucun passage caisse : l'émission **est** le fait générateur comptable. Un **seul** appel au moteur
 * M6 (compte client 411 / compte produit via `MappingComptable` ou repli / TVA collectée via
 * `RegimeComptableResolver`-adjacent `ResolveurComptesFacturation`), scellé par
 * `App\Compta\Nf525\ScellementEcritureHandler` **réutilisé tel quel** (aucun second moteur d'écritures,
 * §0.2 du plan). Statut → `en_attente_paiement` (CA-3).
 *
 * Idempotence (CA-4) : la transition `brouillon → émise` est gardée par le statut — une facture déjà
 * scellée ne peut plus être ré-émise. Numérotation + écriture + scellement s'exécutent dans **une
 * seule transaction** (`wrapInTransaction`), fermant la fenêtre « numéro consommé sans écriture ».
 *
 * Concurrence (correctif revue de cohérence, défaut 2) : le garde `estBrouillon()`/`estScellee()`
 * ci-dessous est évalué **avant** l'ouverture de la transaction (fail-fast + résolution des comptes
 * hors verrou) — insuffisant seul : deux `POST /factures/{id}/emettre` concurrents le franchiraient
 * tous les deux. La facture est donc **verrouillée** (`LockMode::PESSIMISTIC_WRITE`) tout au début de
 * la fermeture transactionnelle, puis le garde est **revérifié après verrou**, avant toute écriture :
 * la seconde requête, une fois le verrou obtenu, constate l'émission déjà faite par la première et
 * échoue proprement (pas de seconde `EcritureComptable`).
 */
final class EmettreFactureDirecteHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly GenerateurNumeroFacture $generateur,
        private readonly ScellementEcritureHandler $scellementEcriture,
        private readonly ScellementFactureHandler $scellementFacture,
    ) {
    }

    public function emettre(Facture $facture): Facture
    {
        if (!$facture->estBrouillon() || $facture->estScellee()) {
            throw new ConflictHttpException('Ré-émission interdite : cette facture est déjà émise (RG-FACT-03, CA-4).');
        }
        if ($facture->getLignes()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Une facture sans ligne ne peut pas être émise.');
        }

        $profil = $facture->getProfilExploitant();
        \assert($profil !== null);

        // Résolution de tous les comptes AVANT toute numérotation : une ligne au compte produit
        // indéterminable est rejetée explicitement (422) sans consommer de numéro ni ouvrir
        // d'écriture partielle.
        $comptesLignes = [];
        foreach ($facture->getLignes() as $ligne) {
            $comptesLignes[(string) $ligne->getId()] = $this->comptes->compteProduit($profil, $ligne);
        }
        $compteClient = $this->comptes->compteClient($profil);
        $compteTva = $this->comptes->compteTvaCollectee($profil);
        $journal = $this->comptes->journalFactures($profil);

        /** @var Facture $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($facture, $profil, $comptesLignes, $compteClient, $compteTva, $journal): Facture {
            // Verrou pessimiste posé en tout premier, puis revérification du garde : ferme la fenêtre
            // de concurrence entre le contrôle initial (hors transaction) et l'écriture.
            $this->em->lock($facture, LockMode::PESSIMISTIC_WRITE);
            if (!$facture->estBrouillon() || $facture->estScellee() || $facture->getNumero() !== null) {
                throw new ConflictHttpException('Ré-émission interdite : cette facture est déjà émise (RG-FACT-03, CA-4).');
            }

            $dateEmission = new \DateTimeImmutable();
            $periode = $this->comptes->periodePour($profil, $dateEmission);
            $facture->setPeriode($periode);

            $this->generateur->attribuer($facture);

            $ecriture = new EcritureComptable();
            $ecriture->setProfilExploitant($profil);
            $ecriture->setJournal($journal);
            $ecriture->setPeriode($periode);
            $ecriture->setDateEcriture($dateEmission);
            $ecriture->setLibelle('Facture directe ' . $facture->getNumero());
            $ecriture->setStatut(StatutEcriture::Controlee);

            $premierTaux = null;
            $totalCreditCentimes = 0;
            foreach ($facture->getLignes() as $ligne) {
                $compteProduit = $comptesLignes[(string) $ligne->getId()];
                $premierTaux ??= $ligne->getTauxTva();

                $htCentimes = $this->centimes($ligne->getMontantHT());
                $tvaCentimes = $this->centimes($ligne->getMontantTva());

                $ligneProduit = new LigneEcriture();
                $ligneProduit->setCompte($compteProduit);
                $ligneProduit->setCreditCentimes($htCentimes);
                $ligneProduit->setTauxTva($ligne->getTauxTva());
                $ligneProduit->setLibelle('Produit ' . $facture->getNumero() . ' — ' . $ligne->getDesignation());
                $ecriture->addLigne($ligneProduit);
                $totalCreditCentimes += $htCentimes;

                if ($tvaCentimes > 0) {
                    $ligneTva = new LigneEcriture();
                    $ligneTva->setCompte($compteTva);
                    $ligneTva->setCreditCentimes($tvaCentimes);
                    $ligneTva->setTauxTva($ligne->getTauxTva());
                    $ligneTva->setLibelle('TVA collectée ' . $facture->getNumero());
                    $ecriture->addLigne($ligneTva);
                    $totalCreditCentimes += $tvaCentimes;
                }
            }

            $ligneClient = new LigneEcriture();
            $ligneClient->setCompte($compteClient);
            $ligneClient->setDebitCentimes($totalCreditCentimes);
            $ligneClient->setTauxTva($premierTaux);
            $ligneClient->setLibelle('Créance facture ' . $facture->getNumero());
            $ecriture->addLigne($ligneClient);

            $this->scellementEcriture->sceller($ecriture);
            $this->em->persist($ecriture);
            $this->em->flush();

            $facture->setDateEmission($dateEmission);
            $facture->setEcritureGeneree($ecriture);
            $facture->setLigneEcritureClient($ligneClient);
            $facture->setStatut(StatutFacture::EnAttentePaiement);
            if ($facture->getConditionsReglement() === null) {
                $facture->setConditionsReglement($this->comptes->parametre($profil)?->conditionsCompletes());
            }

            $this->scellementFacture->sceller($facture);
            $this->em->flush();

            return $facture;
        });

        return $resultat;
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
