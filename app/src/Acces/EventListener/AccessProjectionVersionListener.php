<?php

declare(strict_types=1);

namespace App\Acces\EventListener;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Service\VersionSnapshotSequencer;
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
 *  - un support bloqué/débloqué, ré-identifié → lui-même.
 *
 * Ce qui ne déclenche PAS : `synchroniseLe`, les références de reprise, `versionMaj` lui-même (sans
 * quoi l'écouteur se relancerait sur sa propre écriture), et une réécriture À L'IDENTIQUE — un
 * recalcul planifié qui repose la même fenêtre toutes les cinq minutes avec une nouvelle instance de
 * date n'est pas une modification. La comparaison porte donc sur la valeur ÉCRITE en base, pas sur
 * l'identité de l'objet PHP.
 *
 * Le même passage date `updatedAt` du droit et des supports touchés (UTC) : c'est la date que l'API
 * partenaire exposera en `updatedSince`, et elle n'est fiable que si elle avance exactement quand la
 * version avance.
 *
 * ⚠ HORS DE PORTÉE : les UPDATE SQL directs (`credit_restant`, `fenetre_fin`) — Doctrine ne les voit
 * pas. Ils passent par `SnapshotVersionBumper`, dans la même transaction.
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

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Appairage) {
                $addSupport($entity->getSupport());
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
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Appairage && $entity->isActif()) {
                $addSupport($entity->getSupport());
            }
        }

        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection instanceof PersistentCollection ? $collection->getOwner() : null;
            if ($owner instanceof DroitAcces && $collection->getMapping()->fieldName === 'authorisedSpaces') {
                $rights[spl_object_id($owner)] = $owner;
            }
        }

        if ($rights === [] && $supports === []) {
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
