<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère les numéros séquentiels lisibles (session par point de vente, vente par session, avoir).
 * Les numéros restent lisibles/traçables ; l'inaltérabilité stricte est portée par la chaîne NF525.
 */
final class GenerateurNumero
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function numeroSession(PointDeVente $pdv): string
    {
        $nb = (int) $this->em->getRepository(SessionCaisse::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.pointDeVente = :pdv')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('S-%s-%05d', substr(strtoupper($pdv->getId()->toRfc4122()), 0, 8), $nb + 1);
    }

    public function numeroVente(SessionCaisse $session): string
    {
        $nb = (int) $this->em->getRepository(Vente::class)
            ->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.session = :session')
            ->setParameter('session', $session->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s-T%05d', $session->getNumero(), $nb + 1);
    }

    /**
     * Numéro d'une vente directe (D44-bis) : elle n'a pas de session dont hériter, donc on compte par
     * point de vente. Le `D` la distingue à l'œil d'une vente de guichet — utile en relecture de
     * chaîne, où l'on veut savoir tout de suite si un tiroir était censé être impliqué.
     */
    public function numeroVenteDirecte(PointDeVente $pdv): string
    {
        $nb = (int) $this->em->getRepository(Vente::class)
            ->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.pointDeVente = :pdv')
            ->andWhere('v.session IS NULL')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('D-%s-%05d', substr(strtoupper($pdv->getId()->toRfc4122()), 0, 8), $nb + 1);
    }

    /**
     * Numéro d'un avoir de caisse : sa série est celle du point de vente de la vente d'origine.
     *
     * ⚠ LE COMPTE ÉTAIT GLOBAL : tous les avoirs de la plateforme, tous clients confondus. Chaque point
     * de vente voyait des trous dans sa série, et le volume d'un client se lisait dans le numéro d'un
     * autre. La série suit désormais le point de vente, comme la chaîne NF525 (`uniq_op_pdv_sequence`)
     * et les numéros de session et de vente directe ; le préfixe du point de vente garde le numéro
     * unique sur la plateforme (`uniq_avoir_numero`). Les avoirs d'avant (`AV-<date>-…`) comptent dans
     * la série de leur point de vente : elle continue, elle ne repart pas à 1.
     */
    public function numeroAvoir(PointDeVente $pdv): string
    {
        $nb = (int) $this->em->getRepository(Avoir::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.venteOrigine', 'v')
            ->andWhere('v.pointDeVente = :pdv')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('AV-%s-%05d', substr(strtoupper($pdv->getId()->toRfc4122()), 0, 8), $nb + 1);
    }
}
