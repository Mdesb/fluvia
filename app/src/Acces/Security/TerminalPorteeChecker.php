<?php

declare(strict_types=1);

namespace App\Acces\Security;

use App\Acces\Entity\Equipement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle de portée applicatif d'un `Terminal` (plan-acces-terminal.md §3.2) : `equipementId` doit
 * appartenir au même `itboxRef`/établissement que le `Terminal` authentifié. **Pas** un voter (portée
 * dynamique dépendante du corps de requête, pas d'un sujet statique `module.action`) : appelé
 * explicitement par `TerminalPassageProcessor`/`TerminalSynchroProcessor` **avant** tout appel au
 * moteur — 403 sans fuite d'info sur un équipement hors périmètre (§4.1/§4.2 spec).
 */
final class TerminalPorteeChecker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function verifierEquipement(TerminalUtilisateur $terminalUtilisateur, Uuid $equipementId): Equipement
    {
        $terminal = $terminalUtilisateur->terminal;
        $equipement = $this->em->getRepository(Equipement::class)->find($equipementId);
        $controleur = $equipement?->getControleur();

        if (
            !$equipement instanceof Equipement
            || $controleur === null
            || $controleur->getItboxRef() !== $terminal->getItboxRef()
            || $controleur->getEtablissement() === null
            || $terminal->getEtablissement() === null
            || !$controleur->getEtablissement()->getId()->equals($terminal->getEtablissement()->getId())
        ) {
            throw new AccessDeniedHttpException('Équipement hors de la portée du terminal.');
        }

        return $equipement;
    }

    /**
     * Filtre de portée par lot (§2.4 du plan) : une requête batch résout `equipementId → itboxRef`
     * pour tout le lot en une fois. Retourne l'ensemble des identifiants d'équipement (chaînes) dans
     * la portée du terminal.
     *
     * @return array<string, Equipement>
     */
    public function equipementsDansPortee(TerminalUtilisateur $terminalUtilisateur): array
    {
        $terminal = $terminalUtilisateur->terminal;
        if ($terminal->getEtablissement() === null) {
            return [];
        }

        /** @var list<Equipement> $equipements */
        $equipements = $this->em->createQueryBuilder()
            ->select('eq')
            ->from(Equipement::class, 'eq')
            ->innerJoin('eq.controleur', 'c')
            ->andWhere('c.itboxRef = :itbox')
            ->andWhere('IDENTITY(c.etablissement) = :etab')
            ->setParameter('itbox', $terminal->getItboxRef())
            ->setParameter('etab', $terminal->getEtablissement()->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $index = [];
        foreach ($equipements as $equipement) {
            $index[(string) $equipement->getId()] = $equipement;
        }

        return $index;
    }
}
