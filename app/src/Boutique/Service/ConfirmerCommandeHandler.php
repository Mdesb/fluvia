<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Acces\Entity\DroitAcces;
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
use App\Crm\Entity\Consentement;
use App\Crm\Entity\Famille;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\Service\BeneficiaryResolver;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ResolveurPrix;
use App\Organisation\Entity\Espace;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Creneau;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Reservation\Service\JaugeRessourceMereHandler;
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
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly ConfirmationCommandeMailer $mailer,
        private readonly ProduitEtablissementGuard $etablissementGuard,
        private readonly BeneficiaryResolver $beneficiaires,
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
        $id = $this->clientAdapter->creerRapide(['email' => $email, 'nom' => 'Client boutique'], $panier->getEtablissement());
        $panier->setClientResolu($id);
        $client = $this->em->getRepository(Client::class)->find($id);
        \assert($client instanceof Client);

        return $client;
    }

    /**
     * RG-M3-08/§0 décision n°8 : refuse avant même l'initiation du paiement si plus de place.
     *
     * ⚠ EN QUANTITÉ, PAS « COMPLET OU NON ». Trois billets sur un créneau à deux places libres ne le
     * rendent pas « complet », et passaient. Ce contrôle reste un pré-contrôle : la place ne se prend
     * qu'à la confirmation, sous verrou (`prendrePlacesSousVerrou()`).
     */
    public function reverifierDisponibilite(PanierEnLigne $panier): void
    {
        $demandes = [];
        foreach ($panier->getLignes() as $ligne) {
            $creneau = $ligne->getCreneau();
            if ($creneau === null) {
                continue;
            }
            $cle = (string) $creneau->getId();
            $demandes[$cle] ??= [$creneau, 0];
            $demandes[$cle][1] += max(1, $ligne->getQuantite());
        }

        foreach ($demandes as [$creneau, $quantite]) {
            if ($this->jauge->estComplet($creneau)) {
                throw new ConflictHttpException('RG-M3-08 : créneau complet, place non disponible.');
            }
            if (!$this->jauge->peutAccueillir($creneau, $quantite)) {
                throw new ConflictHttpException(sprintf(
                    'RG-M3-08 : places insuffisantes sur ce créneau (%d demandée(s), %d restante(s)).',
                    $quantite,
                    $this->jauge->placesRestantes($creneau),
                ));
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
            // Statut et canal revérifiés ici, comme à l'ajout (`AjouterLignePanierProcessor`) : un
            // produit archivé ou retiré du canal en ligne APRÈS sa mise au panier se vendait encore.
            if ($ligne->getProduit()->getStatut() !== StatutProduit::Publie || !$ligne->getProduit()->aCanal(Canal::EnLigne)) {
                throw new UnprocessableEntityHttpException('Produit non publié ou non visible au canal en ligne (RG-M1-07/09).');
            }
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
        // ── LES PLACES SE PRENNENT AVANT LA VALIDATION, SOUS VERROU, EN QUANTITÉ ───────────────────────
        //
        // Les réservations se créaient APRÈS la validation, sans aucun contrôle de jauge : le seul
        // contrôle était `reverifierDisponibilite()`, à l'initiation du paiement. Tout ce qui se vendait
        // entre les deux — un guichet, une autre commande, une OTA — faisait déborder le créneau. Et
        // chaque réservation valait UNE place quelle que soit la quantité de la ligne : trois billets
        // (trois supports émis par `ValiderVenteService`) ne pesaient qu'une place sur la jauge.
        //
        // Désormais : places prises sous verrou (voir `JaugeCreneauGuard::verrouiller()`), quantité de la
        // ligne comprise, puis validation. Si une place manque, rien n'est validé et la demande de
        // remboursement de la décision n°8 est créée, comme pour tout conflit d'inventaire. Si la
        // validation échoue ensuite, les places prises sont rendues.
        //
        // Le paiement est enregistré APRÈS la prise de places : la transaction de prise de places ne
        // doit rien écrire d'autre, sinon son annulation effacerait un paiement capturé que la mémoire de
        // Doctrine croirait déjà en base.
        $payeur = $this->resoudreClient($panier);
        $reservations = $this->prendrePlacesSousVerrou($vente, $payeur);

        $paiement = new Paiement();
        $paiement->setMoyenCode($moyenCode)
            ->setMontant($vente->getTotal())
            ->setRefTPE($referenceTransaction)
            ->setStatutTPE(StatutTPE::Accepte);
        $vente->addPaiement($paiement);
        $this->em->persist($paiement);
        $this->calculateur->recalculerVente($vente);

        if ($reservations === null) {
            return $this->demanderRemboursement(
                $vente,
                'Conflit d\'inventaire à la confirmation (paiement déjà capturé) : une place de la commande a été prise entre la vérification et le paiement.',
            );
        }

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
            // La vente n'est pas validée : les places prises plus haut se rendent — sur le créneau
            // comme sur la jauge globale de la ressource, sinon le compteur garderait des unités que
            // plus aucune réservation ne justifie.
            foreach ($reservations as $reservation) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
                $porteuse = $reservation->getCreneau()?->getRessource();
                if ($porteuse !== null) {
                    $this->jaugeMere->decrementer($porteuse, $reservation->getQuantity());
                }
            }

            return $this->demanderRemboursement(
                $vente,
                'Conflit d\'inventaire à la confirmation (paiement déjà capturé) : ' . $e->getMessage(),
            );
        }

        $this->em->flush();

        foreach ($vente->getLignes() as $ligneVente) {
            $meta = $this->em->getRepository(LigneCommandeMeta::class)->findOneBy(['ligneVente' => $ligneVente]);

            // L'accès ne s'ouvre qu'une fois la vente validée, jamais à la prise de place.
            $reservation = $reservations[(string) $ligneVente->getId()] ?? null;
            if ($reservation !== null) {
                $this->projectionAcces->projeterSiApplicable($reservation);
            }

            // Un billet daté vaut pour son créneau, pas pour le jour de l'achat (décision du 08/10) :
            // chaque billet de la ligne, pas seulement le premier.
            $creneau = $meta?->getCreneau();
            foreach ($creneau !== null ? $this->em->getRepository(BilletSupport::class)->findBy(['ligne' => $ligneVente]) : [] as $billet) {
                $this->em->getRepository(DroitAcces::class)->findOneBy(['billetSupportRef' => $billet->getId()])
                    ?->setFenetreDebut($creneau->getDebut())->setFenetreFin($creneau->getFin());
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

        // L'accord marketing, s'il a été donné (#101, D2) : UNE ligne, au nom du payeur, d'après l'état
        // FINAL de la case — pas une par envoi de l'écran, et rien si elle a été décochée entre-temps.
        $versionMarketing = $panier->getMarketingOptInVersion();
        if ($versionMarketing !== null) {
            $accord = new Consentement(CanalConsentement::Email, EtatConsentement::Accorde);
            $accord->setClient($payeur)->setSource('boutique')->setTextVersion($versionMarketing);
            $this->em->persist($accord);
        }
        $panier->setStatut(StatutPanier::TransformeEnCommande);
        $this->em->flush();

        $this->mailer->envoyer($vente, $panier);

        return false;
    }

    /**
     * Prend, sous verrou, les places de chaque ligne à créneau de la vente — une réservation par ligne,
     * portant la quantité de la ligne.
     *
     * Les lectures (métadonnées de ligne, bénéficiaires) se font AVANT la transaction : une lecture
     * faite avant les verrous figerait l'instantané (voir `JaugeCreneauGuard::verrouiller()`).
     *
     * @return array<string, Reservation>|null par identifiant de ligne de vente ; `null` si une place
     *                                         manque — rien n'a alors été écrit
     */
    private function prendrePlacesSousVerrou(Vente $vente, Client $payeur): ?array
    {
        $lignes = [];
        $creneaux = [];
        $demandes = [];
        foreach ($vente->getLignes() as $ligneVente) {
            $meta = $this->em->getRepository(LigneCommandeMeta::class)->findOneBy(['ligneVente' => $ligneVente]);
            $creneau = $meta instanceof LigneCommandeMeta ? $meta->getCreneau() : null;
            if (!$creneau instanceof Creneau) {
                continue;
            }
            $quantite = max(1, $ligneVente->getQuantite());
            $lignes[] = [$ligneVente, $creneau, $quantite, $this->resoudreBeneficiaire($payeur, $meta)];
            $cle = (string) $creneau->getId();
            $creneaux[$cle] = $creneau;
            $demandes[$cle] = ($demandes[$cle] ?? 0) + $quantite;
        }

        if ($lignes === []) {
            return [];
        }

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $this->jauge->verrouiller(array_values($creneaux), null);

            foreach ($demandes as $cle => $quantite) {
                if (!$this->jauge->peutAccueillir($creneaux[$cle], $quantite)) {
                    // Rien n'a été écrit dans cette transaction : la défaire ne perd rien.
                    $connexion->rollBack();

                    return null;
                }
            }

            $reservations = [];
            foreach ($lignes as [$ligneVente, $creneau, $quantite, $beneficiaire]) {
                $reservation = new Reservation();
                $reservation->setCreneau($creneau)
                    ->setOrganisateur($beneficiaire)
                    ->setEtablissement($vente->getEtablissement())
                    ->setModeDecompte(ModeDecompteReservation::VenteUnite)
                    ->setVenteRattachee($vente)
                    ->setMontantDu('0.00')
                    ->setQuantity($quantite);
                $this->em->persist($reservation);
                // La jauge globale de la ressource compte ces places comme celles d'une réservation
                // ordinaire (RG-M5-08) : sans cet incrément, l'annulation de la commande rendrait au
                // compteur des unités que personne n'y a posées.
                $porteuse = $creneau->getRessource();
                if ($porteuse !== null) {
                    $this->jaugeMere->incrementer($porteuse, $quantite);
                }
                $reservations[(string) $ligneVente->getId()] = $reservation;
            }
            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $echec) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $echec;
        }

        return $reservations;
    }

    /**
     * §0 décision n°8b : paiement déjà capturé, place indisponible — jamais d'avoir automatique
     * (RG-M3-15) : une demande de remboursement pré-remplie est créée à la place.
     */
    private function demanderRemboursement(Vente $vente, string $motif): bool
    {
        $demande = new DemandeRemboursement();
        $demande->setVente($vente)
            ->setMotif($motif)
            ->setStatut(StatutDemandeRemboursement::Recue)
            ->setOrigineAutomatique(true)
            ->setEtablissement($vente->getEtablissement());
        $this->em->persist($demande);
        $this->em->flush();

        return true;
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

    /**
     * ⚠ CETTE RESOLUTION A DEMENAGE DANS `BeneficiaryResolver`, ET PAS PAR GOUT DU RANGEMENT.
     *
     * La souscription d'abonnement en ligne en avait besoin a l'identique. La recopier aurait donne
     * deux reponses a « qui est l'adherent de cet achat » — identiques le premier jour, divergentes
     * le jour ou l'une des deux apprend un cas de plus.
     */
    private function resoudreBeneficiaire(Client $payeur, LigneCommandeMeta $meta): Beneficiaire
    {
        return $this->beneficiaires->forPurchase($payeur, $meta->getLigneVente()?->getBeneficiaire());
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
