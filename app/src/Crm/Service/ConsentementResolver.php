<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * État courant d'un consentement par canal (RG-M4-07, §3.1 plan-crm.md) : la ligne `Consentement` la
 * plus récente pour (client, canal) — résolu par requête, jamais de colonne dénormalisée (évite
 * l'incohérence entre l'historique append-only et un état recopié).
 */
final class ConsentementResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function etatCourant(Client $client, CanalConsentement $canal): ?Consentement
    {
        return $this->em->getRepository(Consentement::class)->findOneBy(
            ['client' => $client, 'canal' => $canal],
            ['dateRecueil' => 'DESC'],
        );
    }

    /** CA-16 : un client sans consentement `accorde` valide sur le canal est exclu de tout envoi/export. */
    public function estExploitable(Client $client, CanalConsentement $canal): bool
    {
        $etat = $this->etatCourant($client, $canal);

        return $etat !== null && $etat->estExploitable();
    }
}
