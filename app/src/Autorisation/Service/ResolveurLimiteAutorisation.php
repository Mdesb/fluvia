<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Résout la `LimiteAutorisation` applicable à un utilisateur/opération/établissement (RG-AUTZ-03) :
 * requête directe sur `Affectation`/`DelegationDroit`, dupliquée volontairement de
 * `CalculateurDroits` (§0 n°7 plan) pour ne pas coupler `App\Securite` à un besoin propre à
 * `App\Autorisation` — même logique de non-couplage que `PerimetreVenteExtension`.
 */
final class ResolveurLimiteAutorisation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resoudre(OperationSensible $operation, Utilisateur $utilisateur, ?Uuid $etablissementActifId): ?LimiteAutorisation
    {
        if ($etablissementActifId === null) {
            return null;
        }
        $etablissement = $this->em->getRepository(Etablissement::class)->find($etablissementActifId);
        if ($etablissement === null) {
            return null;
        }

        // 1. Limite spécifique à l'utilisateur : prévaut sur toute limite de rôle (RG-AUTZ-03).
        $limiteUtilisateur = $this->em->getRepository(LimiteAutorisation::class)->findOneBy([
            'operation' => $operation,
            'utilisateur' => $utilisateur,
            'etablissement' => $etablissement,
        ]);
        if ($limiteUtilisateur !== null) {
            return $limiteUtilisateur;
        }

        // 2. Limites de rôle (rôles effectifs incluant délégations actives, RG-AUTZ-12).
        $roles = $this->rolesEffectifs($utilisateur, $etablissement);
        if ($roles === []) {
            return null;
        }

        // Une requête `findBy` par rôle (même mécanisme éprouvé que la recherche « limite
        // utilisateur » ci-dessus, criteria à valeur entité) plutôt qu'un `IN(:roles)` DQL avec un
        // tableau d'entités à identifiant UUID (BINARY(16)) — évite toute ambiguïté de conversion de
        // paramètre TABLEAU par le type Doctrine `uuid` (nombre de rôles par utilisateur toujours
        // faible en pratique, coût négligeable).
        $limitesParId = [];
        foreach ($roles as $role) {
            $trouvees = $this->em->getRepository(LimiteAutorisation::class)->findBy([
                'operation' => $operation,
                'etablissement' => $etablissement,
                'role' => $role,
            ]);
            foreach ($trouvees as $trouvee) {
                $limitesParId[(string) $trouvee->getId()] = $trouvee;
            }
        }
        $limites = array_values($limitesParId);

        if ($limites === []) {
            return null;
        }

        // 3. La plus restrictive l'emporte : plafond le plus bas (null = +∞) puis périmètre le plus
        // étroit (⚠ HYPOTHÈSE §2.3 plan, Risque n°3 — ordre de priorité non tranché explicitement
        // par RG-AUTZ-03 quand plafond et périmètre divergent entre deux limites de rôle).
        usort($limites, static function (LimiteAutorisation $a, LimiteAutorisation $b): int {
            $plafondA = $a->getPlafondMontant() ?? '99999999999.99';
            $plafondB = $b->getPlafondMontant() ?? '99999999999.99';
            $cmp = ComparateurMontant::comparer($plafondA, $plafondB);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a->getPerimetre()->rang() <=> $b->getPerimetre()->rang();
        });

        return $limites[0];
    }

    /** @return list<Role> */
    private function rolesEffectifs(Utilisateur $utilisateur, Etablissement $etablissement): array
    {
        $roles = [];

        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy([
            'utilisateur' => $utilisateur,
            'etablissement' => $etablissement,
        ]);
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            if ($role !== null) {
                $roles[(string) $role->getId()] = $role;
            }
        }

        $maintenant = new \DateTimeImmutable();
        /** @var list<DelegationDroit> $delegations */
        $delegations = $this->em->getRepository(DelegationDroit::class)->findBy([
            'beneficiaire' => $utilisateur,
            'etablissement' => $etablissement,
            'statut' => StatutDelegation::Active,
        ]);
        foreach ($delegations as $delegation) {
            if (!$delegation->estActiveMaintenant($maintenant)) {
                continue;
            }
            $role = $delegation->getRole();
            if ($role !== null) {
                $roles[(string) $role->getId()] = $role;
            }
        }

        return array_values($roles);
    }
}
