<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\BilletQrMeta;
use App\Boutique\Entity\LigneCommandeMeta;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Enum\StatutDemandeRemboursement;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Enum\StatutRetraitClickCollect as StatutRetrait;
use App\Boutique\Enum\StatutRetraitPhysique;
use App\Boutique\Enum\StatutTunnel;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Notification\ConfirmationCommandeMailer;
use App\Boutique\Security\ProduitEtablissementGuard;
use App\Crm\Adapter\ClientM4Adapter;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\RoleBeneficiaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Service\ResolveurPrix;
use App\Organisation\Entity\Espace;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cœur du tunnel (§0 décisions n°1/2/4/8 du plan) : construit une `Vente` M2 (canal `en_ligne`) à
 * partir du panier, revérifie synchroniquement la disponibilité juste avant paiement, appelle
 * **directement** `App\Vente\Service\ValiderVenteService::valider()` (code réel, inchangé) au retour
 * de paiement réussi, puis crée les `Reservation` confirmées (timed-entry) et les satellites
 * `BilletQrMeta`/`RetraitClickCollect`. Aucun remboursement automatique même en cas de conflit
 * d'inventaire résiduel (§0 décision n°8) : une `DemandeRemboursement` pré-remplie est créée à la
 * place.
 */
final class ConfirmerCommandeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierCalculateur $calculateur,
        private readonly GenerateurNumero $generateurNumero,
        private readonly SessionSystemeBoutiqueResolver $sessionSysteme,
        private readonly ValiderVenteService $validerVente,
        private readonly ClientM4Adapter $clientAdapter,
        private readonly ResolveurPrix $resolveurPrix,
        private readonly JaugeCreneauGuard $jauge,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly ConfirmationCommandeMailer $mailer,
        private readonly ProduitEtablissementGuard $etablissementGuard,
    ) {
    }

    /** Résout (ou crée, §0 décision n°4) le `Client` (M4) payeur du panier — invité inclus. */
    public function resoudreClient(PanierEnLigne $panier): Client
    {
        $compte = $panier->getCompteClient();
        if ($compte !== null && $compte->getClient() !== null) {
            $panier->setClientResolu($compte->getClient()->getId());

            return $compte->getClient();
        }

        if ($panier->getClientResolu() !== null) {
            $client = $this->em->getRepository(Client::class)->find($panier->getClientResolu());
            if ($client instanceof Client) {
                return $client;
            }
        }

        $email = $panier->getContactConnu();
        $id = $this->clientAdapter->creerRapide(['email' => $email, 'nom' => 'Client boutique']);
        $panier->setClientResolu($id);
        $client = $this->em->getRepository(Client::class)->find($id);
        \assert($client instanceof Client);

        return $client;
    }

    /** RG-M3-08/§0 décision n°8 : refuse avant même l'initiation du paiement si plus de place. */
    public function reverifierDisponibilite(PanierEnLigne $panier): void
    {
        foreach ($panier->getLignes() as $ligne) {
            $creneau = $ligne->getCreneau();
            if ($creneau !== null && $this->jauge->estComplet($creneau)) {
                throw new ConflictHttpException('RG-M3-08 : créneau complet, place non disponible.');
            }
        }
    }

    /** Construit (ou récupère, idempotent — CA-10 retente sans reperdre sa place) la Vente en_cours. */
    public function creerOuRecupererVente(PanierEnLigne $panier): Vente
    {
        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier]);
        if ($suivi instanceof SuiviCommandeEnLigne && $suivi->getVente()?->getStatut() === StatutVente::EnCours) {
            return $suivi->getVente();
        }

        $etablissement = $panier->getEtablissement();
        $client = $this->resoudreClient($panier);
        $session = $this->sessionSysteme->sessionSysteme($etablissement);

        $vente = new Vente();
        $vente->setSession($session)
            ->setClient($client->getId())
            ->setStatut(StatutVente::EnCours)
            ->setEtablissement($etablissement)
            ->setNumero($this->generateurNumero->numeroVente($session));

        foreach ($panier->getLignes() as $ligne) {
            // Revue de sécurité — faille bloquante, défense en profondeur : revérifie le cloisonnement
            // établissement juste avant la construction de la Vente (même garde qu'à l'ajout au
            // panier, `AjouterLignePanierProcessor`), au cas où une ligne aurait été insérée par un
            // autre chemin que le processeur public.
            $this->etablissementGuard->verifier($ligne->getProduit(), $etablissement);
            [$typeTarif, $prix] = $this->resoudrePrix($ligne->getProduit());

            $ligneVente = new LigneVente();
            $ligneVente->setProduit($ligne->getProduit()->getId())
                ->setTypeTarif($typeTarif->getId())
                ->setQuantite($ligne->getQuantite())
                ->setPrixUnitaire($prix)
                ->setBeneficiaire($ligne->getBeneficiaireRef()?->getClient()?->getId());
            $this->calculateur->recalculerLigne($ligneVente);
            $vente->addLigne($ligneVente);
            $this->em->persist($ligneVente);

            $meta = new LigneCommandeMeta();
            $meta->setLigneVente($ligneVente)
                ->setCreneau($ligne->getCreneau())
                ->setChampsPersonnalises($ligne->getChampsPersonnalises())
                ->setBeneficiaireSimple($ligne->getBeneficiaireSimple());
            $this->em->persist($meta);
        }

        $this->calculateur->recalculerVente($vente);
        $this->em->persist($vente);

        if (!$suivi instanceof SuiviCommandeEnLigne) {
            $suivi = new SuiviCommandeEnLigne();
            $suivi->setPanierOrigine($panier)->setVitrine($panier->getVitrine())->setEtablissement($etablissement);
        }
        $suivi->setVente($vente)->setCompteClient($panier->getCompteClient())->setStatutTunnel(StatutTunnel::Paye);
        $this->em->persist($suivi);

        $this->em->flush();

        return $vente;
    }

    /**
     * Retour de paiement réussi (CA-11) : enregistre le règlement, valide la vente (M2 inchangé),
     * projette les réservations/billets. Retourne `true` si un conflit d'inventaire résiduel a été
     * détecté (§0 décision n°8, DemandeRemboursement automatique créée, jamais d'avoir automatique).
     */
    public function confirmerApresPaiementReussi(PanierEnLigne $panier, Vente $vente, string $moyenCode, string $referenceTransaction): bool
    {
        $paiement = new Paiement();
        $paiement->setMoyenCode($moyenCode)
            ->setMontant($vente->getTotal())
            ->setRefTPE($referenceTransaction)
            ->setStatutTPE(StatutTPE::Accepte);
        $vente->addPaiement($paiement);
        $this->em->persist($paiement);
        $this->calculateur->recalculerVente($vente);

        // Le code de support (unique, signé HMAC — CA-12) est généré automatiquement par
        // `ValiderVenteService::creerSupport()` (`App\Vente\Service\GenerateurCodeSupport`) : seul le
        // type « qr » est forcé ici (billet boutique dématérialisé).
        $overrides = [];
        foreach ($vente->getLignes() as $ligneVente) {
            $overrides[(string) $ligneVente->getId()] = ['type' => 'qr'];
        }

        try {
            $this->validerVente->valider($vente, $overrides);
        } catch (ConflictHttpException|UnprocessableEntityHttpException $e) {
            // §0 décision n°8b : paiement déjà capturé, place prise entre-temps — jamais d'avoir
            // automatique (RG-M3-15) : une demande de remboursement pré-remplie est créée à la place.
            $demande = new DemandeRemboursement();
            $demande->setVente($vente)
                ->setMotif('Conflit d\'inventaire à la confirmation (paiement déjà capturé) : ' . $e->getMessage())
                ->setStatut(StatutDemandeRemboursement::Recue)
                ->setOrigineAutomatique(true)
                ->setEtablissement($vente->getEtablissement());
            $this->em->persist($demande);
            $this->em->flush();

            return true;
        }

        $this->em->flush();

        $payeur = $this->resoudreClient($panier);

        foreach ($vente->getLignes() as $ligneVente) {
            $meta = $this->em->getRepository(LigneCommandeMeta::class)->findOneBy(['ligneVente' => $ligneVente]);

            if ($meta instanceof LigneCommandeMeta && $meta->getCreneau() !== null) {
                $beneficiaire = $this->resoudreBeneficiaire($payeur, $meta);
                $reservation = new Reservation();
                $reservation->setCreneau($meta->getCreneau())
                    ->setOrganisateur($beneficiaire)
                    ->setEtablissement($vente->getEtablissement())
                    ->setModeDecompte(ModeDecompteReservation::VenteUnite)
                    ->setVenteRattachee($vente)
                    ->setMontantDu('0.00');
                $this->em->persist($reservation);
                $this->em->flush();
                $this->projectionAcces->projeterSiApplicable($reservation);
            }

            $support = $this->em->getRepository(BilletSupport::class)->findOneBy(['ligne' => $ligneVente]);
            if ($support instanceof BilletSupport) {
                $this->creerBilletQrMeta($support, $vente, $meta);
            }
        }

        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['vente' => $vente]);
        if ($suivi instanceof SuiviCommandeEnLigne) {
            $suivi->setStatutTunnel(StatutTunnel::Confirme);
        }
        $panier->setStatut(StatutPanier::TransformeEnCommande);
        $this->em->flush();

        $this->mailer->envoyer($vente, $panier);

        return false;
    }

    private function creerBilletQrMeta(BilletSupport $support, Vente $vente, ?LigneCommandeMeta $meta): void
    {
        $produit = null;
        if ($meta !== null) {
            $ligneVente = $support->getLigne();
            $produit = $ligneVente !== null ? $this->em->getRepository(Produit::class)->find($ligneVente->getProduit()) : null;
        }
        $supportPhysique = $produit instanceof Produit && (($produit->getChampsPerso()['supportPhysique'] ?? false) === true);

        $billetMeta = new BilletQrMeta();
        $billetMeta->setBilletSupport($support)
            ->setQrDynamique((string) $support->getIdentifiantSupport())
            ->setPassWalletDisponible(false)
            ->setRepliQr(true) // ⚠ HYPOTHÈSE MVP : aucun pass wallet réel intégré, repli systématique (RG-M3-14).
            ->setValiditeDebut($meta?->getCreneau()?->getDebut())
            ->setValiditeFin($meta?->getCreneau()?->getFin())
            ->setStatutRetraitPhysique($supportPhysique ? StatutRetraitPhysique::ARetirer : StatutRetraitPhysique::NonApplicable);
        $this->em->persist($billetMeta);

        if ($supportPhysique) {
            $pointRetrait = $this->em->getRepository(Espace::class)->findOneBy(['etablissement' => $vente->getEtablissement()]);
            $retrait = new RetraitClickCollect();
            $retrait->setBilletSupport($support)
                ->setPointRetrait($pointRetrait)
                ->setCodeRetrait(strtoupper(substr(bin2hex(random_bytes(6)), 0, 8)))
                ->setStatut(StatutRetrait::ARetirer)
                ->setEtablissement($vente->getEtablissement());
            $this->em->persist($retrait);
        }
    }

    private function resoudreBeneficiaire(Client $payeur, LigneCommandeMeta $meta): Beneficiaire
    {
        $ligneVente = $meta->getLigneVente();
        if ($ligneVente !== null && $ligneVente->getBeneficiaire() !== null) {
            $existant = $this->em->getRepository(Beneficiaire::class)->findOneBy(['client' => $ligneVente->getBeneficiaire()]);
            if ($existant instanceof Beneficiaire) {
                return $existant;
            }
        }

        $beneficiaire = $this->em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        if ($beneficiaire instanceof Beneficiaire) {
            return $beneficiaire;
        }

        $famille = new Famille();
        $famille->setPayeurPrincipal($payeur)->setGroupe($payeur->getGroupe())->setLibelle('Famille ' . ($payeur->getNom() ?? 'boutique'));
        $this->em->persist($famille);

        $beneficiaire = new Beneficiaire();
        $beneficiaire->setFamille($famille)->setClient($payeur)->setRole(RoleBeneficiaire::PayeurEtBeneficiaire);
        $this->em->persist($beneficiaire);
        $this->em->flush();

        return $beneficiaire;
    }

    /** @return array{0: TypeTarif, 1: string} */
    private function resoudrePrix(Produit $produit): array
    {
        foreach ($produit->getGrilles() as $grille) {
            $typeTarif = $grille->getTypeTarif();
            if ($typeTarif === null) {
                continue;
            }
            $prix = $this->resolveurPrix->resoudre($produit, $typeTarif, new \DateTimeImmutable(), Canal::EnLigne);
            if ($prix !== null) {
                return [$typeTarif, $prix];
            }
        }

        throw new UnprocessableEntityHttpException('Produit non commercialisé en ligne (aucun prix résolu, RG-M1-01/07).');
    }
}
