<?php

declare(strict_types=1);

namespace App\Platform\Module;

use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un module masqué est, et s'il est allumé (recentrage sur la billetterie, lot 1).
 *
 * **Masqué = a une capacité et n'est pas du cœur.** Un tel module peut être éteint par
 * établissement ; éteint, ses opérations n'existent plus pour cet établissement (404) et ses tâches
 * planifiées ne tournent plus. Le cœur, lui, ne se garde pas et ne s'éteint pas.
 *
 * **L'appartenance d'une classe à un module se lit au registre, jamais dans une liste de classes.**
 * Une classe appartient au module dont le manifeste partage son namespace : une ressource ajoutée
 * demain dans `App\Padel` est gardée sans que personne l'écrive nulle part.
 *
 * **Seules les capacités comptent.** Rien ici ne lit une permission : un rôle `*.*` ne rallume pas
 * un module éteint. La garde dit si le module EXISTE pour l'établissement, les permissions disent
 * ensuite qui peut s'en servir — deux questions, deux réponses.
 */
final class ModuleGuard
{
    /**
     * Les modules du cœur (spec du recentrage du 20/10 ; Sport sur précision de Maxime, même jour).
     * Tout module sans capacité doit y figurer : il ne peut pas être éteint, donc il n'est pas masqué.
     * `ModuleGuardCoverageTest` le vérifie.
     */
    public const CORE_MODULES = ['dms', 'group', 'membership', 'piscine', 'sport', 'vente', 'website'];

    /** @var array<string, ModuleManifest>|null préfixe de namespace => manifeste masqué */
    private ?array $masked = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAccess $access,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Le module masqué de la première de ces classes qui en a un ; `null` si aucune n'en a. */
    public function maskedModuleOf(?string ...$classes): ?ModuleManifest
    {
        foreach ($classes as $class) {
            foreach ($class === null ? [] : $this->masked() as $prefix => $manifest) {
                if (str_starts_with(ltrim($class, '\\'), $prefix)) {
                    return $manifest;
                }
            }
        }

        return null;
    }

    public function isOn(ModuleManifest $module, Etablissement $establishment): bool
    {
        return $this->access->hasModule($establishment, (string) $module->capability());
    }

    /**
     * Allumé dans au moins un établissement — parmi ceux où l'utilisateur est affecté, s'il est donné.
     *
     * Sert là où aucun établissement n'est nommé : l'ordonnanceur, qui lance une commande pour tous
     * les établissements, et une requête sans en-tête `X-Etablissement`.
     */
    public function isOnAnywhere(ModuleManifest $module, ?Utilisateur $user = null): bool
    {
        $query = $this->em->createQueryBuilder()
            ->select('COUNT(f)')
            ->from(FonctionnaliteEtablissement::class, 'f')
            ->where('f.capaciteCode = :capability')
            ->andWhere('f.active = true')
            ->setParameter('capability', $module->capability());

        if ($user !== null) {
            $query
                ->andWhere(sprintf(
                    'IDENTITY(f.etablissement) IN (SELECT IDENTITY(guard_aff.etablissement) FROM %s guard_aff WHERE IDENTITY(guard_aff.utilisateur) = :guard_user)',
                    Affectation::class,
                ))
                ->setParameter('guard_user', $user->getId(), 'uuid');
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Les capacités qui portent un module du cœur : elles ne s'éteignent pas (CA-4).
     *
     * @return list<string>
     */
    public function coreCapabilities(): array
    {
        $codes = [];
        foreach ($this->registry->all() as $id => $manifest) {
            if (\in_array($id, self::CORE_MODULES, true) && $manifest->capability() !== null) {
                $codes[] = $manifest->capability();
            }
        }

        return $codes;
    }

    /** @return array<string, ModuleManifest> */
    private function masked(): array
    {
        if ($this->masked === null) {
            $this->masked = [];
            foreach ($this->registry->all() as $id => $manifest) {
                if ($manifest->capability() !== null && !\in_array($id, self::CORE_MODULES, true)) {
                    $this->masked[(new \ReflectionClass($manifest))->getNamespaceName() . '\\'] = $manifest;
                }
            }
        }

        return $this->masked;
    }
}
