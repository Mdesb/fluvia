<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeDroitAcces;
use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Entity\Produit;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\BilletSupport;
use App\Vente\Port\CardRechargeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Implémentation réelle (pas de stub, RG-CQ1-09) de la recharge d'une carte multi-entrées. Résout le
 * `App\Acces\Entity\Support`/`Appairage`/`DroitAcces` déjà appairés au support scanné, applique les
 * refus explicites (RG-CQ1-07 — reprend les libellés de `ValidationPassageHandler` quand la condition
 * est identique), incrémente `creditRestant` par un `UPDATE` SQL conditionnel (RG-CQ1-08, patron
 * `ValidationPassageHandler:188-204`), recalcule `fenetreFin` (RG-CQ1-04, `CardExpiryCalculator`) et
 * bascule `Support.versionMaj` (RG-CQ1-03).
 *
 * **Ne publie PAS `access.card_recharged` elle-même** (correctif revue de cohérence, D7-bis) : le bus
 * est synchrone et cette méthode s'exécute imbriquée dans la transaction externe de
 * `ValiderVenteService::valider()` (voir plus bas) — publier ici publierait avant le commit racine réel.
 * L'événement est construit et **retourné** ; c'est `ValiderVenteService::valider()` qui le publie,
 * après le retour de sa propre transaction (donc après le commit réel, jamais en cas de rollback).
 *
 * **Atomicité recharge ⇄ vente (point critique, cf. rapport d'implémentation) :** ce service n'ouvre
 * sa propre transaction DBAL que pour rester correct s'il est un jour appelé hors du flux de vente
 * (défense en profondeur, même patron que `ValidationPassageHandler`). Appelé depuis
 * `ValiderVenteService::valider()` (son unique appelant actuel, via `creerSupport()`), il s'exécute
 * **imbriqué** dans la transaction DBAL que `valider()` ouvre désormais elle-même : la connexion
 * Doctrine compte les niveaux d'imbrication et ne committe physiquement qu'à la fermeture du niveau le
 * plus externe (`Doctrine\DBAL\Connection::transactional()`/`beginTransaction()`/`commit()`) — le
 * `transactional()` local ci-dessous ne produit donc AUCUN commit physique séparé tant qu'il est
 * appelé depuis `valider()` : l'incrément de crédit ne devient définitivement acquis qu'avec le
 * scellement NF525 de la même vente.
 */
final class CardRechargeHandler implements CardRechargeInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly SnapshotVersionBumper $bumper,
        private readonly CardExpiryCalculator $cardExpiry,
        private readonly Security $security,
    ) {
    }

    public function recharge(BilletSupport $support, int $credits): ?DomainEvent
    {
        $etablissement = $support->getVente()?->getEtablissement();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement de la vente introuvable.');
        }

        // RG-CQ1-06/07 (bullet « jamais appairée ») — un identifiant sans Support Accès est traité de
        // la même façon qu'un identifiant d'un autre établissement dans `ValiderVenteService::creerSupport()`
        // (échec fermé), avec un message distinct : la carte est déjà connue et légitime côté Vente
        // (filtrée en amont), le problème est qu'elle n'a jamais été appairée.
        $accesSupport = $this->em->getRepository(Support::class)
            ->findOneBy(['identifiant' => $support->getIdentifiantSupport()]);
        if (!$accesSupport instanceof Support) {
            throw new UnprocessableEntityHttpException(
                'Support jamais appairé côté Accès : finalisez POST /acces/appairages avant de recharger (RG-CQ1-07).'
            );
        }

        // Cloisonnement de sécurité, échec fermé — comme `AppairageProcessor` (D3/D8) : le cas normal
        // est déjà écarté en amont (`creerSupport()`), mais un `Support` Accès pourrait exister sans
        // qu'aucun `BilletSupport` ne porte le même identifiant côté Vente si l'appairage a été fait
        // « à la main » avec un identifiant différent — improbable, refusé quand même. 404 et non 403 :
        // ne jamais confirmer l'existence d'un support d'un autre établissement.
        if ((string) $accesSupport->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new NotFoundHttpException('Support introuvable.');
        }

        // RG-CQ1-07 (bullet « support bloqué ») — même condition, même message que
        // `ValidationPassageHandler::valider()` étape 2.
        if ($accesSupport->getStatut() === StatutSupport::Bloque) {
            throw new ConflictHttpException('Support bloqué (perte/vol) : recharge refusée (RG-ACC-07).');
        }

        $appairage = $this->em->getRepository(Appairage::class)
            ->findOneBy(['support' => $accesSupport, 'actif' => true]);
        $droit = $appairage?->getDroit();
        if (!$droit instanceof DroitAcces) {
            // Couvre aussi bien « jamais appairé » que « appairage révoqué » : les deux impliquent de
            // repasser par POST /acces/appairages avant de recharger.
            throw new UnprocessableEntityHttpException(
                'Support non appairé à un droit actif : finalisez POST /acces/appairages avant de recharger (RG-CQ1-07).'
            );
        }

        // RG-CQ1-07 (bullet « droit dévalidé ») — même message que `ValidationPassageHandler` étape 3.
        if ($droit->getStatutProjection() !== StatutProjectionDroit::Valide) {
            throw new ConflictHttpException('Droit dévalidé : recharge refusée (RG-CQ1-07).');
        }

        // RG-CQ1-07 (bullet « sourceType ≠ CarteQuota ») — la recharge ne s'applique qu'aux droits à crédit.
        if ($droit->getSourceType() !== TypeDroitAcces::CarteQuota) {
            throw new ConflictHttpException(
                'Ce droit n\'est pas un droit à crédit (carte) : recharge refusée (RG-CQ1-07).'
            );
        }

        // RG-CQ1-04 — nouvelle échéance, calculée depuis la CarteMultiEntrees du produit VENDU pour
        // cette recharge (résolue depuis BilletSupport → LigneVente → Produit).
        $carte = $this->carteVendue($support);
        $nouvelleEcheance = $carte !== null
            ? $this->cardExpiry->calculer($carte, $droit->getFenetreFin(), new \DateTimeImmutable(), $droit->getEtablissement())
            : $droit->getFenetreFin();

        $droitId = $droit->getId();

        // RG-CQ1-08 — UPDATE SQL conditionnel, même patron que `ValidationPassageHandler:188-204`. Voir
        // la note de classe : ce `transactional()` s'imbrique dans la transaction ouverte par
        // `ValiderVenteService::valider()` sans committer séparément.
        $this->connection->transactional(function () use (
            $droit, $credits, $nouvelleEcheance, $droitId
        ): void {
            $this->connection->executeStatement(
                'UPDATE acces_droit_acces SET credit_restant = credit_restant + :n, fenetre_fin = :fin, updated_at = UTC_TIMESTAMP() WHERE id = UNHEX(:hex)',
                [
                    'n' => $credits,
                    'fin' => $nouvelleEcheance?->format('Y-m-d H:i:s'),
                    'hex' => bin2hex($droitId->toBinary()),
                ],
            );
            // CA-7 — mirage en mémoire par RECHARGEMENT depuis la base (`refresh()`), pas par un calcul
            // relatif `ancienne_valeur_en_mémoire + $credits`. Si `$droit` était déjà chargé dans la map
            // d'identité AVANT une écriture concurrente (ex. une autre recharge déjà committée), la
            // valeur PHP en mémoire est périmée à cet instant — un calcul arithmétique dessus produirait
            // un total localement plausible mais faux, et le `flush()` ci-dessous émettrait alors sa
            // PROPRE `UPDATE credit_restant = <valeur fausse>` qui écraserait le résultat pourtant
            // correct de l'`UPDATE` conditionnel ci-dessus (Doctrine ne connaît rien du SQL brut : son
            // suivi de changements ne compare que l'instantané qu'il a lui-même pris à l'objet en
            // mémoire). `refresh()` recharge `$droit` — donc `creditRestant` ET `fenetreFin` — depuis la
            // base (lecture de ses propres écritures, cohérente dans la transaction en cours) et
            // réinitialise l'instantané Doctrine : le `flush()` ne trouve alors plus rien à ré-écrire
            // sur ces deux colonnes.
            $this->em->refresh($droit);

            // Version du snapshot de TOUS les supports du droit, pas seulement de celui qu'on
            // recharge : la même carte peut être portée par un badge (`SnapshotVersionBumper`).
            $this->bumper->bumpPairedSupports($droitId);

            $this->em->flush();
        });

        // D7-bis — construit et RETOURNE l'événement au lieu de le publier ici (voir docblock de
        // classe) : cette méthode s'exécute imbriquée dans la transaction externe de
        // `ValiderVenteService::valider()`, qui ne le publiera qu'après son propre commit réel.
        $acteur = $this->security->getUser();

        return new DomainEvent(
            'access.card_recharged',
            // D6 — tenant dérivé du SUJET (l'établissement du droit), jamais de ContexteEtablissement.
            new EventTenant($droit->getEtablissement()->getId()),
            new EventSubject('DroitAcces', (string) $droit->getId()),
            [
                'droitId' => (string) $droit->getId(),
                'supportId' => (string) $accesSupport->getId(),
                'creditsAdded' => $credits,
                'creditBalanceAfter' => $droit->getCreditRestant(),
                'newExpiryAt' => $nouvelleEcheance?->format(\DATE_ATOM),
                'saleId' => (string) $support->getVente()?->getId(),
            ],
            $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
        );
    }

    private function carteVendue(BilletSupport $support): ?CarteMultiEntrees
    {
        $ligne = $support->getLigne();
        if ($ligne === null) {
            return null;
        }
        $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());

        return $produit instanceof Produit ? $produit->getCarte() : null;
    }
}
