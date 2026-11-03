<?php

declare(strict_types=1);

namespace App\Platform\Module;

use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\PlatformScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

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
     * Les modules du cœur (spec du recentrage du 20/10 ; Sport sur précision de Maxime, même jour ;
     * Personnel à la relecture de la PR 2, même jour). Tout module sans capacité doit y figurer : il ne
     * peut pas être éteint, donc il n'est pas masqué. `ModuleGuardCoverageTest` le vérifie.
     */
    public const CORE_MODULES = ['dms', 'group', 'membership', 'personnel', 'piscine', 'sport', 'vente', 'website'];

    /**
     * Les capacités du cœur qui n'ont pas de manifeste (spec du recentrage, « Cœur » : Compta, Stock,
     * Réservation, Accès, Boutique, Sepa, Recouvrement, Calendar ; décision de Maxime du 20/10).
     * Elles ne s'éteignent pas plus que les modules du cœur (CA-4).
     */
    public const CORE_CAPABILITIES = [
        'agenda', 'boutique_en_ligne', 'casiers', 'comptabilite', 'controle_acces', 'recouvrement', 'reservation', 'sepa', 'stock',
    ];

    /** @var array<string, ModuleManifest>|null préfixe de namespace => manifeste masqué */
    private ?array $masked = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly PlatformScope $platformScope,
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
     * LA règle, la seule : la garde des routes (`ModuleGuardListener`) et `/me` la lisent toutes deux,
     * pour que le menu ne montre jamais un module que le serveur refuse, ni l'inverse.
     *
     * Avec un établissement : allumé pour lui. Sans établissement, pour un `Utilisateur` : allumé dans
     * au moins un de ses établissements (retirer l'en-tête ne doit pas suffire à passer). Plateforme,
     * ou aucune identité d'utilisateur : pas de garde.
     */
    public function isOnFor(ModuleManifest $module, ?Etablissement $establishment, ?UserInterface $user): bool
    {
        if ($establishment !== null) {
            return $this->isOn($module, $establishment);
        }

        return !$user instanceof Utilisateur
            || $this->platformScope->isGlobal($user)
            || $this->isOnAnywhere($module, $user);
    }

    /**
     * Les modules actifs, au sens de `isOnFor()` : tout le cœur, plus les modules masqués allumés.
     *
     * @return list<string> identifiants de module, triés
     */
    public function activeModules(?Etablissement $establishment, ?UserInterface $user): array
    {
        $masked = array_map(static fn (ModuleManifest $m): string => $m->id(), $this->masked());
        $active = [];
        foreach ($this->registry->all() as $id => $manifest) {
            if (!\in_array($id, $masked, true) || $this->isOnFor($manifest, $establishment, $user)) {
                $active[] = $id;
            }
        }
        sort($active);

        return $active;
    }

    /**
     * Cette classe peut-elle agir pour cet établissement ? Toujours, hors d'un module masqué ; sinon
     * seulement si le module y est allumé. Établissement inconnu ou absent : non (échec fermé).
     *
     * Sert là où aucune route n'est en jeu : écouteurs d'événements, messages asynchrones, commandes.
     */
    public function allows(string $class, Etablissement|Uuid|null $establishment): bool
    {
        $module = $this->maskedModuleOf($class);
        if ($module === null) {
            return true;
        }
        if ($establishment instanceof Uuid) {
            $establishment = $this->em->find(Etablissement::class, $establishment);
        }

        return $establishment instanceof Etablissement && $this->isOn($module, $establishment);
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
     * Les capacités du cœur — celles de ses modules, et celles qui n'ont pas de manifeste : elles ne
     * s'éteignent pas (CA-4).
     *
     * @return list<string>
     */
    public function coreCapabilities(): array
    {
        $codes = self::CORE_CAPABILITIES;
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
