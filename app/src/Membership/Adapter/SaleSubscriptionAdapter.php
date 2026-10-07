<?php

declare(strict_types=1);

namespace App\Membership\Adapter;

use App\Crm\Entity\Client;
use App\Crm\Service\BeneficiaryResolver;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Repository\SubscriptionRepository;
use App\Membership\Service\SouscriptionAbonnementHandler;
use App\Offre\Entity\Formule;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Port\SaleSubscriptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LA CAISSE CRÉE L'ABONNEMENT, APRÈS LE COMMIT (spec-caisse-abonnement CP-1 G-1/G-5, plan CP-2
 * É1/É2). Implémentation réelle de `App\Vente\Port\SaleSubscriptionInterface` : pour chaque ligne
 * d'une vente comptoir validée qui porte un produit à facette Formule, crée l'abonnement
 * (`App\Membership\Entity\Membership`) via l'unique `SouscriptionAbonnementHandler::souscrire()`.
 * Un seul endroit décide ce qu'est un abonnement ; on ne réécrit pas une variante « caisse » qui
 * oublierait un geste le jour où la première en gagne un.
 *
 * ⚠ MANDAT « EN ATTENTE », SANS LIEN — CE LOT (É1/É2) SEULEMENT. La captation du mandat par lien de
 *   signature (G-3, É5) n'est pas câblée ici : l'abonnement naît avec un mandat SEPA au statut
 *   `EnAttente`, sans IBAN, non prélevable — jamais un mandat `Actif` fabriqué depuis un IBAN tapé
 *   par le caissier (G-3 l'interdit explicitement). Le prix du 1er mois encaissé au comptoir (É4) et
 *   le canal→régime (É6) ne sont pas traités ici non plus.
 *
 * ⚠ APPELÉE APRÈS LE COMMIT (ValiderVenteProcessor, jamais dans la transaction de scellement) : un
 *   refus ci-dessous laisse la vente SCELLÉE et VALIDE, rejouable côté reprise — pas de rollback
 *   (G-5, correction du montage transactionnel faux de l'ancienne branche
 *   `feature/caisse-abonnement`, qui créait l'abonnement DANS la transaction scellée).
 *   Le payeur manquant, lui, est refusé AVANT le scellement (`assertSubscribable()`, décision de
 *   Maxime du 07/10).
 */
final class SaleSubscriptionAdapter implements SaleSubscriptionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SouscriptionAbonnementHandler $souscription,
        private readonly BeneficiaryResolver $beneficiaires,
        private readonly SubscriptionRepository $abonnements,
    ) {
    }

    /**
     * AVANT LE SCELLEMENT (décision de Maxime du 07/10, qui revoit G-5). Une vente anonyme qui ouvre
     * un abonnement était scellée PUIS refusée : l'argent entrait, sans abonnement possible ni reprise
     * (aucun débiteur pour le mandat SEPA). On la refuse tant qu'elle est encore ouverte.
     */
    public function assertSubscribable(Vente $vente): void
    {
        if ($this->subscriptionLines($vente) !== [] && !$this->resoudreClient($vente->getClient()) instanceof Client) {
            throw new UnprocessableEntityHttpException(
                'Un abonnement exige un client payeur : rattachez le client à la vente avant de la valider. '
                . 'Rien n\'a été scellé.'
            );
        }
    }

    public function createSubscriptionsFromSale(Vente $vente): void
    {
        $etablissement = $vente->getEtablissement();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement de la vente introuvable : abonnement impossible.');
        }

        foreach ($this->subscriptionLines($vente) as [$ligne, $formule]) {
            // ── IDEMPOTENCE (G-5) ──────────────────────────────────────────────────────────────
            // Une ligne ne crée qu'UN abonnement. `sourceSaleLineId` est UNIQUE en base (filet
            // ultime) ; court-circuit ici pour ne pas relancer une souscription complète si cette
            // méthode est rejouée (reprise explicite après un échec, cf. docblock de classe).
            if ($this->abonnements->findOneBySourceSaleLine($ligne->getId()) !== null) {
                continue;
            }

            // ── QUANTITÉ > 1 REFUSÉE ───────────────────────────────────────────────────────────
            // Un abonnement engage UN adhérent. Une ligne à quantité N facture N fois mais ne
            // pourrait créer qu'un seul contrat : refus explicite plutôt qu'un abonnement unique
            // pour N encaissés — un mensonge que personne ne verrait.
            if ($ligne->getQuantite() > 1) {
                throw new UnprocessableEntityHttpException(
                    'Une ligne d\'abonnement ne peut pas porter une quantité supérieure à 1 : '
                    . 'un abonnement engage un adhérent unique. Ajoutez une ligne par adhérent.'
                );
            }

            // ── LE PAYEUR = LE CLIENT DE LA VENTE ──────────────────────────────────────────────
            // Une vente anonyme ne peut pas porter d'abonnement : un mandat SEPA suppose un
            // débiteur nommé.
            $payeur = $this->resoudreClient($vente->getClient());
            if (!$payeur instanceof Client) {
                throw new UnprocessableEntityHttpException(
                    'Un abonnement exige un client payeur : une vente anonyme ne peut pas en porter '
                    . '(aucun débiteur pour le mandat SEPA).'
                );
            }

            // L'adhérent désigné par la ligne (nullable : à défaut, le payeur est l'adhérent).
            $designe = $this->resoudreClient($ligne->getBeneficiaire());

            // ── CLOISONNEMENT, ÉCHEC FERMÉ ─────────────────────────────────────────────────────
            // `BeneficiaryResolver::forPurchase()` résout l'adhérent désigné SANS aucun filtre de
            // groupe ni d'établissement. On refuse AVANT de l'appeler : le désigné doit partager le
            // groupe du payeur. 404 et non 403 : ne jamais confirmer l'existence d'un client d'un
            // autre établissement.
            if ($designe instanceof Client
                && (string) $designe->getGroupe()?->getId() !== (string) $payeur->getGroupe()?->getId()) {
                throw new NotFoundHttpException('Bénéficiaire introuvable.');
            }

            $adherent = $this->beneficiaires->forPurchase($payeur, $designe);

            // ── L'ABONNEMENT DÉJÀ SOUSCRIT PAR CE PARCOURS (G-5 : formule déjà active → 1 seul) ──
            // L'écran de souscription souscrit d'abord, PUIS encaisse le 1er mois dans une vente qui
            // porte la même formule : souscrire ici en créait un second (PR #276, 2 → 3). On relie la
            // ligne à l'abonnement actif du jour, même payeur, même formule, qu'aucune ligne n'a encore
            // payé — la clé d'idempotence de `/sport/abonnements/souscrire`. La ligne de l'écran ne
            // nomme pas l'adhérent : il ne départage que si elle le désigne.
            $criteres = [
                'payeur' => $payeur, 'formule' => $formule, 'etablissement' => $etablissement,
                'statut' => MembershipStatus::Actif, 'dateSouscription' => $vente->getDate(), 'sourceSaleLineId' => null,
            ];
            if ($designe instanceof Client) {
                $criteres['adherent'] = $adherent;
            }
            $dejaSouscrit = $this->abonnements->findOneBy($criteres);
            if ($dejaSouscrit instanceof Membership) {
                $dejaSouscrit->setSourceSaleLineId($ligne->getId());
                $this->em->flush();
                continue;
            }

            $this->souscription->souscrire(
                adherent: $adherent,
                payeur: $payeur,
                formule: $formule,
                etablissement: $etablissement,
                dateSouscription: $vente->getDate(),
                dureeEngagementMois: 12,
                canal: Canal::Guichet,
                mandatEnAttente: true,
                sourceSaleLineId: $ligne->getId(),
            );
        }
    }

    /**
     * Les lignes qui ouvrent un abonnement. Seul un produit à facette Formule nous concerne (G-1) ;
     * les autres (billet, carnet, accès simple) sont ignorés, pas refusés.
     *
     * ── OPT-OUT « VENDU COMME PRODUIT SIMPLE » (sauf paramétrage contraire) ─────────────────────
     * Un abonnement se souscrit (mandat + contrat) partout — SAUF si la fiche produit demande de le
     * vendre comme produit SIMPLE. Alors la ligne est encaissée telle quelle et n'ouvre AUCUN
     * abonnement ni mandat. Le drapeau vit dans `champsPerso`, comme « bénéficiaire obligatoire ».
     * (Au comptoir, un tel produit n'ouvre d'ailleurs pas la modale de souscription : il passe par la
     * vente normale, qui atterrit ici.)
     *
     * @return list<array{0: LigneVente, 1: Formule}>
     */
    private function subscriptionLines(Vente $vente): array
    {
        $lignes = [];
        foreach ($vente->getLignes() as $ligne) {
            $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());
            $formule = $produit instanceof Produit ? $produit->getFormule() : null;
            if ($formule instanceof Formule
                && (($produit->getChampsPerso() ?? [])['venteSansSouscription'] ?? false) !== true) {
                $lignes[] = [$ligne, $formule];
            }
        }

        return $lignes;
    }

    private function resoudreClient(?Uuid $id): ?Client
    {
        if ($id === null) {
            return null;
        }

        return $this->em->getRepository(Client::class)->find($id);
    }
}
