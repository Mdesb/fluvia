<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use App\Platform\Nf525\SealKeyRunQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestre le scellement d'une opération dans la même transaction que sa validation (§2 du plan).
 * Récupère le dernier maillon de la chaîne du point de vente, délègue le calcul au SignataireOperation
 * (enfichable) et persiste le nouveau maillon. Le verrou d'unicité (pointDeVente, numeroSequence)
 * sérialise l'attribution de séquence (pas de trou, pas de doublon).
 */
final class ScellementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SignataireOperation $signataire,
        // #369 — le trousseau de la chaîne de vente (le même que celui du signataire) : le refus de sceller
        // lit l'ordre des clés ici, car `HashChainSignataire` n'a pas d'accès à la base.
        #[Autowire(service: 'app.nf525.keyring.sales')]
        private readonly SealKeyring $keyring,
        // #370 — l'alerte NF525 ; null : aucun accès à la table d'alerte (constructions manuelles des tests).
        private readonly ?SealAlertRecorder $alerts = null,
    ) {
    }

    /**
     * Scelle l'opération et persiste le maillon (sans flush : la transaction est portée par l'appelant).
     */
    public function sceller(OperationAScellerDto $op): OperationScellee
    {
        $chain = SealChainRef::forSales($op->pointDeVente);
        try {
            $precedente = $this->dernierMaillon($op->pointDeVente);

            // La signature du dernier maillon se juge AVANT l'ordre des clés (même ordre que compta et facturation) ;
            // le signataire la rejuge ensuite, sans effet de bord.
            if ($precedente !== null) {
                $this->keyring->assertCanExtend(
                    $precedente->getEmpreinte(),
                    $precedente->getSignature(),
                    $precedente->getSealKeyId(),
                    sprintf('chaîne de vente du point de vente %s, maillon n° %d', (string) $op->pointDeVente->getId(), $precedente->getNumeroSequence()),
                );
            }

            // #369 — ÉCHEC FERMÉ SUR L'ORDRE DES CLÉS : une chaîne qui a quitté la clé active, ou dont le dernier
            // maillon est en régression, ne se prolonge pas. Les intervalles sont lus sur la même connexion (donc la
            // même transaction que l'appelant) que `dernierMaillon`, sans verrou de plus : comme lui, la lecture est
            // protégée à l'écriture par l'unicité (point de vente, séquence). Tout maillon lu, séquence 0 comprise.
            $this->keyring->assertCanExtendInOrderFrom(
                fn (): array => SealKeyRunQuery::forSales($this->em->getConnection(), $this->keyring, (string) $op->pointDeVente->getId()),
                sprintf('chaîne de vente du point de vente %s', (string) $op->pointDeVente->getId()),
            );

            // `scelle` rejuge les gardes : son refus remonte dans le même `try`.
            $operation = $this->signataire->scelle($op, $precedente);
        } catch (SealingRefusedException $e) {
            throw $this->alerts?->recordRefusal($chain, $e) ?? $e;
        }
        $this->alerts?->resolveOnSeal($chain);
        $this->em->persist($operation);

        return $operation;
    }

    public function dernierMaillon(PointDeVente $pointDeVente): ?OperationScellee
    {
        return $this->em->getRepository(OperationScellee::class)
            ->createQueryBuilder('o')
            ->andWhere('o.pointDeVente = :pdv')
            ->setParameter('pdv', $pointDeVente->getId(), 'uuid')
            ->orderBy('o.numeroSequence', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Chaîne complète d'un point de vente, ordonnée par séquence (pour verifieChaine).
     *
     * @return list<OperationScellee>
     */
    public function chaine(PointDeVente $pointDeVente): array
    {
        /** @var list<OperationScellee> $ops */
        $ops = $this->em->getRepository(OperationScellee::class)
            ->createQueryBuilder('o')
            ->andWhere('o.pointDeVente = :pdv')
            ->setParameter('pdv', $pointDeVente->getId(), 'uuid')
            ->orderBy('o.numeroSequence', 'ASC')
            ->getQuery()
            ->getResult();

        return $ops;
    }
}
