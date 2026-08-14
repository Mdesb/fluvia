<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Calcule les droits effectifs d'un utilisateur (RG-SOCLE-04) : union des permissions des
 * affectations sur l'établissement actif. Supporte les jokers (`*.lire`, `organisation.*`).
 *
 * NB « conflit → plus restrictive » : le modèle ne connaît que des permissions accordées
 * (pas de refus explicite), l'union est donc additive ; aucune permission ne peut en révoquer
 * une autre. La règle reste documentée pour un éventuel mécanisme de refus ultérieur.
 */
final class CalculateurDroits
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Codes « module.action » effectifs de l'utilisateur, bornés à l'établissement actif si fourni.
     *
     * @return list<string>
     */
    public function codesEffectifs(Utilisateur $utilisateur, ?Uuid $etablissementActif): array
    {
        $criteres = ['utilisateur' => $utilisateur];
        if ($etablissementActif !== null) {
            $etablissement = $this->em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($etablissementActif);
            if ($etablissement === null) {
                return [];
            }
            $criteres['etablissement'] = $etablissement;
        }

        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy($criteres);

        $codes = [];
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            if ($role === null) {
                continue;
            }
            foreach ($role->getPermissions() as $permission) {
                $codes[$permission->getCode()] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Vrai si l'un des codes couvre « module.action », jokers inclus.
     *
     * @param list<string> $codes
     */
    public function autorise(array $codes, string $module, string $action): bool
    {
        foreach ($codes as $code) {
            [$m, $a] = array_pad(explode('.', $code, 2), 2, '');
            if (($m === $module || $m === '*') && ($a === $action || $a === '*')) {
                return true;
            }
        }

        return false;
    }
}
