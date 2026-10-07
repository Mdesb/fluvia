<?php

declare(strict_types=1);

namespace App\Acces\EventListener;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Service\VersionSnapshotSequencer;
use App\Organisation\Entity\Etablissement;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Uid\Uuid;

/**
 * Toute écriture ORM qui change ce qu'une borne doit décider fait avancer la version du snapshot.
 *
 * ⚠ POURQUOI UN ÉCOUTEUR ET PAS UN APPEL PAR CHEMIN. Les bornes en mode dégradé se synchronisent par
 * `GET /terminal/snapshot?depuis=V`, qui ne sert que les supports dont `versionMaj > V`. Jusqu'au
 * 04/10, faire avancer cette version était une politesse que chaque chemin devait se rappeler : sept
 * l'avaient oubliée (suspension pour impayé, pause fitness, réservation annulée, fin de créneau d'un
 * badge, contrôle manuel...). La base disait « fermé », la borne synchronisée en delta ouvrait
 * toujours, jusqu'au prochain snapshot complet. Un huitième chemin écrit demain l'oublierait aussi ;
 * posé ici, au point où Doctrine écrit, il n'a rien à se rappeler.
 *
 * Ce qui déclenche :
 *  - un champ PROJETÉ de `DroitAcces` (ce que le snapshot transmet ou ce que la porte décide :
 *    statut, fenêtre, crédit, type, sous-réseau, marges, billet, établissement) ou sa collection de
 *    zones autorisées (D87) → tous les supports de ses appairages actifs ;
 *  - un appairage créé, activé/désactivé, déplacé ou supprimé → son support (et l'ancien) ;
 *  - un support bloqué/débloqué, ré-identifié → lui-même ;
 *  - la TOPOLOGIE : une porte créée, supprimée ou déplacée, un contrôleur qui change d'espace,
 *    d'ITBOX ou d'espaces desservis, un espace qui change de sous-réseau → TOUS les supports à
 *    appairage actif de l'établissement. `portesEligibles` se calcule porte par porte ; le delta
 *    ne le recalcule que pour les supports dont la version avance.
 *
 * Ce qui ne déclenche PAS : le battement de cœur et l'état d'un contrôleur, les marges et le sens
 * d'une porte, les seuils d'un espace (écrits en permanence ou absents du snapshot : chacun ferait
 * de chaque delta un snapshot complet) ; `synchroniseLe`, les références de reprise, `versionMaj` lui-même (sans
 * quoi l'écouteur se relancerait sur sa propre écriture), et une réécriture À L'IDENTIQUE — un
 * recalcul planifié qui repose la même fenêtre toutes les cinq minutes avec une nouvelle instance de
 * date n'est pas une modification. La comparaison porte donc sur la valeur ÉCRITE en base, pas sur
 * l'identité de l'objet PHP.
 *
 * Le même passage date `updatedAt` du droit et des supports touchés (UTC) : c'est la date que l'API
 * partenaire exposera en `updatedSince`, et elle n'est fiable que si elle avance exactement quand la
 * version avance.
 *
 * ⚠ LES UPDATE SQL DIRECTS (`credit_restant`, `fenetre_fin`) NE PASSENT PAS PAR ICI. Ils rechargent le
 * droit par `refresh()` après leur UPDATE — sans quoi le flush réécrirait une valeur absolue
 * périmée et effacerait une écriture concurrente — et Doctrine n'y voit alors plus aucun changement.
 * Ils font avancer la version par `SnapshotVersionBumper`, dans la même transaction. Un chemin qui
 * recopierait la valeur sur l'objet au lieu de recharger repasserait ici (second tirage de
 * séquence) ET écraserait la concurrence : c'est le défaut corrigé le 06/10 dans `ValidationPassageHandler`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AccessProjectionVersionListener
{
    /** Champs de `DroitAcces` qui changent la décision d'une porte ou le contenu du snapshot. */
    private const RIGHT_FIELDS = [
        'sourceType', 'billetSupportRef', 'fenetreDebut', 'fenetreFin', 'creditRestant',
        'margeAvanceDefaut', 'margeRetardDefaut', 'sousReseau', 'statutProjection', 'etablissement',
    ];

    private const SUPPORT_FIELDS = ['identifiant', 'type', 'statut', 'etablissement'];

    private const PAIRING_FIELDS = ['actif', 'droit', 'support'];

    /** Champs de topologie dont dépend `portesEligibles` (voir `SnapshotTerminalProvider::ouvreLaPorte()`). */
    private const TOPOLOGY_FIELDS = [
        Controleur::class => ['espace', 'itboxRef', 'etablissement'],
        Equipement::class => ['controleur', 'etablissement'],
        EspaceAcces::class => ['sousReseau', 'espaceSocle', 'etablissement'],
    ];

    public function __construct(
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, DroitAcces> $rights */
        $rights = [];
        /** @var array<int, Support> $supports */
        $supports = [];
        $addSupport = static function (?Support $support) use (&$supports): void {
            if ($support instanceof Support) {
                $supports[spl_object_id($support)] = $support;
            }
        };

        /** @var array<string, string> $establishments hex de l'établissement => hex */
        $establishments = [];
        $addEstablishment = static function (?Etablissement $etablissement) use (&$establishments): void {
            if ($etablissement instanceof Etablissement) {
                $hex = bin2hex($etablissement->getId()->toBinary());
                $establishments[$hex] = $hex;
            }
        };

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Appairage) {
                $addSupport($entity->getSupport());
            } elseif ($this->isTopology($entity)) {
                $addEstablishment($this->topologyEstablishment($entity));
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            if ($entity instanceof DroitAcces && $this->changed($changeSet, self::RIGHT_FIELDS)) {
                $rights[spl_object_id($entity)] = $entity;
            } elseif ($entity instanceof Support && $this->changed($changeSet, self::SUPPORT_FIELDS)) {
                $addSupport($entity);
            } elseif ($entity instanceof Appairage && $this->changed($changeSet, self::PAIRING_FIELDS)) {
                $addSupport($entity->getSupport());
                // Un appairage déplacé d'un support à l'autre : l'ancien perd son droit.
                $ancien = $changeSet['support'][0] ?? null;
                $addSupport($ancien instanceof Support ? $ancien : null);
            } elseif ($this->isTopology($entity) && $this->changed($changeSet, self::TOPOLOGY_FIELDS[$entity::class] ?? self::TOPOLOGY_FIELDS[get_parent_class($entity) ?: ''] ?? [])) {
                $addEstablishment($this->topologyEstablishment($entity));
                // L'établissement QUITTÉ perd aussi une porte (équipement ou contrôleur déplacé).
                foreach (['etablissement', 'controleur', 'espace', 'espaceSocle'] as $field) {
                    $ancien = $changeSet[$field][0] ?? null;
                    $addEstablishment($ancien instanceof Etablissement ? $ancien : (\is_object($ancien) && method_exists($ancien, 'getEtablissement') ? $ancien->getEtablissement() : null));
                }
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Appairage && $entity->isActif()) {
                $addSupport($entity->getSupport());
            } elseif ($this->isTopology($entity)) {
                $addEstablishment($this->topologyEstablishment($entity));
            }
        }

        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection instanceof PersistentCollection ? $collection->getOwner() : null;
            if ($owner instanceof DroitAcces && $collection->getMapping()->fieldName === 'authorisedSpaces') {
                $rights[spl_object_id($owner)] = $owner;
            } elseif ($owner instanceof Controleur && $collection->getMapping()->fieldName === 'servedSpaces') {
                $addEstablishment($this->topologyEstablishment($owner));
            }
        }

        if ($rights === [] && $supports === [] && $establishments === []) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rightMeta = $em->getClassMetadata(DroitAcces::class);
        foreach ($rights as $right) {
            if ($uow->isScheduledForDelete($right)) {
                continue;
            }
            $right->setUpdatedAt($now);
            $uow->recomputeSingleEntityChangeSet($rightMeta, $right);
            if (!$uow->isScheduledForInsert($right)) {
                foreach ($this->activePairings($em, $right) as $appairage) {
                    $addSupport($appairage->getSupport());
                }
            }
        }

        $version = null;
        if ($establishments !== []) {
            $version = $this->sequencer->suivant();
            $this->bumpEstablishments($em, array_values($establishments), $version, $now);
        }
        $supportMeta = $em->getClassMetadata(Support::class);
        foreach ($supports as $support) {
            if ($uow->isScheduledForDelete($support) || $uow->getEntityState($support) !== UnitOfWork::STATE_MANAGED) {
                continue;
            }
            // Une seule valeur pour tout le flush : la séquence est monotone d'un flush à l'autre, et
            // la pagination du snapshot départage les égalités par l'id.
            $version ??= $this->sequencer->suivant();
            $support->setVersionMaj($version)->setUpdatedAt($now);
            $uow->recomputeSingleEntityChangeSet($supportMeta, $support);
        }
    }

    private function isTopology(object $entity): bool
    {
        return $entity instanceof Controleur || $entity instanceof Equipement || $entity instanceof EspaceAcces;
    }

    /** L'établissement d'un objet de topologie, en remontant la chaîne si le champ dénormalisé manque. */
    private function topologyEstablishment(object $entity): ?Etablissement
    {
        return match (true) {
            $entity instanceof Equipement => $entity->getEtablissement() ?? $entity->getControleur()?->getEtablissement(),
            $entity instanceof Controleur => $entity->getEtablissement() ?? $entity->getEspace()?->getEtablissement(),
            $entity instanceof EspaceAcces => $entity->getEtablissement() ?? $entity->getEspaceSocle()?->getEtablissement(),
            default => null,
        };
    }

    /**
     * Avance tous les supports à appairage actif des établissements touchés, en SQL : ils peuvent
     * être des milliers, les hydrater pour un `recompute` coûterait plus que la modification.
     *
     * ⚠ `onFlush` précède l'ouverture de la transaction du flush : si celui-ci échoue ensuite, les
     * supports auront avancé pour rien. C'est le sens sûr — un support servi deux fois au delta ne
     * coûte rien, une porte retirée qui n'y arrive pas laisse entrer.
     *
     * @param list<string> $establishments identifiants en hexadécimal
     */
    private function bumpEstablishments(EntityManagerInterface $em, array $establishments, int $version, \DateTimeImmutable $now): void
    {
        $connection = $em->getConnection();
        $touches = [];
        foreach ($establishments as $hex) {
            foreach ($connection->fetchFirstColumn(
                'SELECT LOWER(HEX(s.id)) FROM acces_support s JOIN acces_appairage a ON a.support_id = s.id AND a.actif = 1 WHERE s.etablissement_id = UNHEX(:hex)',
                ['hex' => $hex],
            ) as $id) {
                $touches[$id] = true;
            }
            $connection->executeStatement(
                'UPDATE acces_support s JOIN acces_appairage a ON a.support_id = s.id AND a.actif = 1 '
                . 'SET s.version_maj = :v, s.updated_at = :at WHERE s.etablissement_id = UNHEX(:hex)',
                ['v' => $version, 'at' => $now->format('Y-m-d H:i:s'), 'hex' => $hex],
            );
        }

        // Mirage des supports déjà chargés (valeur ET instantané), comme `SnapshotVersionBumper`.
        $uow = $em->getUnitOfWork();
        foreach ($uow->getIdentityMap()[Support::class] ?? [] as $support) {
            if ($support instanceof Support && isset($touches[bin2hex($support->getId()->toBinary())])) {
                $support->setVersionMaj($version)->setUpdatedAt($now);
                $uow->setOriginalEntityProperty(spl_object_id($support), 'versionMaj', $version);
                $uow->setOriginalEntityProperty(spl_object_id($support), 'updatedAt', $now);
            }
        }
    }

    /**
     * Les appairages actifs EN BASE. Un appairage désactivé dans ce même flush y figure encore : son
     * support reçoit la version, ce qui est voulu (il passe en tombstone).
     *
     * @return list<Appairage>
     */
    private function activePairings(EntityManagerInterface $em, DroitAcces $right): array
    {
        return $em->getRepository(Appairage::class)->findBy(['droit' => $right, 'actif' => true]);
    }

    /**
     * Un champ a-t-il changé de VALEUR ÉCRITE ?
     *
     * Doctrine signale une date dès que l'instance change, même pour le même instant. On compare
     * donc ce que la colonne recevra : `Y-m-d H:i:s` dans le fuseau de l'objet (c'est ce que le type
     * `datetime_immutable` écrit), l'identifiant pour une relation ou un `Uuid`.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param list<string>                             $fields
     */
    private function changed(array $changeSet, array $fields): bool
    {
        foreach ($fields as $field) {
            if (isset($changeSet[$field]) && $this->stored($changeSet[$field][0]) !== $this->stored($changeSet[$field][1])) {
                return true;
            }
        }

        return false;
    }

    private function stored(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value) && method_exists($value, 'getId')) {
            $id = $value->getId();

            return $id instanceof Uuid ? $id->toRfc4122() : $id;
        }

        return $value;
    }
}
