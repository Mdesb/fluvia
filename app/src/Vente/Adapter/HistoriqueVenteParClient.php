<?php

declare(strict_types=1);

namespace App\Vente\Adapter;

use App\Vente\Entity\Vente;
use App\Vente\Port\HistoriqueVenteInterface;
use App\Vente\Port\ResumeVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation réelle du port `HistoriqueVenteInterface` (§2.4 plan-crm.md, CA-3/CA-4/CA-5) :
 * une vente est retournée pour un client dès qu'il en est le payeur (`Vente.client`) ou le
 * bénéficiaire d'au moins une ligne (`LigneVente.beneficiaire`) — CA-5 : l'abonnement réglé par le
 * payeur apparaît dans l'historique du bénéficiaire, distinctement du rôle payeur.
 */
final class HistoriqueVenteParClient implements HistoriqueVenteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function pourClients(array $clientIds): iterable
    {
        if ($clientIds === []) {
            return [];
        }

        $idsStr = array_map(static fn (Uuid $u): string => (string) $u, $clientIds);

        // Doctrine ne sait pas convertir un tableau d'UUID en une seule liaison de paramètre `IN()`
        // typée (le type `uuid` s'applique à une valeur scalaire, pas à un tableau) : chaque
        // identifiant est donc lié individuellement avec son propre paramètre nommé et typé.
        $qb = $this->em->createQueryBuilder();
        $qb->select('v')
            ->from(Vente::class, 'v')
            ->leftJoin('v.lignes', 'l');

        $ouClient = $qb->expr()->orX();
        $ouBeneficiaire = $qb->expr()->orX();
        foreach ($clientIds as $i => $uuid) {
            $param = 'cid' . $i;
            $ouClient->add($qb->expr()->eq('v.client', ':' . $param));
            $ouBeneficiaire->add($qb->expr()->eq('l.beneficiaire', ':' . $param));
            $qb->setParameter($param, $uuid, UuidType::NAME);
        }

        $qb->where($qb->expr()->orX($ouClient, $ouBeneficiaire))->distinct();

        /** @var list<Vente> $ventes */
        $ventes = $qb->getQuery()->getResult();

        $resumes = [];
        foreach ($ventes as $vente) {
            $roles = [];
            $client = $vente->getClient();
            if ($client !== null && \in_array((string) $client, $idsStr, true)) {
                $roles[(string) $client][] = 'payeur';
            }
            foreach ($vente->getLignes() as $ligne) {
                $beneficiaire = $ligne->getBeneficiaire();
                if ($beneficiaire !== null && \in_array((string) $beneficiaire, $idsStr, true)) {
                    $roles[(string) $beneficiaire][] = 'beneficiaire';
                }
            }
            foreach ($roles as $cid => $r) {
                $roles[$cid] = array_values(array_unique($r));
            }

            $resumes[] = new ResumeVente(
                $vente->getId(),
                $vente->getNumero(),
                $vente->getDate(),
                $vente->getTotal(),
                $roles,
            );
        }

        return $resumes;
    }
}
