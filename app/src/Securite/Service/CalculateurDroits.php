<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Calcule les droits effectifs d'un utilisateur (RG-SOCLE-04) : union des permissions des
 * affectations, ET des délégations actives non expirées (§2.5 plan-backoffice.md, US-L7-07), sur
 * l'établissement actif. Supporte les jokers (`*.lire`, `organisation.*`).
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
        $etablissement = null;
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

        // Délégations actives (§2.5) : même symétrie que les affectations — si aucun établissement
        // actif n'est fourni, les délégations actives de tous les établissements sont incluses.
        $criteresDelegation = ['beneficiaire' => $utilisateur, 'statut' => StatutDelegation::Active];
        if ($etablissement !== null) {
            $criteresDelegation['etablissement'] = $etablissement;
        }

        /** @var list<DelegationDroit> $delegations */
        $delegations = $this->em->getRepository(DelegationDroit::class)->findBy($criteresDelegation);

        $maintenant = new \DateTimeImmutable();
        foreach ($delegations as $delegation) {
            // Double garde défensive (statut déjà mis à jour par la commande planifiée en cas
            // normal) contre un retard d'exécution de `securite:delegations:expirer`.
            if (!$delegation->estActiveMaintenant($maintenant)) {
                continue;
            }
            $role = $delegation->getRole();
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
