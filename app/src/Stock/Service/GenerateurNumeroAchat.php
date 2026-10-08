<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\CommandeAchat;
use Doctrine\ORM\EntityManagerInterface;

/** Numéro de commande d'achat lisible et traçable (même patron que `App\Vente\Service\GenerateurNumero`). */
final class GenerateurNumeroAchat
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * ⚠ LE COMPTE ÉTAIT GLOBAL : toutes les commandes de la plateforme, brouillons compris. Chaque
     * client voyait des trous dans sa série, et le volume d'achat d'un autre dans son numéro. La série
     * suit l'établissement de la commande, seul rattachement qu'elle porte (le module Stock ne lit pas
     * les profils de Compta, D2) ; le préfixe de l'établissement garde le numéro unique sur la
     * plateforme (`uniq_commande_achat_numero`). Seules les commandes déjà numérotées comptent.
     */
    public function genererPourCommande(CommandeAchat $commande): string
    {
        $etablissement = $commande->getEtablissement();
        \assert($etablissement !== null);

        $nb = (int) $this->em->getRepository(CommandeAchat::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.etablissement = :etablissement')
            ->andWhere("c.numero <> ''")
            ->setParameter('etablissement', $etablissement->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('CA-%s-%05d', substr(strtoupper($etablissement->getId()->toRfc4122()), 0, 8), $nb + 1);
    }
}
