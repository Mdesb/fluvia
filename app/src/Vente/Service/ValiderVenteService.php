<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Enum\TypeSupport;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Port\AppairageAccesInterface;
use App\Vente\Port\CardRechargeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur de la validation d'une vente (CA-8/11/12/15, §2/§6 du plan), réutilisé par le guichet et par
 * la resynchro hors-ligne. Refuse si le reste dû > 0 (sauf paiement différé autorisé, RG-M2-03),
 * décrémente le stock atomiquement (§6), scelle l'opération dans la chaîne NF525 (§2), émet et
 * appaire les supports d'accès (CA-12), applique le seuil d'impression (CA-11).
 *
 * **CQ-1, RG-CQ1-08 (point critique d'atomicité recharge ⇄ vente).** Le corps de la méthode (décrément
 * de stock, création/recharge des supports, scellement, flush) s'exécute désormais dans une
 * transaction DBAL explicite (`Connection::transactional()`, PAS `EntityManagerInterface::wrapInTransaction()`
 * — celle-ci fermerait l'`EntityManager` sur TOUTE exception, y compris un refus métier ordinaire que
 * plusieurs appelants de `valider()` catchent pour continuer d'utiliser `$em` ensuite, ex.
 * `App\Boutique\Service\ConfirmerCommandeHandler::confirmerApresPaiementReussi()`). Toute transaction
 * imbriquée ouverte plus bas (`CardRechargeHandler`, `DecrementStockHandler`) rejoint cette même
 * transaction DBAL (comptage d'imbrication de `Doctrine\DBAL\Connection`, aucun COMMIT physique avant
 * la sortie de CETTE méthode) : l'incrément de crédit d'une recharge de carte ne peut donc jamais
 * rester acquis si le scellement NF525 de la même vente échoue ensuite — corrige un risque déjà
 * documenté (accepté jusqu'ici) sur le décrément de stock, qui committait en SQL brut hors de toute
 * transaction avant ce correctif.
 *
 * **CQ-1, D7-bis (correctif revue de cohérence) — publication différée après commit réel.** Le bus
 * d'événements est synchrone (`SymfonyEventBus::publish()` dispatche immédiatement). `CardRechargeInterface::recharge()`
 * ne publie donc plus elle-même `access.card_recharged` (elle s'exécute imbriquée dans la transaction
 * ci-dessous, avant le commit racine réel) : elle **retourne** l'événement, `creerSupport()` le
 * collecte dans `$this->evenementsEnAttente`, et `valider()` ne le publie qu'**après** le retour de
 * `$this->connection->transactional(...)` — donc après le commit physique réel. Si la transaction
 * échoue (exception), le `return` n'est jamais atteint : la liste accumulée est simplement abandonnée
 * (jamais publiée), et sera de toute façon réinitialisée au tout début du prochain appel à `valider()`.
 */
final class ValiderVenteService
{
    /**
     * Événements de recharge collectés PENDANT la transaction courante (RG-CQ1-*, D7-bis), publiés
     * seulement après son commit réel — jamais en cas de rollback. Réinitialisée en tête de chaque
     * `valider()` pour ne jamais fuiter d'une vente à l'autre.
     *
     * @var list<DomainEvent>
     */
    private array $evenementsEnAttente = [];

    public function __construct(
        private readonly RequiredComplementGuard $complementsObligatoires,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly PanierCalculateur $calculateur,
        private readonly TicketPrintingPolicy $politiqueTicket,
        private readonly DecrementStockHandler $stock,
        private readonly ScellementHandler $scellement,
        private readonly AppairageAccesInterface $appairage,
        private readonly GenerateurCodeSupport $generateurCode,
        private readonly CardRechargeInterface $cardRecharge,
        private readonly EventBus $eventBus,
    ) {
    }

    /**
     * @param array<string, array{type?: string, identifiant?: string}> $supportsOverride indexé par id de ligne
     */
    public function valider(Vente $vente, array $supportsOverride = []): OperationScellee
    {
        // D7-bis — repart toujours d'une liste vide : un appel précédent qui aurait échoué en cours de
        // route (donc jamais parvenu jusqu'à la publication post-commit ci-dessous) ne doit rien laisser
        // traîner pour CET appel-ci.
        $this->evenementsEnAttente = [];

        if ($vente->getStatut() !== StatutVente::EnCours) {
            throw new ConflictHttpException('Seule une vente en cours peut être validée (NF525).');
        }

        $this->calculateur->recalculerVente($vente);

        // RG-M2-03 — reste dû doit être 0, sauf s'il existe un paiement différé autorisé.
        $reste = $this->calculateur->centimes($vente->getResteAPayer());
        if ($reste > 0 && !$this->aPaiementDiffere($vente)) {
            throw new UnprocessableEntityHttpException('Reste dû non nul : validation impossible sans paiement différé (RG-M2-03).');
        }

        // ⚠ UNE VENTE SANS AUCUNE LIGNE SCELLAIT UNE OPÉRATION FISCALE POUR RIEN.
        //
        // Trouvé le 30/08 en vendant un vrai billet en préproduction : l'ajout de ligne avait échoué
        // sur une option obligatoire, la vente est restée vide, et `POST /valider` a rendu 201 —
        // consommant le numéro 1 de la chaîne NF525 et posant l'empreinte que la vente suivante
        // chaîne. Cette chaîne est **inaltérable par construction** : le numéro ne se libère pas,
        // l'entrée ne se retire pas. À une vraie caisse, un clic de trop laisse une écriture fiscale
        // définitive, et la clôture du jour la compte.
        //
        // ⚠ La garde du reste dû ne pouvait pas l'attraper : une vente sans ligne a un reste dû de
        // ZÉRO. Elle passait donc en satisfaisant parfaitement le contrôle — le même piège qu'une
        // assertion vacueusement vraie, qui réussit d'autant mieux qu'il n'y a rien à contrôler.
        //
        // ── « AUCUNE LIGNE » ET NON « TOTAL NUL », ET LA NUANCE COMPTE ─────────────────────────
        //
        // Un total nul est LÉGITIME : un billet offert, une remise de 100 %, un geste commercial.
        // Ces ventes-là portent des lignes, elles disent ce qui a été remis, et elles ont toute leur
        // place au journal. Refuser sur le total interdirait un cas réel ; refuser sur l'absence de
        // ligne ne refuse rien qui ait un sens.
        if ($vente->getLignes()->isEmpty()) {
            throw new UnprocessableEntityHttpException(
                'Une vente sans aucune ligne ne peut pas être validée : le scellement NF525 est '
                .'irréversible et consommerait un numéro de séquence pour rien.'
            );
        }

        // D44-bis — porté par la vente : une vente directe n'a pas de session d'où le déduire.
        $pdv = $vente->getPointDeVente();
        if ($pdv === null) {
            throw new UnprocessableEntityHttpException('Point de vente introuvable pour le scellement.');
        }

        // ── LES COMPLÉMENTS OBLIGATOIRES, AVANT TOUTE ÉCRITURE ─────────────────────────────────
        //
        // Un produit peut en exiger un autre — le bonnet de bain avec l'entrée bassin, quand le
        // règlement intérieur l'impose. La vérification est ici, et non après l'ouverture de la
        // transaction : un refus ne doit rien avoir commencé à écrire, sinon on découvre le
        // manquant au milieu d'un décrément de stock.
        $this->complementsObligatoires->verifier($vente);

        // Voir le docblock de classe (RG-CQ1-08) : cette transaction englobe le décrément de stock, la
        // création/recharge des supports ET le flush qui scelle l'OperationScellee.
        // Supports créés dans la transaction, suivis pour un nettoyage précis de l'UnitOfWork en cas
        // d'échec (voir le `catch` ci-dessous, correctif revue de cohérence).
        $supportsCrees = [];
        try {
            $operation = $this->connection->transactional(function () use ($vente, $supportsOverride, $pdv, &$supportsCrees): OperationScellee {
                // §6 — décrément de stock atomique (peut lever 422 « stock épuisé »), avant scellement.
                $this->stock->decrementer($vente);

                // CA-12 — émission et appairage des supports pour les lignes concernées.
                foreach ($vente->getLignes() as $ligne) {
                    $override = $supportsOverride[(string) $ligne->getId()] ?? null;

                    // RG-CQ8-01 (ARGENT) — une ligne émettrice à quantite = N doit émettre N supports,
                    // chacun avec son identifiant propre (et, pour une carte, chacun avec le stock de
                    // compostages initial). Correction du défaut préexistant : la vente facturait N
                    // (PanierCalculateur, payload NF525 `qte`=N) mais n'émettait qu'UN support.
                    // La recharge (creerSupport retourne null après avoir crédité, RG-CQ1-05) et les
                    // produits non émetteurs retournent null dès la 1re itération -> break, aucune
                    // répétition. L'identifiant explicite + quantite > 1 est refusé dans creerSupport
                    // (RG-CQ8-02), donc la boucle ne peut jamais réémettre deux fois le même code.
                    for ($unite = 0, $quantite = max(1, $ligne->getQuantite()); $unite < $quantite; ++$unite) {
                        $support = $this->creerSupport($vente, $ligne, $override);
                        if ($support === null) {
                            break;
                        }
                        $vente->addSupport($support);
                        $this->em->persist($support);
                        $supportsCrees[] = $support;
                        $this->appairage->appairer($support); // un échec laisse le support en « echec » (remise bloquée).
                    }
                }

                $vente->setStatut(StatutVente::Validee);

                // §2 — scellement NF525 dans la transaction de validation.
                $operation = $this->scellement->sceller(new OperationAScellerDto(
                    $pdv,
                    TypeOperationScellee::Vente,
                    'Vente',
                    $vente->getId(),
                    $this->payload($vente),
                ));

                // CA-11 — impression automatique au-dessus du seuil.
                // D44-bis — une vente directe n'a pas de comptoir, donc pas d'imprimante : la marquer
                // « imprimée » écrirait un fait qui n'a pas eu lieu. Le seuil par défaut valant 0 €,
                // sans cette condition **toute** vente directe serait déclarée imprimée.
                // ⚠ LA MEME REGLE QUE `TicketProcessor`, ET DESORMAIS LE MEME CODE.
                //
                // Elle etait recopiee ici. Le 29/08, la version de `TicketProcessor` a ete corrigee
                // pour qu'une vente entierement gratuite ne sorte pas de ticket -- et celle-ci ne
                // l'a pas ete : une vente a 0 € en session restait marquee « imprimee » pour un
                // document que l'autre refusait d'editer, et la reedition suivante aurait annonce
                // un DUPLICATA d'un ticket qui n'a jamais existe.
                if ($this->politiqueTicket->marqueImprimeeALaValidation($vente)) {
                    $vente->setImprime(true);
                }

                $this->em->flush();

                return $operation;
            });
        } catch (\Throwable $e) {
            // RG-CQ1-08 (correctif revue de cohérence) — `Connection::transactional()` fait un ROLLBACK
            // SQL *pur* : il ne vide PAS l'UnitOfWork Doctrine. Les BilletSupport créés ci-dessus restent
            // « scheduled for insert » et, comme `Vente->supports` est en `cascade: persist`, un appelant
            // qui catche l'exception pour continuer d'utiliser `$em` (ex.
            // `App\Boutique\Service\ConfirmerCommandeHandler::confirmerApresPaiementReussi()`) les
            // insèrerait au prochain `flush()` : support/billet orphelin d'une vente jamais scellée.
            // On restaure donc l'état mémoire à l'identique du rollback SQL — sans `em->clear()` global
            // (qui détacherait aussi la Vente et le Paiement de l'appelant) :
            //   1. retrait de la collection (coupe la cascade) + detach des supports créés ICI ;
            //   2. restauration du statut EnCours (le passage à Validee n'a pas été committé) ;
            //   3. abandon des événements de recharge accumulés (jamais publiés sur échec).
            foreach ($supportsCrees as $support) {
                $vente->removeSupport($support);
                if ($this->em->contains($support)) {
                    $this->em->detach($support);
                }
            }
            $vente->setStatut(StatutVente::EnCours);
            $this->evenementsEnAttente = [];

            throw $e;
        }

        // D7-bis — publié ICI, après le retour de `transactional()` : la transaction externe a alors
        // réellement committé (sinon une exception aurait déjà interrompu `valider()` plus haut, et ce
        // point ne serait jamais atteint). Aucun abonné synchrone ne peut donc jamais voir une recharge
        // que la vente finira par annuler.
        foreach ($this->evenementsEnAttente as $evenement) {
            $this->eventBus->publish($evenement);
        }
        $this->evenementsEnAttente = [];

        return $operation;
    }

    private function aPaiementDiffere(Vente $vente): bool
    {
        foreach ($vente->getPaiements() as $paiement) {
            if ($paiement->isDiffere()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{type?: string, identifiant?: string}|null $override
     */
    private function creerSupport(Vente $vente, LigneVente $ligne, ?array $override): ?BilletSupport
    {
        $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());
        if (!$produit instanceof Produit) {
            return null;
        }
        $type = $produit->getType();
        if (!$type instanceof TypeProduit || !$this->emetSupport($type)) {
            return null;
        }

        $identifiantOverride = isset($override['identifiant'])
            && \is_string($override['identifiant'])
            && trim($override['identifiant']) !== ''
                ? trim($override['identifiant'])
                : null;

        // RG-CQ1-01 — un identifiant déjà connu, sur une ligne portant une carte multi-entrées, bascule
        // l'opération en recharge (Option A, §6 de la spec) au lieu d'une émission. Rien ne change si
        // l'identifiant est absent ou inédit (CA-10, non-régression) : on tombe dans le code existant.
        if ($identifiantOverride !== null && $produit->getCarte() !== null) {
            $existant = $this->em->getRepository(BilletSupport::class)
                ->findOneBy(['identifiantSupport' => $identifiantOverride]);

            if ($existant instanceof BilletSupport) {
                // RG-CQ1-06 — cloisonnement, échec fermé : un identifiant qui existe mais appartient à
                // un autre établissement échoue COMME s'il n'existait pas (même 404 que
                // `AppairageProcessor`) — jamais un oracle cross-tenant, et surtout jamais une tentative
                // d'émission avec un identifiant déjà pris ailleurs (qui crasherait sur la contrainte
                // unique globale).
                $etabVente = $vente->getEtablissement();
                $etabExistant = $existant->getVente()?->getEtablissement();
                if ($etabVente === null || $etabExistant === null
                    || (string) $etabExistant->getId() !== (string) $etabVente->getId()) {
                    throw new NotFoundHttpException('Support introuvable.');
                }

                // RG-CQ1-07 (bullet 2) — conflit explicite au lieu du crash de contrainte unique.
                // N'arrive que si l'identifiant scanné est un billet/abonnement, pas une carte.
                if ($existant->getType() !== TypeSupport::Carte) {
                    throw new ConflictHttpException(
                        'Cet identifiant est déjà utilisé par un support qui n\'est pas une carte multi-entrées.'
                    );
                }

                // RG-CQ1-07 (cas limite §10 spec, arbitrage claude-A) — une ligne de recharge à
                // quantité > 1 facturerait plusieurs fois (`PanierCalculateur` multiplie le prix par la
                // quantité) pour un seul crédit (la recharge ne crédite le droit qu'UNE fois,
                // indépendamment de `quantite` — même règle que l'émission). Refus explicite AVANT tout
                // UPDATE de crédit, plutôt que multiplier le crédit (choix retenu pour ce premier lot :
                // plus simple, défendable). N'affecte pas l'émission normale à quantite > 1 (même
                // défaut, mais hors périmètre CQ-1 — CQ-8).
                if ($ligne->getQuantite() > 1) {
                    throw new UnprocessableEntityHttpException(
                        'Une ligne de recharge ne peut pas porter une quantité supérieure à 1 (RG-CQ1-07).'
                    );
                }

                // RG-CQ1-02/08 — crédit ajouté = stock initial du produit VENDU pour cette recharge,
                // même règle que l'émission (ligne ~163, non multiplié par la quantité — cas limite).
                $credits = $produit->getCarte()->getStockCompostagesInitial();
                // D7-bis — l'événement retourné n'est PAS publié ici : collecté, il ne partira qu'après
                // le commit réel de la transaction englobante (cf. docblock de classe et `valider()`).
                $evenement = $this->cardRecharge->recharge($existant, $credits);
                if ($evenement instanceof DomainEvent) {
                    $this->evenementsEnAttente[] = $evenement;
                }

                // RG-CQ1-05 — aucun nouveau BilletSupport : la ligne de recharge n'en produit pas (même
                // branche que le cas déjà géré ci-dessous, `if ($support === null) { continue; }`).
                return null;
            }
            // Identifiant inédit : comportement actuel inchangé, on continue ci-dessous (CA-8).
        }

        // RG-CQ8-02 — un identifiant de support explicite (fourni par l'appelant) ne peut désigner
        // qu'UN seul support : la contrainte d'unicité globale sur `identifiantSupport` interdit de le
        // réutiliser pour les N unités d'une ligne à quantite > 1. Refus explicite (422), symétrique au
        // refus de recharge à quantite > 1 (RG-CQ1-07). L'émission sans identifiant (auto-génération)
        // n'est pas concernée : chaque unité reçoit un code unique distinct.
        if ($identifiantOverride !== null && $ligne->getQuantite() > 1) {
            throw new UnprocessableEntityHttpException(
                'Un identifiant de support explicite impose une quantité de 1 (RG-CQ8-02).'
            );
        }

        $support = new BilletSupport();
        $support->setLigne($ligne);
        if (isset($override['type']) && ($enum = TypeSupport::tryFrom($override['type'])) !== null) {
            $support->setType($enum);
        } elseif ($type->aFacette(TypeProduit::FACETTE_CARNET)) {
            $support->setType(TypeSupport::Carte);
        }
        if ($identifiantOverride !== null) {
            $support->setIdentifiantSupport($identifiantOverride);
        } else {
            // Aucun identifiant fourni : génère un code de support unique et signé (CA-12, cf.
            // App\Vente\Service\GenerateurCodeSupport) pour tout support émis — billet, carte,
            // abonnement, billet boutique.
            $support->setIdentifiantSupport($this->genererIdentifiantUnique($support->getType()));
        }
        if ($produit->getCarte() !== null) {
            $support->setNbCompostages($produit->getCarte()->getStockCompostagesInitial());
        }

        return $support;
    }

    /**
     * Génère un code signé et retente en cas de collision (probabilité négligeable, ~80 bits
     * d'entropie) — défense en profondeur, la contrainte unique en base reste la garantie ultime.
     */
    private function genererIdentifiantUnique(TypeSupport $type): string
    {
        $repository = $this->em->getRepository(BilletSupport::class);
        for ($tentative = 0; $tentative < 5; ++$tentative) {
            $code = $this->generateurCode->genererPourType($type);
            if ($repository->findOneBy(['identifiantSupport' => $code]) === null) {
                return $code;
            }
        }

        throw new ConflictHttpException('Impossible de générer un code de support unique après plusieurs tentatives.');
    }

    private function emetSupport(TypeProduit $type): bool
    {
        return $type->aFacette(TypeProduit::FACETTE_BILLET)
            || $type->aFacette(TypeProduit::FACETTE_CARNET)
            || $type->aFacette(TypeProduit::FACETTE_ACCES);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Vente $vente): array
    {
        $moyens = [];
        foreach ($vente->getPaiements() as $paiement) {
            $moyens[] = ['moyen' => $paiement->getMoyenCode(), 'montant' => $paiement->getMontant()];
        }
        $lignes = [];
        foreach ($vente->getLignes() as $ligne) {
            $lignes[] = [
                'produit' => (string) $ligne->getProduit(),
                'qte' => $ligne->getQuantite(),
                'montant' => $ligne->getMontantLigne(),
            ];
        }

        return [
            'vente' => (string) $vente->getId(),
            'numero' => $vente->getNumero(),
            'date' => $vente->getDate()->format(\DateTimeInterface::ATOM),
            'total' => $vente->getTotal(),
            'totalRemises' => $vente->getTotalRemises(),
            'lignes' => $lignes,
            'paiements' => $moyens,
        ];
    }
}
