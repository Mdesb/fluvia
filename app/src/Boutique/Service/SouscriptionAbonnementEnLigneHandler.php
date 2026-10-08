<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\BilletQrMeta;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Entity\LigneCommandeMeta;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Enum\StatutTunnel;
use App\Boutique\Security\VitrineAccessibleGuard;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ProductSaleScopeGuard;
use App\Crm\Service\BeneficiaryResolver;
use App\Offre\Service\SubscriptionPriceResolver;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Membership\Service\SouscriptionAbonnementHandler;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Sepa\Service\IbanFormatValidator;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Achat d'abonnement avec mandat SEPA en ligne — **compte obligatoire** (US-L8-09, RG-M3-12/17).
 * Réutilise intégralement le module `App\Sepa` (tokenisation + coffre IBAN, §2.4 du plan) : aucun
 * port Boutique supplémentaire. Bloque tout parcours invité **avant** paiement (CA-13).
 */
final class SouscriptionAbonnementEnLigneHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierCalculateur $calculateur,
        private readonly GenerateurNumero $generateurNumero,
        private readonly SessionSystemeBoutiqueResolver $sessionSysteme,
        private readonly ValiderVenteService $validerVente,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
        private readonly SubscriptionPriceResolver $resolveurAbonnement,
        private readonly BeneficiaryResolver $beneficiaires,
        private readonly SouscriptionAbonnementHandler $souscription,
        private readonly VitrineAccessibleGuard $vitrineGuard,
        private readonly IbanFormatValidator $ibanValidator,
        private readonly ProductSaleScopeGuard $saleScope,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees {iban, bicDebiteur, debiteurNom, dateSignature?}
     */
    /**
     * @param Vitrine|null $vitrineAchat la boutique OU L'ACHAT A LIEU.
     *
     * **Pourquoi ce parametre existe.** Le gestionnaire lisait `compteClient->getVitrineCreation()`
     * et `compteClient->getEtablissement()` : un client inscrit chez Piscine A qui s'abonne chez
     * Patinoire B faisait entrer l'abonnement, le mandat SEPA et le panier DANS LES COMPTES DE
     * PISCINE A.
     *
     * Un compte global est une IDENTITE, pas une appartenance commerciale. L'argent, lui,
     * appartient au vendeur -- et une recette portee au mauvais etablissement ne se voit ni a la
     * vente, ni au prelevement, seulement a la cloture.
     *
     * > Confondre ou quelqu'un s'est inscrit avec ou il achete, c'est facturer le mauvais.
     *
     * `null` conserve l'ancien comportement : un appelant qui ne sait pas ou il vend ne doit pas
     * se voir refuser la vente, il doit se voir attribuer le defaut historique.
     */
    public function souscrire(
        ?CompteClient $compteClient,
        Produit $produit,
        array $donnees,
        ?Vitrine $vitrineAchat = null,
    ): Vente
    {
        // RG-M3-12/17 (CA-13) : un invité (aucun CompteClient) est bloqué avant paiement.
        if ($compteClient === null) {
            throw new AccessDeniedHttpException('Achat d\'abonnement réservé aux titulaires de compte (RG-M3-12) : création de compte requise.');
        }
        $formule = $produit->getFormule();
        if ($formule === null || !$formule->isSepaActif()) {
            throw new UnprocessableEntityHttpException('Ce produit ne porte pas la facette SEPA (RG-M3-17).');
        }

        // La vitrine où l'achat a lieu doit être publiquement accessible (établissement actif + canal
        // en_ligne ouvert), comme tout point d'entrée public (`VitrineAccessibleGuard`). `null` =
        // appelant historique sans vitrine : rien à vérifier, on garde le repli.
        if ($vitrineAchat instanceof Vitrine) {
            $this->vitrineGuard->verifier($vitrineAchat);
        }

        $client = $compteClient->getClient();
        \assert($client !== null);
        // L'etablissement du VENDEUR, pas celui ou le compte est ne.
        $etablissement = $vitrineAchat?->getEtablissement() ?? $compteClient->getEtablissement();

        // ── LE PRODUIT DOIT ÊTRE PROPOSÉ EN LIGNE, ICI (revue sécurité ; statut et site le 07/10/2026) ──
        // `isSepaActif()` ne dit RIEN du canal : un produit GUICHET-ONLY avec un tarif visible partout
        // devenait souscriptible en ligne, car `SubscriptionPriceResolver` résout sur la visibilité du
        // `TypeTarif`, pas sur `Produit.canaux`. Le catalogue et le tunnel billet imposent déjà
        // `Canal::EnLigne` ; ce chemin l'oubliait. 404 (D3) : un produit hors ligne n'existe pas ici.
        //
        // Le statut et le site manquaient aussi : un brouillon, un archivé, ou le produit d'un AUTRE
        // site (vendu au nom de cette vitrine, dans ses comptes) se souscrivaient. Même règle de site
        // que la caisse (D92 : aucun site = socle). Un seul `throw` : les quatre cas répondent pareil.
        if ($produit->getStatut() !== StatutProduit::Publie
            || !$produit->aCanal(Canal::EnLigne)
            || $etablissement === null
            || !$this->saleScope->isSoldAt($produit, $etablissement)) {
            throw new NotFoundHttpException('Ce produit n\'est pas proposé en ligne.');
        }

        $iban = \is_string($donnees['iban'] ?? null) ? $donnees['iban'] : '';
        $bic = \is_string($donnees['bicDebiteur'] ?? null) ? $donnees['bicDebiteur'] : '';
        $nomDebiteur = \is_string($donnees['debiteurNom'] ?? null) ? $donnees['debiteurNom'] : '';
        if (trim($iban) === '' || trim($nomDebiteur) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « debiteurNom » sont requis pour signer le mandat SEPA.');
        }
        // Format de l'IBAN (structure pays + clé mod-97) avant tokenisation/chiffrement — mêmes règles
        // qu'au guichet : un IBAN mal saisi ne doit pas n'échouer qu'au rejet bancaire.
        $this->ibanValidator->valider($iban);
        $dateSignature = isset($donnees['dateSignature']) && \is_string($donnees['dateSignature'])
            ? new \DateTimeImmutable($donnees['dateSignature'])
            : new \DateTimeImmutable('today');

        $token = $this->tokenisation->tokeniser($iban);
        $mandat = new MandatSepa();
        $mandat->setRum('RUM-' . strtoupper(substr(hash('sha256', uniqid('bou', true)), 0, 20)))
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer($iban))
            ->setBicDebiteur($bic)
            ->setDebiteurNom($nomDebiteur)
            ->setDateSignature($dateSignature)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($client)
            ->setEtablissement($etablissement);
        $this->em->persist($mandat);

        // ⚠ CETTE BOUCLE ÉTAIT UNE COPIE, ET `ResolvedSubscriptionPrice` DIT POURQUOI IL EXISTE :
        //    « Rendre le prix seul aurait obligé chaque appelant à refaire la boucle de résolution :
        //    trois copies au lieu d'une. » Celle-ci en était une — même produit, mêmes grilles, même
        //    canal, même résolveur. Deux copies s'accordent le premier jour et divergent le jour où
        //    l'une apprend une règle de plus, et ce jour-là la vente et l'échéancier ne diraient plus
        //    le même prix au même client.
        $resolu = $this->resolveurAbonnement->forProduct($produit, Canal::EnLigne, new \DateTimeImmutable());
        $typeTarif = $resolu->tariffType;
        $prix = $resolu->price;

        $session = $this->sessionSysteme->sessionSysteme($etablissement);
        $vente = new Vente();
        $vente->setSession($session)->setClient($client->getId())->setStatut(StatutVente::EnCours)
            ->setEtablissement($etablissement)->setNumero($this->generateurNumero->numeroVente($session));

        $ligneVente = new LigneVente();
        $ligneVente->setProduit($produit->getId())->setTypeTarif($typeTarif->getId())->setQuantite(1)->setPrixUnitaire($prix);
        $this->calculateur->recalculerLigne($ligneVente);
        $vente->addLigne($ligneVente);
        $this->em->persist($ligneVente);
        $this->calculateur->recalculerVente($vente);
        $this->em->persist($vente);

        $paiement = new Paiement();
        $paiement->setMoyenCode('sepa')->setMontant($vente->getTotal())->setDiffere(true);
        $vente->addPaiement($paiement);
        $this->em->persist($paiement);
        $this->calculateur->recalculerVente($vente);

        // Code de support (unique, signé HMAC — CA-12) généré automatiquement par
        // `ValiderVenteService::creerSupport()` ; seul le type « qr » est forcé ici.
        $this->validerVente->valider($vente, [(string) $ligneVente->getId() => ['type' => 'qr']]);

        $suivi = new SuiviCommandeEnLigne();
        $suivi->setVente($vente)->setVitrine($compteClient->getVitrineCreation())
            ->setPanierOrigine($this->panierFictifPourAbonnement($compteClient, $vitrineAchat))
            ->setCompteClient($compteClient)->setStatutTunnel(StatutTunnel::Confirme)->setEtablissement($etablissement);
        $this->em->persist($suivi);

        $meta = new LigneCommandeMeta();
        $meta->setLigneVente($ligneVente);
        $this->em->persist($meta);

        $support = $this->em->getRepository(BilletSupport::class)->findOneBy(['ligne' => $ligneVente]);
        if ($support instanceof BilletSupport) {
            $billetMeta = new BilletQrMeta();
            $billetMeta->setBilletSupport($support)
                ->setQrDynamique((string) $support->getIdentifiantSupport())
                ->setPassWalletDisponible(false)->setRepliQr(true);
            $this->em->persist($billetMeta);
        }

        $this->em->flush();

        /*
         * ── ET MAINTENANT LE CONTRAT, QUI MANQUAIT ─────────────────────────────────────────────
         *
         * ⚠ TOUT CE QUI PRÉCÈDE NE FAISAIT PAS UN ABONNÉ. Mandat signé, vente validée, QR posé — et
         *   ni `Membership`, ni échéancier, ni statut d'accès. Le client lisait « Abonnement
         *   souscrit » et n'était ni abonné ni jamais prélevé : aucune source SEPA ne lit une
         *   `Vente`, et le drapeau `differe` n'a qu'un lecteur, qui sert à autoriser un reste dû.
         *
         * ⚠ ON PASSE PAR `souscrire()`, PAS PAR UNE VARIANTE BOUTIQUE. Un seul endroit décide ce
         *   qu'est un abonnement : tarif résolu, échéancier généré, statut d'accès ouvert, termes de
         *   l'offre gelés. Une seconde définition oublierait l'un de ces gestes le jour où la
         *   première en gagne un cinquième.
         *
         * ⚠ ET LE MANDAT EST CELUI QU'ON VIENT DE SIGNER. Le tunnel fait saisir l'IBAN avant qu'un
         *   abonnement existe ; en refaire un ici mettrait deux RUM actifs sur le même client.
         *
         * La règle de l'adhérent est celle de Maxime : en ligne, l'acheteur est le payeur, et
         * l'adhérent est lui-même — `forPurchase()` le retrouve ou le crée.
         */
        $this->souscription->souscrire(
            adherent: $this->beneficiaires->forPurchase($client),
            payeur: $client,
            formule: $formule,
            etablissement: $etablissement,
            dateSouscription: Etablissement::jourCivil($etablissement),
            // Repli : la formule décide quand elle déclare `engagement.dureeMin`. En ligne, aucun
            // vendeur ne peut négocier une durée — 12 mois est le défaut de l'écran de souscription.
            dureeEngagementMois: 12,
            canal: Canal::EnLigne,
            mandatExistant: $mandat,
            // Le lien retour vente → abonnement : annuler la vente le résilie (décision du 07/10).
            sourceSaleLineId: $ligneVente->getId(),
            // Le QR affiché au client est celui de la vente : il devient l'accès de l'abonnement et
            // il est coupé avec lui (décision de Maxime du 07/10), au lieu d'un second QR jamais remis.
            saleTicketCode: $support instanceof BilletSupport ? $support->getIdentifiantSupport() : null,
        );

        return $vente;
    }

    /**
     * L'abonnement en ligne ne part pas d'un panier générique (endpoint spécialisé, §3 du plan) :
     * `SuiviCommandeEnLigne.panierOrigine` reste requis par le schéma (traçabilité) — on réutilise le
     * panier le plus récent du compte s'il en existe un, sinon on lève un panier n'est pas requis :
     * ce champ est rendu nullable applicativement via un panier vide auto-créé au besoin.
     */
    private function panierFictifPourAbonnement(CompteClient $compteClient, ?Vitrine $vitrineAchat): \App\Boutique\Entity\PanierEnLigne
    {
        $existant = $this->em->getRepository(\App\Boutique\Entity\PanierEnLigne::class)
            ->findOneBy(['compteClient' => $compteClient], ['dateCreation' => 'DESC']);
        if ($existant instanceof \App\Boutique\Entity\PanierEnLigne) {
            return $existant;
        }

        $panier = new \App\Boutique\Entity\PanierEnLigne();
        $panier->setVitrine($vitrineAchat ?? $compteClient->getVitrineCreation())
            ->setCompteClient($compteClient)
            ->setEtablissement($vitrineAchat?->getEtablissement() ?? $compteClient->getEtablissement())
            ->setStatut(\App\Boutique\Enum\StatutPanier::TransformeEnCommande);
        $this->em->persist($panier);

        return $panier;
    }
}
