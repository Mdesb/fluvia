<?php

declare(strict_types=1);

namespace App\Sport\Adapter;

use App\Crm\Entity\Client;
use App\Crm\Service\BeneficiaryResolver;
use App\Offre\Entity\Formule;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sport\Repository\SubscriptionRepository;
use App\Sport\Service\SouscriptionAbonnementHandler;
use App\Vente\Entity\Vente;
use App\Vente\Port\MandateChoice;
use App\Vente\Port\SaleSubscriptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LA CAISSE CRÉE L'ABONNEMENT (arbitrage de Maxime du 07/09). Implémentation réelle du port
 * `App\Vente\Port\SaleSubscriptionInterface` (même patron d'inversion de dépendance que
 * `CardRechargeHandler`/`SaleAccessPairingAdapter`) : à partir d'une vente validée au guichet, chaque
 * ligne portant un produit-abonnement (une `Formule` en prélèvement SEPA) donne naissance à un
 * `AbonnementFitness` complet — tarif résolu, mandat, échéancier, statut d'accès — via l'unique
 * `SouscriptionAbonnementHandler::souscrire()`. Un seul endroit décide ce qu'est un abonnement ; on ne
 * réécrit pas une variante « caisse » qui oublierait un geste le jour où la première en gagne un.
 *
 * ⚠ APPELÉE DANS LA TRANSACTION DE SCELLEMENT (`ValiderVenteService::valider()`, via le rappel
 *   `$apresScellement`). Tout refus ci-dessous lève une exception qui fait ROLLBACK le scellement
 *   NF525 de la même vente : la vente reste `EnCours`, rejouable, et l'API rend une 422 (ou 404)
 *   honnête. Jamais d'abonnement sans vente scellée, jamais de vente scellée sans son abonnement.
 *
 * ⚠ 1ER MOIS AU COMPTOIR, SEPA DÈS LE 2E (arbitrage de Maxime). Le prix de la ligne encaisse le
 *   premier mois DANS la vente ; la première échéance SEPA vaut donc 0 (`montantPremiereCentimes: 0`,
 *   valeur explicitement acceptée par `souscrire()`), et le prélèvement récurrent commence au 2e mois.
 *   Passer `null` ferait prélever le 1er mois une seconde fois — une fois au comptoir, une fois par
 *   SEPA.
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

    public function createSubscriptionsFromSale(Vente $vente, MandateChoice $mandate): void
    {
        $etablissement = $vente->getEtablissement();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement de la vente introuvable : abonnement impossible.');
        }

        foreach ($vente->getLignes() as $ligne) {
            $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());
            if (!$produit instanceof Produit) {
                continue;
            }
            $formule = $produit->getFormule();
            // Un produit-abonnement = une formule en prélèvement SEPA. Les autres lignes (billet,
            // carnet, accès simple) ne nous concernent pas : la boucle les ignore, elle ne refuse rien.
            if (!$formule instanceof Formule || !$formule->isSepaActif()) {
                continue;
            }

            // ── IDEMPOTENCE ────────────────────────────────────────────────────────────────────
            // Une ligne ne crée qu'UN abonnement. `sourceSaleLineId` est UNIQUE en base (filet
            // ultime) ; on court-circuite ici pour ne pas relancer une souscription complète si la
            // validation était rejouée. `valider()` refuse déjà de re-sceller une vente non `EnCours`,
            // c'est donc une défense en profondeur — pas l'unique garde.
            if ($this->abonnements->findOneBySourceSaleLine($ligne->getId()) !== null) {
                continue;
            }

            // ── QUANTITÉ > 1 REFUSÉE ───────────────────────────────────────────────────────────
            // Un abonnement engage UN adhérent. Une ligne à quantité N facture N fois
            // (`PanierCalculateur` multiplie par la quantité) mais ne pourrait créer qu'un seul
            // contrat : refus explicite AVANT toute écriture, comme la recharge (RG-CQ1-07), plutôt
            // qu'un abonnement unique pour N encaissés — un mensonge que personne ne verrait.
            if ($ligne->getQuantite() > 1) {
                throw new UnprocessableEntityHttpException(
                    'Une ligne d\'abonnement ne peut pas porter une quantité supérieure à 1 : '
                    . 'un abonnement engage un adhérent unique. Ajoutez une ligne par adhérent.'
                );
            }

            // ── LE PAYEUR = LE CLIENT DE LA VENTE ──────────────────────────────────────────────
            // Une vente anonyme ne peut pas porter d'abonnement : un mandat SEPA suppose un débiteur
            // nommé, et l'échéancier un compte à prélever.
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
            // groupe ni d'établissement (`findOneBy(['client' => $designated])`). Une ligne qui
            // désignerait un client d'un autre locataire y rattacherait donc l'abonnement — la fuite.
            // On refuse AVANT d'appeler forPurchase : le désigné doit partager le groupe du payeur,
            // exactement la portée que forPurchase suppose lui-même en bâtissant la `Famille` avec
            // `$payer->getGroupe()`. 404 et non 403, comme `CardRechargeHandler`/`AppairageProcessor` :
            // ne jamais confirmer l'existence d'un client d'un autre établissement.
            if ($designe instanceof Client
                && (string) $designe->getGroupe()?->getId() !== (string) $payeur->getGroupe()?->getId()) {
                throw new NotFoundHttpException('Bénéficiaire introuvable.');
            }

            $adherent = $this->beneficiaires->forPurchase($payeur, $designe);

            // ── LE MANDAT ──────────────────────────────────────────────────────────────────────
            // Trois modes existent (cf. `MandateChoice`). « counter » signe un mandat au comptoir
            // depuis l'IBAN saisi ; « existing » réutilise un mandat actif du client. « pending »
            // (IBAN capturé plus tard) reste refusé explicitement tant qu'il n'est pas câblé : jamais
            // dégradé en silence vers un autre comportement sur un prélèvement.
            //
            // `souscrire()` refuse lui-même (422) si le mandat manque, résout le tarif depuis la grille
            // du produit et gèle la cadence sur la formule : on ne redécide rien de tout cela ici. Le
            // 1er mois est encaissé dans la vente, la 1re échéance SEPA vaut donc 0 (voir docblock).
            match ($mandate->mode) {
                MandateChoice::MODE_COUNTER => $this->souscription->souscrire(
                    adherent: $adherent,
                    payeur: $payeur,
                    formule: $formule,
                    etablissement: $etablissement,
                    dateSouscription: $vente->getDate(),
                    dureeEngagementMois: 12,
                    ibanClair: $mandate->iban,
                    titulaireMandat: $mandate->titulaire,
                    montantPremiereCentimes: 0,
                    canal: Canal::Guichet,
                    bicDebiteur: $mandate->bic,
                    sourceSaleLineId: $ligne->getId(),
                ),
                MandateChoice::MODE_EXISTING => $this->souscription->souscrire(
                    adherent: $adherent,
                    payeur: $payeur,
                    formule: $formule,
                    etablissement: $etablissement,
                    dateSouscription: $vente->getDate(),
                    dureeEngagementMois: 12,
                    montantPremiereCentimes: 0,
                    canal: Canal::Guichet,
                    mandatExistant: $this->resoudreMandatExistant($mandate, $payeur, $etablissement),
                    sourceSaleLineId: $ligne->getId(),
                ),
                default => throw new UnprocessableEntityHttpException(sprintf(
                    'Le mode de mandat « %s » n\'est pas encore disponible à la caisse : signez le '
                    . 'mandat au comptoir (IBAN + titulaire) ou réutilisez un mandat existant du client.',
                    $mandate->mode,
                )),
            };
        }
    }

    /**
     * Le mandat à réutiliser (mode « existing »), résolu puis VALIDÉ dans le périmètre. Un mandat
     * désigné par son id doit exister ; à défaut d'id, on prend le mandat actif du client. Dans tous
     * les cas il doit être ACTIF, appartenir au payeur ET à l'établissement vendeur — échec fermé
     * (422, message identique) sinon : jamais le mandat d'un autre client ni d'un autre locataire, et
     * jamais un oracle sur l'existence d'un mandat hors périmètre.
     */
    private function resoudreMandatExistant(MandateChoice $mandate, Client $payeur, Etablissement $etablissement): MandatSepa
    {
        $repo = $this->em->getRepository(MandatSepa::class);
        if (null !== $mandate->mandateId) {
            $mandat = Uuid::isValid($mandate->mandateId) ? $repo->find(Uuid::fromString($mandate->mandateId)) : null;
        } else {
            $mandat = $repo->findOneBy([
                'client' => $payeur,
                'etablissement' => $etablissement,
                'statut' => StatutMandatSepa::Actif,
            ]);
        }

        if (!$mandat instanceof MandatSepa
            || StatutMandatSepa::Actif !== $mandat->getStatut()
            || (string) $mandat->getClient()?->getId() !== (string) $payeur->getId()
            || (string) $mandat->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new UnprocessableEntityHttpException(
                'Aucun mandat SEPA actif pour ce client sur cet établissement : signez-en un au '
                . 'comptoir (IBAN + titulaire).'
            );
        }

        return $mandat;
    }

    private function resoudreClient(?Uuid $id): ?Client
    {
        if ($id === null) {
            return null;
        }

        return $this->em->getRepository(Client::class)->find($id);
    }
}
