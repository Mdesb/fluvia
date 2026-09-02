<?php

declare(strict_types=1);

namespace App\Compta\Repository;

use App\Compta\Entity\LegalVatRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LegalVatRate>
 */
class LegalVatRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalVatRate::class);
    }

    /**
     * Les taux en vigueur d'un pays a une date donnee.
     *
     * ⚠ LA DATE EST UN PARAMETRE, PAS « MAINTENANT ». Expliquer une facture de 2025 demande les taux
     * de 2025 ; un referentiel qui ne saurait rendre que l'etat du jour serait faux sur tout le passe
     * des le premier decret, et personne ne s'en apercevrait puisque les montants, eux, sont figes.
     *
     * @return list<LegalVatRate>
     */
    public function inForce(string $country, \DateTimeImmutable $on, string $territory = ''): array
    {
        /** @var list<LegalVatRate> $lignes */
        $lignes = $this->createQueryBuilder('t')
            ->andWhere('t.country = :pays')->setParameter('pays', strtoupper($country))
            ->andWhere('t.territory IN (:territoires)')
            ->setParameter('territoires', array_unique(['', strtoupper(trim($territory))]))
            ->andWhere('t.validFrom <= :date')->setParameter('date', $on)
            ->andWhere('t.validUntil IS NULL OR t.validUntil >= :date')
            ->orderBy('t.rate', 'DESC')
            ->getQuery()
            ->getResult();

        return self::baremeComplet($lignes, strtoupper(trim($territory)));
    }

    /**
     * UN TERRITOIRE QUI DECLARE DES TAUX DECLARE SON BAREME COMPLET — il ne complete pas le pays.
     *
     * ⚠ J'AI ECRIT L'AUTRE REGLE D'ABORD, ET LE PREMIER CAS REEL L'A DEMENTIE.
     *
     * Ma premiere version fusionnait categorie par categorie : le territoire ecrasait ce qu'il
     * redefinit, le droit commun tenait le reste. Ca paraissait plus fin. Applique aux DOM, ou la
     * TVA ne connait que DEUX taux — 8,5 % et 2,1 % (CGI art. 296) — ca faisait apparaitre le
     * 5,5 % metropolitain dans un catalogue guadeloupeen, sous le libelle « produits alimentaires,
     * livres ». Un taux qui n'existe pas la-bas, propose a la saisie, dans une liste qui a l'air
     * complete.
     *
     * La regle retenue est donc entiere : si le territoire demande porte au moins un taux, on ne
     * rend QUE les siens. Ce que ca coute est reel et assume — un territoire dont on ne saisit que
     * les particularites rendra un catalogue incomplet, et il faudra y semer aussi les taux qu'il
     * partage avec la metropole. Mais un catalogue incomplet se voit, la ou un taux etranger glisse
     * sans bruit jusqu'a une facture.
     *
     * @param list<LegalVatRate> $lignes
     *
     * @return list<LegalVatRate>
     */
    private static function baremeComplet(array $lignes, string $territory): array
    {
        $duTerritoire = $territory === '' ? [] : array_values(array_filter(
            $lignes,
            static fn (LegalVatRate $t): bool => $t->getTerritory() === $territory,
        ));

        $retenus = $duTerritoire !== [] ? $duTerritoire : array_values(array_filter(
            $lignes,
            static fn (LegalVatRate $t): bool => $t->getTerritory() === '',
        ));

        usort($retenus, static fn (LegalVatRate $a, LegalVatRate $b): int => (float) $b->getRate() <=> (float) $a->getRate());

        return $retenus;
    }
}
