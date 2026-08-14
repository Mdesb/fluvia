<?php

declare(strict_types=1);

namespace App\Audit\Service;

use App\Audit\Entity\EntreeAudit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Fabrique et persiste des entrées d'audit (RG-SOCLE-07). Ne flush pas : l'appelant décide.
 */
final class JournalAudit
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function enregistrer(
        string $action,
        string $cibleType,
        ?string $cibleId,
        ?Uuid $etablissement = null,
        ?string $auteur = null,
    ): EntreeAudit {
        $entree = new EntreeAudit();
        $entree->setAction($action);
        $entree->setCibleType($cibleType);
        $entree->setCibleId($cibleId);
        $entree->setEtablissement($etablissement);
        $entree->setAuteur($auteur ?? $this->auteurCourant());

        $this->em->persist($entree);

        return $entree;
    }

    private function auteurCourant(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
