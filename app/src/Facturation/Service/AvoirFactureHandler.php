<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Avoir — seule voie de correction d'une facture émise (RG-FACT-05, `plan-facturation.md` §1, CA-6).
 * Ne modifie ni ne supprime aucune ligne de la facture d'origine.
 *
 *  - Si la facture corrigée avait généré une `EcritureComptable` (directe) → génère une **écriture
 *    d'extourne symétrique** (inversion stricte débit/crédit de chaque ligne, même principe que
 *    `App\Compta\Regime\RegimeBase::genererEcritureExtourne`) ;
 *  - si elle n'en avait généré aucune (justificative) → l'avoir ne génère lui non plus **aucune**
 *    écriture (RG-FACT-03) : correction documentaire pure.
 *
 * Simplification assumée (§7 point 7 du plan) : cet avoir est **total** (montant = facture d'origine),
 * porté par une seule ligne synthétique au taux de la première ligne d'origine — même niveau de
 * simplification que `ContrePassationHandler::rembourser()` côté M2.
 */
final class AvoirFactureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly GenerateurNumeroFacture $generateur,
        private readonly ScellementEcritureHandler $scellementEcriture,
        private readonly ScellementFactureHandler $scellementFacture,
    ) {
    }

    public function genererAvoir(Facture $factureCorrigee, Utilisateur $auteur): Facture
    {
        if ($factureCorrigee->estBrouillon()) {
            throw new ConflictHttpException('Impossible de générer un avoir sur une facture en brouillon (RG-FACT-05).');
        }
        if ($factureCorrigee->getNature() === NatureFacture::Avoir) {
            throw new ConflictHttpException('Impossible de générer un avoir sur un avoir.');
        }

        // Idempotence : un second appel sur la même facture corrigée renvoie l'avoir déjà généré,
        // jamais un doublon (double extourne / double crédit 411) — même patron que
        // `EmissionFactureJustificativeHandler::emettre()` pour `venteOrigine` (CA-2).
        $existant = $this->em->getRepository(Facture::class)->findOneBy(['factureCorrigee' => $factureCorrigee]);
        if ($existant instanceof Facture) {
            return $existant;
        }

        /** @var Facture $avoir */
        $avoir = $this->em->wrapInTransaction(function () use ($factureCorrigee, $auteur): Facture {
            // Revérification sous transaction : ferme la fenêtre de concurrence entre le premier
            // contrôle et l'écriture.
            $existant = $this->em->getRepository(Facture::class)->findOneBy(['factureCorrigee' => $factureCorrigee]);
            if ($existant instanceof Facture) {
                return $existant;
            }

            $profil = $factureCorrigee->getProfilExploitant();
            \assert($profil !== null);
            $etablissement = $factureCorrigee->getEtablissement();
            \assert($etablissement !== null);
            $destinataireOrigine = $factureCorrigee->getDestinataire();
            \assert($destinataireOrigine instanceof DestinataireFacturation);

            $avoir = new Facture();
            $avoir->setNature(NatureFacture::Avoir);
            $avoir->setOrigine($factureCorrigee->getOrigine());
            $avoir->setFactureCorrigee($factureCorrigee);
            $avoir->setEtablissement($etablissement);
            $avoir->setProfilExploitant($profil);
            $avoir->setCreePar($auteur);
            // Instantané figé propre à l'avoir (RG-FACT-08) : jamais partagé avec la facture d'origine.
            $avoir->setDestinataire($destinataireOrigine->copier());

            $premiereLigne = $factureCorrigee->getLignes()->first();
            $taux = $premiereLigne instanceof LigneFacture ? $premiereLigne->getTauxTva() : null;
            \assert($taux !== null);

            $ligne = new LigneFacture();
            $ligne->setDesignation('Avoir sur facture ' . $factureCorrigee->getNumero());
            $ligne->setQuantite(1);
            $ligne->setPrixUnitaireHT('-' . $factureCorrigee->getTotalHT());
            $ligne->setTauxTva($taux);
            $avoir->addLigne($ligne);
            $avoir->recalculerTotaux();

            $dateEmission = new \DateTimeImmutable();
            $avoir->setPeriode($this->comptes->periodePour($profil, $dateEmission));

            $this->generateur->attribuer($avoir);
            $avoir->setDateEmission($dateEmission);
            $avoir->setConditionsReglement('Avoir — sans effet sur les conditions de règlement de la facture corrigée.');

            $ecritureOrigine = $factureCorrigee->getEcritureGeneree();
            if ($ecritureOrigine instanceof EcritureComptable) {
                $extourne = $this->extourner($ecritureOrigine, $avoir, $profil);
                $avoir->setEcritureGeneree($extourne);
            }

            if ($factureCorrigee->isMentionAcquittee()) {
                $avoir->setMentionAcquittee(true);
                $avoir->setAcquitteeLe($dateEmission);
                $avoir->setAcquitteeMoyen('avoir');
                $avoir->setAcquitteeReference($factureCorrigee->getNumero() ?? '');
            }
            $avoir->setStatut(StatutFacture::Acquittee);

            $this->scellementFacture->sceller($avoir);

            $this->em->persist($avoir);
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $exception) {
                // Deux requêtes concurrentes ont franchi la vérification ci-dessus avant ce flush : la
                // contrainte UNIQUE (base, `uniq_facturation_facture_corrigee`) a tranché — récupération
                // gracieuse de l'avoir gagnant plutôt qu'une erreur 500.
                $existant = $this->em->getRepository(Facture::class)->findOneBy(['factureCorrigee' => $factureCorrigee]);
                if ($existant instanceof Facture) {
                    return $existant;
                }

                throw $exception;
            }

            return $avoir;
        });

        return $avoir;
    }

    private function extourner(EcritureComptable $origine, Facture $avoir, ProfilExploitant $profil): EcritureComptable
    {
        $extourne = new EcritureComptable();
        $extourne->setProfilExploitant($profil);
        $extourne->setJournal($origine->getJournal());
        $extourne->setPeriode($origine->getPeriode());
        $extourne->setDateEcriture(new \DateTimeImmutable());
        $extourne->setLibelle('Extourne — avoir ' . $avoir->getNumero());
        $extourne->setPieceExtourneDe($origine);
        $extourne->setStatut(StatutEcriture::Controlee);

        foreach ($origine->getLignes() as $ligne) {
            $ligneExtourne = new LigneEcriture();
            $ligneExtourne->setCompte($ligne->getCompte());
            $ligneExtourne->setDebitCentimes($ligne->getCreditCentimes());
            $ligneExtourne->setCreditCentimes($ligne->getDebitCentimes());
            $ligneExtourne->setTauxTva($ligne->getTauxTva());
            $ligneExtourne->setLibelle('Extourne — ' . $ligne->getLibelle());
            $extourne->addLigne($ligneExtourne);
        }

        $this->scellementEcriture->sceller($extourne);
        $this->em->persist($extourne);
        $this->em->flush();

        return $extourne;
    }
}
