<?php

declare(strict_types=1);

namespace App\Subscription\Adapter;

use App\Securite\Entity\Utilisateur;
use App\Securite\Port\SupportAccessScopeInterface;
use App\Subscription\Entity\SupportAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Les établissements qu'un accès d'assistance ouvre à cet agent, à cet instant.
 *
 * ── ⚠ LA RÈGLE N'EST PAS RÉÉCRITE EN SQL, ET C'EST LE POINT DE CETTE CLASSE ────────────────────
 *
 * On pourrait filtrer en base : `revoked_at IS NULL OR revoked_at > :at`, `granted_at <= :at`,
 * `expires_at > :at`. Ce serait une **seconde implémentation** de `SupportAccess::isUsableAt()` —
 * dans un autre langage, sans test propre, et qui divergerait le jour où la règle bougerait. La
 * première version resterait verte, la seconde mentirait, et rien ne relierait les deux.
 *
 * On charge donc les accès de l'agent et on interroge l'entité elle-même. Le volume le permet :
 * un accès d'assistance est **exceptionnel par construction** — nominatif, motivé, borné dans le
 * temps. S'il en existe assez pour que cette lecture pèse, c'est une information sur l'exploitation
 * avant d'être un problème de performance.
 *
 * ⚠ ON FILTRE SUR `grantee` EN BASE, PAS SUR TOUT. La borne qui compte pour le volume est celle-là :
 * ce qu'on charge est l'historique d'assistance d'UNE personne, pas celui de la plateforme.
 *
 * ── CE QU'ON REND ──────────────────────────────────────────────────────────────────────────────
 *
 * Des identifiants, pas des entités. L'appelant est une extension Doctrine qui construit une
 * clause `IN` : lui rendre des objets l'obligerait à les rouvrir pour en extraire l'identifiant, et
 * hydrater des établissements pour n'en lire que la clé est du travail perdu à chaque liste.
 */
final class SupportAccessScope implements SupportAccessScopeInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Uuid>
     */
    public function reachableEstablishmentIds(Utilisateur $agent, \DateTimeImmutable $at): array
    {
        /** @var list<SupportAccess> $accesses */
        $accesses = $this->em->getRepository(SupportAccess::class)->findBy(['grantee' => $agent]);

        $ids = [];
        foreach ($accesses as $access) {
            if (!$access->isUsableAt($at)) {
                continue;
            }

            $etablissement = $access->getEstablishment();
            if (null === $etablissement) {
                continue;
            }

            // Dédoublonné par identifiant : deux accès successifs sur le même établissement — un
            // premier ticket, puis un second — sont deux lignes et un seul établissement.
            $ids[$etablissement->getId()->toRfc4122()] = $etablissement->getId();
        }

        return array_values($ids);
    }
}
