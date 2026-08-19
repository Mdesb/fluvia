<?php

declare(strict_types=1);

namespace App\Personnel\Security;

use App\Personnel\Entity\Employe;
use App\Personnel\Entity\RattachementEmploye;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recoupe qu'un `Employe` résolu par UUID brut (corps de requête) est bien dans le périmètre de
 * l'agent (RG-SOCLE-05), même patron que `App\Personnel\Doctrine\PerimetrePersonnelExtension::
 * restreindreViaEmploye` : visible si l'employé n'a **aucun** `RattachementEmploye` (état
 * transitoire, §1 du plan) — ou s'il en a au moins un sur un établissement où l'agent possède une
 * `Affectation`. Sans ce recoupement, un agent autorisé `personnel.gerer_planning` sur son seul
 * établissement actif pourrait écrire (ex. déclarer une absence) pour n'importe quel employé d'un
 * établissement totalement étranger, simplement en connaissant son UUID.
 */
final class PerimetreEmployeVerificateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function estDansLePerimetre(Employe $employe, Utilisateur $agent): bool
    {
        /** @var list<RattachementEmploye> $rattachements */
        $rattachements = $this->em->getRepository(RattachementEmploye::class)->findBy(['employe' => $employe]);
        if ($rattachements === []) {
            return true;
        }

        foreach ($rattachements as $rattachement) {
            $etablissement = $rattachement->getEtablissement();
            if ($etablissement === null) {
                continue;
            }
            $affectation = $this->em->getRepository(Affectation::class)->findOneBy([
                'utilisateur' => $agent,
                'etablissement' => $etablissement,
            ]);
            if ($affectation !== null) {
                return true;
            }
        }

        return false;
    }
}
