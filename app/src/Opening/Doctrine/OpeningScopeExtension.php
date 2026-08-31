<?php

declare(strict_types=1);

namespace App\Opening\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Opening\Entity\OpeningException;
use App\Opening\Entity\OpeningSlot;
use App\Opening\Entity\OpeningSetting;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;

/**
 * Cloisonnement du module `App\Opening` sur l'AXE ÉTABLISSEMENT ACTIF (RG-SOCLE-05).
 *
 * ── CE QUI REFUSE VRAIMENT N'EST PAS ICI, ET IL FAUT LE SAVOIR ──────────────────────────────────
 *
 * `ContexteEtablissement::idActif()` **ne valide rien** : il lit l'en-tête `X-Etablissement` et
 * vérifie la syntaxe de l'UUID. C'est un SÉLECTEUR, pas une preuve. Filtrer là-dessus en croyant
 * cloisonner reviendrait à remplacer un contrôle par rien.
 *
 * Ce qui refuse, c'est `CalculateurDroits::codesEffectifs()`, qui ne retient que les affectations
 * portant sur l'établissement actif : sans affectation là-bas, aucun code, donc 403 avant que cette
 * requête n'existe. Cette extension borne la LISTE d'un lecteur déjà autorisé ; elle n'est pas le
 * rempart, elle est la conséquence.
 *
 * ── LA FERMETURE PAR DÉFAUT ─────────────────────────────────────────────────────────────────────
 *
 * Sans en-tête, `1 = 0` : liste vide, et non liste complète. Un appel sans contexte est une erreur
 * d'appelant ; y répondre par tous les établissements ferait d'un oubli d'en-tête une fuite.
 */
final readonly class OpeningScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const RESSOURCES = [
        OpeningSlot::class,
        OpeningException::class,
        OpeningSetting::class,
    ];

    public function __construct(
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!\in_array($resourceClass, self::RESSOURCES, true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        // ⚠ LE TROISIÈME ARGUMENT `'uuid'` N'EST PAS DÉCORATIF (D58). Sans lui, Doctrine lie
        // l'identifiant sans son type : la requête ne trouve rien, et ne lève rien. Une fuite de
        // cloisonnement produit des lignes en trop ; cette erreur-là produit des lignes en moins,
        // tout aussi silencieusement — un planning d'ouverture qui paraît vide alors qu'il est plein.
        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :opening_active', $alias))
            ->setParameter('opening_active', $actif, 'uuid')
            ->distinct();
    }
}
