<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Regime\CompteLookupService;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Émission d'une **facture justificative** (RG-FACT-03.1, cœur du module, `plan-facturation.md` §0.1).
 * La `Vente` M2 référencée est déjà validée/scellée et intégralement payée en caisse, donc déjà
 * comptabilisée (RG-COMPTA-04) : cette émission **n'appelle jamais** le moteur d'écritures M6.
 * `ecritureGeneree` reste `null`, le statut passe directement à `acquittee` (CA-1).
 *
 * Idempotence (CA-2, RG-FACT-09) : `uniq_facture_vente_origine` (base) + vérification applicative —
 * une deuxième demande pour la même vente renvoie le document existant, jamais une nouvelle facture ni
 * un nouveau numéro.
 */
final class EmissionFactureJustificativeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly CompteLookupService $lookup,
        private readonly GenerateurNumeroFacture $generateur,
        private readonly ScellementFactureHandler $scellement,
    ) {
    }

    /** @param array<string, mixed>|null $destinataireDonnees */
    public function emettre(Vente $vente, ?array $destinataireDonnees, Utilisateur $auteur): Facture
    {
        $existante = $this->em->getRepository(Facture::class)->findOneBy(['venteOrigine' => $vente]);
        if ($existante instanceof Facture) {
            return $existante; // CA-2 : duplicata, jamais une nouvelle facture ni un nouveau numéro.
        }

        if (!$vente->estScellee()) {
            throw new ConflictHttpException("La vente n'est pas validée : émission d'une facture justificative impossible (RG-FACT-03.1).");
        }
        if ((float) $vente->getResteAPayer() > 0.0) {
            throw new ConflictHttpException('La vente n\'est pas intégralement payée : émission d\'une facture justificative impossible (RG-FACT-03.1).');
        }

        /** @var Facture $facture */
        $facture = $this->em->wrapInTransaction(function () use ($vente, $destinataireDonnees, $auteur): Facture {
            // Revérification sous transaction : ferme la fenêtre de concurrence entre le premier
            // contrôle et l'écriture (idempotence CA-2, doublée par `uniq_facture_vente_origine`).
            $existante = $this->em->getRepository(Facture::class)->findOneBy(['venteOrigine' => $vente]);
            if ($existante instanceof Facture) {
                return $existante;
            }

            $etablissement = $vente->getEtablissement();
            \assert($etablissement !== null);
            $profil = $this->comptes->profilPour($etablissement);
            $periode = $this->comptes->periodePour($profil, $vente->getDate());

            $facture = new Facture();
            $facture->setNature(NatureFacture::Facture);
            $facture->setOrigine(OrigineFacture::TicketEncaisse);
            $facture->setVenteOrigine($vente);
            $facture->setEtablissement($etablissement);
            $facture->setProfilExploitant($profil);
            $facture->setPeriode($periode);
            $facture->setCreePar($auteur);
            $facture->setDestinataire($this->construireDestinataire($vente, $destinataireDonnees));

            foreach ($vente->getLignes() as $ligneVente) {
                $facture->addLigne($this->ligneDepuisVente($ligneVente, $profil));
            }
            $facture->recalculerTotaux();

            $this->generateur->attribuer($facture);

            $facture->setDateEmission(new \DateTimeImmutable());
            $facture->setConditionsReglement($this->comptes->parametre($profil)?->conditionsCompletes());

            // RG-FACT-03.1 : acquittée immédiatement, date/moyen/référence du ticket M2 — aucune
            // écriture comptable nouvelle, aucun appel au moteur M6.
            $facture->setMentionAcquittee(true);
            $facture->setAcquitteeLe($vente->getDate());
            $facture->setAcquitteeMoyen($this->moyensPaiement($vente));
            $facture->setAcquitteeReference($vente->getNumero());
            $facture->setStatut(StatutFacture::Acquittee);

            $this->scellement->sceller($facture);

            $this->em->persist($facture);
            $this->em->flush();

            return $facture;
        });

        return $facture;
    }

    /** @param array<string, mixed>|null $donnees */
    private function construireDestinataire(Vente $vente, ?array $donnees): DestinataireFacturation
    {
        $destinataire = new DestinataireFacturation();

        $clientRef = $vente->getClient();
        $client = $clientRef !== null ? $this->em->getRepository(Client::class)->find($clientRef) : null;

        if ($client !== null) {
            $destinataire->setClientRef($client->getId());
            if ($client->getType() === TypeClient::Morale) {
                $destinataire->setType(TypeDestinataire::PersonneMorale);
                $destinataire->setRaisonSociale($client->getRaisonSociale() ?? '');
                $destinataire->setSiret($client->getSiret());
            } else {
                $destinataire->setType(TypeDestinataire::Particulier);
                $destinataire->setNom($client->getNom());
                $destinataire->setPrenom($client->getPrenom());
            }
            $destinataire->setAdresse($client->getAdresse() ?? []);
        }

        // Le corps de la requête (agent, ou espace client) complète/écrase l'instantané CRM.
        if ($donnees !== null) {
            $type = \is_string($donnees['type'] ?? null) ? TypeDestinataire::tryFrom($donnees['type']) : null;
            if ($type !== null) {
                $destinataire->setType($type);
            }
            if (\is_string($donnees['nom'] ?? null)) {
                $destinataire->setNom($donnees['nom']);
            }
            if (\is_string($donnees['prenom'] ?? null)) {
                $destinataire->setPrenom($donnees['prenom']);
            }
            if (\is_string($donnees['raisonSociale'] ?? null)) {
                $destinataire->setRaisonSociale($donnees['raisonSociale']);
            }
            if (\is_string($donnees['siret'] ?? null)) {
                $destinataire->setSiret($donnees['siret']);
            }
            if (isset($donnees['adresse']) && \is_array($donnees['adresse'])) {
                $destinataire->setAdresse($donnees['adresse']);
            }
        }

        if ($destinataire->getNom() === null && $destinataire->getRaisonSociale() === null) {
            // Vente anonyme, sans complément fourni : identité minimale mais valide (§4.4 spec).
            $destinataire->setType(TypeDestinataire::Particulier);
            $destinataire->setNom('Client comptoir');
        }

        return $destinataire;
    }

    private function ligneDepuisVente(LigneVente $ligneVente, ProfilExploitant $profil): LigneFacture
    {
        $produit = $this->em->getRepository(Produit::class)->find($ligneVente->getProduit());
        $categorieId = $produit?->getCategorieParAxe(AxeCategorie::Comptable)?->getId();
        $taux = $this->resoudreTaux($profil, $categorieId);

        $ttcCentimes = (int) round(((float) $ligneVente->getMontantLigne()) * 100);
        $tauxValeur = (float) $taux->getTaux();
        $tvaCentimes = (int) round($ttcCentimes * $tauxValeur / (100 + $tauxValeur));
        $htCentimes = $ttcCentimes - $tvaCentimes;
        $quantite = max(1, $ligneVente->getQuantite());

        $ligne = new LigneFacture();
        $ligne->setDesignation($produit?->getLibelleRecherche() ?? 'Article');
        $ligne->setLigneVenteOrigine($ligneVente->getId());
        $ligne->setCategorieComptable($categorieId);
        $ligne->setQuantite($quantite);
        // Frontière décimale (§1 du plan, même arrondi que `ProjectionVenteDoctrineAdapter`) : le
        // prix unitaire HT redérivé peut s'écarter de quelques centimes du HT exact sur une ligne
        // multi-quantité — sans effet comptable (aucune écriture n'est générée côté justificative).
        $ligne->setPrixUnitaireHT(number_format(($htCentimes / $quantite) / 100, 2, '.', ''));
        $ligne->setTauxTva($taux);
        $ligne->recalculer();

        return $ligne;
    }

    private function resoudreTaux(ProfilExploitant $profil, ?Uuid $categorieId): TauxTva
    {
        if ($categorieId !== null) {
            $mapping = $this->em->getRepository(MappingComptable::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'categorie' => $categorieId,
            ]);
            if ($mapping?->getTauxTva() !== null) {
                return $mapping->getTauxTva();
            }
        }

        return $this->lookup->tauxHorsChamp($profil);
    }

    private function moyensPaiement(Vente $vente): string
    {
        $moyens = [];
        foreach ($vente->getPaiements() as $paiement) {
            $moyens[$paiement->getMoyenCode()] = true;
        }

        return implode(', ', array_keys($moyens));
    }
}
