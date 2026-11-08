<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutClient;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipStatus;
use App\Organisation\Entity\Groupe;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * QUELLES FICHES PEUVENT ÊTRE ANONYMISÉES — une seule définition, pour l'aperçu, l'épuration et la
 * purge à échéance (#344, #207).
 *
 * ── LE CRITÈRE : L'INACTIVITÉ, PAS L'ANCIENNETÉ ────────────────────────────────────────────────
 *
 * La CNIL fait courir la conservation des données clients « à compter de la fin de la relation
 * commerciale », par exemple d'un achat ou du dernier contact émanant du client (référentiel
 * « gestion des activités commerciales », délibération n° 2021-131 du 23/09/2021). La première
 * version de la tâche de conservation comptait depuis la CRÉATION de la fiche : elle aurait
 * anonymisé un nageur inscrit il y a quatre ans et venu hier.
 *
 * Une fiche est inactive depuis la date-seuil si elle a été créée avant ce seuil ET si sa dernière
 * visite (dernière vente validée, `dateDerniereVisite`) est absente ou antérieure au seuil.
 *
 * ── CE QUI PROTÈGE UNE FICHE, QUELLE QUE SOIT SON INACTIVITÉ ───────────────────────────────────
 *
 *  - un abonnement vivant (actif, en pause, impayé), qu'elle le paie ou qu'il soit à son nom :
 *    la relation contractuelle n'est pas finie, et un impayé est une créance à recouvrer ;
 *  - un solde de porte-monnaie strictement positif : c'est de l'argent qui lui appartient ;
 *  - une fiche déjà anonymisée ou fusionnée dans une autre (rien à effacer, ou la survivante porte
 *    l'identité).
 *
 * Ces protections ne se désactivent pas depuis l'écran. Une fiche protégée est comptée à part dans
 * l'aperçu, pour qu'un « 0 à épurer » ne passe pas pour « personne d'inactif ».
 *
 * ── LE PÉRIMÈTRE ───────────────────────────────────────────────────────────────────────────────
 *
 * Toutes les méthodes prennent le GROUPE en argument, et la requête le pose en premier. L'appelant
 * l'obtient de `CustomerGroupGuard::activeGroup()` (écran) ou de la règle de conservation (tâche) :
 * jamais d'une saisie.
 */
final class PurgeCandidateFinder
{
    /** Statuts d'abonnement qui maintiennent la relation contractuelle. */
    private const LIVE_MEMBERSHIP = [MembershipStatus::Actif, MembershipStatus::Pause, MembershipStatus::Impaye];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Fiches anonymisables, des plus anciennement actives aux plus récentes.
     *
     * @param list<mixed>|null $onlyIds restreint aux identifiants donnés (sélection de l'écran) ;
     *                                  un identifiant hors groupe ou protégé est simplement ignoré
     *
     * @return list<Client>
     */
    public function candidates(Groupe $groupe, \DateTimeImmutable $inactiveSince, ?\DateTimeImmutable $createdBefore, int $limit, ?array $onlyIds = null): array
    {
        if ($onlyIds !== null) {
            return $this->selected($groupe, $inactiveSince, $createdBefore, $limit, $onlyIds);
        }

        $qb = $this->inactive($groupe, $inactiveSince, $createdBefore);
        $this->excludeProtected($qb);

        /** @var list<Client> $clients */
        $clients = $qb->orderBy('c.dateDerniereVisite', 'ASC')
            ->addOrderBy('c.dateCreation', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $clients;
    }

    /**
     * La sélection de l'écran, re-vérifiée fiche par fiche contre les MÊMES critères.
     *
     * ⚠ UNE ÉGALITÉ PAR FICHE, PAS UN `IN (:liste)` : le dépôt refuse cette forme sur des UUID binaires
     * (garde-fou D58) — une conversion oubliée y rend une liste vide sans lever. La sélection est
     * plafonnée par l'appelant ; quelques centaines de requêtes indexées sur la clé primaire.
     *
     * @param list<mixed> $onlyIds
     *
     * @return list<Client>
     */
    private function selected(Groupe $groupe, \DateTimeImmutable $inactiveSince, ?\DateTimeImmutable $createdBefore, int $limit, array $onlyIds): array
    {
        $retenus = [];
        foreach (array_unique(array_filter($onlyIds, static fn (mixed $id): bool => \is_string($id) && Uuid::isValid($id))) as $id) {
            if (\count($retenus) >= $limit) {
                break;
            }
            $qb = $this->inactive($groupe, $inactiveSince, $createdBefore);
            $this->excludeProtected($qb);
            $client = $qb->andWhere('c.id = :selected')
                ->setParameter('selected', Uuid::fromString($id), 'uuid')
                ->getQuery()
                ->getOneOrNullResult();
            if ($client instanceof Client) {
                $retenus[] = $client;
            }
        }

        return $retenus;
    }

    /** Nombre de fiches anonymisables (protections déduites). */
    public function countEligible(Groupe $groupe, \DateTimeImmutable $inactiveSince, ?\DateTimeImmutable $createdBefore): int
    {
        $qb = $this->inactive($groupe, $inactiveSince, $createdBefore)->select('COUNT(c.id)');
        $this->excludeProtected($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Nombre de fiches inactives, protégées ou non. */
    public function countInactive(Groupe $groupe, \DateTimeImmutable $inactiveSince, ?\DateTimeImmutable $createdBefore): int
    {
        return (int) $this->inactive($groupe, $inactiveSince, $createdBefore)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function inactive(Groupe $groupe, \DateTimeImmutable $inactiveSince, ?\DateTimeImmutable $createdBefore): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Client::class, 'c')
            ->where('IDENTITY(c.groupe) = :groupe')
            ->andWhere('c.statut NOT IN (:closed)')
            ->andWhere('c.dateCreation <= :seuil')
            ->andWhere('(c.dateDerniereVisite IS NULL OR c.dateDerniereVisite <= :seuil)')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->setParameter('closed', [StatutClient::Anonymise->value, StatutClient::Fusionne->value])
            ->setParameter('seuil', $inactiveSince);
        if ($createdBefore !== null) {
            $qb->andWhere('c.dateCreation <= :createdBefore')->setParameter('createdBefore', $createdBefore);
        }

        return $qb;
    }

    private function excludeProtected(QueryBuilder $qb): void
    {
        $qb->andWhere('NOT EXISTS (SELECT m1.id FROM ' . Membership::class . ' m1 '
                . 'WHERE m1.payeur = c AND m1.statut IN (:live))')
            ->andWhere('NOT EXISTS (SELECT m2.id FROM ' . Membership::class . ' m2 '
                . 'INNER JOIN ' . Beneficiaire::class . ' b2 WITH b2 = m2.adherent '
                . 'WHERE b2.client = c AND m2.statut IN (:live))')
            ->andWhere('NOT EXISTS (SELECT p.id FROM ' . PorteMonnaieVirtuel::class . ' p '
                . 'WHERE p.client = c AND p.solde > 0)')
            ->setParameter('live', array_map(static fn (MembershipStatus $s): string => $s->value, self::LIVE_MEMBERSHIP));
    }
}
