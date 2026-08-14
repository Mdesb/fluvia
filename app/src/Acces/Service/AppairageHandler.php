<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeSupport;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Appairage Support ↔ DroitAcces (US-L3-02, A-02, CA-2). Un support déjà appairé actif ou bloqué est
 * refusé avec un message explicite ; un seul appairage actif par support (garanti en base par la
 * colonne dénormalisée `supportActif`, contrainte unique).
 */
final class AppairageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function appairer(
        string $identifiantSupport,
        TypeSupport $typeSupport,
        DroitAcces $droit,
        ModeAppairage $mode,
        Etablissement $etablissement,
        ?Utilisateur $agent = null,
    ): Appairage {
        $support = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $identifiantSupport]);
        if (!$support instanceof Support) {
            $support = new Support();
            $support->setIdentifiant($identifiantSupport)->setType($typeSupport)->setEtablissement($etablissement);
            $this->em->persist($support);
        }

        if ($support->getStatut() === StatutSupport::Bloque) {
            throw new ConflictHttpException('Support bloqué (blacklisté) : appairage refusé (CA-2, RG-ACC-07).');
        }

        $actif = $this->em->getRepository(Appairage::class)->findOneBy(['support' => $support, 'actif' => true]);
        if ($actif instanceof Appairage) {
            throw new ConflictHttpException('Support déjà appairé à un droit actif : révocation préalable requise (CA-2).');
        }

        if (!$droit instanceof DroitAcces) {
            throw new UnprocessableEntityHttpException('Droit introuvable.');
        }

        $appairage = new Appairage();
        $appairage->setSupport($support)
            ->setDroit($droit)
            ->setMode($mode)
            ->setActif(true)
            ->setAgent($agent)
            ->setEtablissement($etablissement);

        $this->em->persist($appairage);
        $this->em->flush();

        return $appairage;
    }

    public function revoquer(Appairage $appairage): Appairage
    {
        $appairage->setActif(false);
        $this->em->flush();

        return $appairage;
    }
}
