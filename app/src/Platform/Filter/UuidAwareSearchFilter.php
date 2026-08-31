<?php

declare(strict_types=1);

namespace App\Platform\Filter;

use ApiPlatform\Doctrine\Common\Filter\SearchFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * LE `SearchFilter` STANDARD, RENDU CAPABLE DE COMPARER UN `Uuid`.
 *
 * ── LE DÉFAUT, MESURÉ ET NON DÉDUIT ─────────────────────────────────────────────────────────────
 *
 * Posé sur une propriété dont l'identifiant est un `Uuid`, le `SearchFilter` d'API Platform rend
 * **toujours une liste vide**. L'identifiant est stocké en `BINARY(16)` par le type Doctrine `uuid` ;
 * le comparer à une chaîne de 36 caractères ne trouve rien — et surtout ne lève rien.
 *
 *     /api/demande_rgpds   ?statut=recue → 2      ?client=<IRI>     → 0
 *     /api/passages        total = 1              ?controleur=<IRI> → 0
 *
 * ⚠ CE QUE ÇA DONNAIT SUR L'ÉCRAN LE PLUS SENSIBLE. Les demandes RGPD, ouvertes depuis la fiche de
 * quelqu'un, annonçaient « cette personne n'a jamais demandé l'effacement de ses données » alors
 * qu'elle en avait deux en cours — sur le seul écran qui porte un délai légal d'un mois.
 *
 * ── POURQUOI UN DÉCORATEUR PLUTÔT QUE 145 DÉCLARATIONS CORRIGÉES ────────────────────────────────
 *
 * L'audit `bin/audit-uuid.php` interroge le mapping Doctrine — pas les noms, pas une expression
 * régulière — et compte **145 propriétés** dans 28 modules qui rendent une liste vide en silence.
 *
 * On aurait pu les convertir une par une vers `UuidReferenceFilter`. C'est un correctif d'instantané :
 * il traite les 145 qui existent, pas la 146e que quelqu'un écrira demain en toute bonne foi, dans
 * un module qu'il connaît, avec le filtre que la documentation d'API Platform lui recommande. Et
 * tenir cet instantané demanderait un cliquet, c'est-à-dire plus de machinerie que ce décorateur.
 *
 * Le défaut est corrigé là où il naît : la déclaration `SearchFilter` reste ce qu'elle est, et elle
 * devient juste. `UuidAwareSearchFilterPass` remplace les 111 services de filtre par celui-ci.
 *
 * ⚠ CE DÉCORATEUR EST INVISIBLE DEPUIS L'ENTITÉ, ET C'EST SON SEUL DÉFAUT. Un lecteur y voit
 * `SearchFilter` et obtient autre chose — or l'invisibilité est précisément ce qui a permis au
 * défaut d'origine de vivre si longtemps. Deux contrepoids : `debug:container` montre la vraie
 * classe, et `UuidAwareSearchFilterTest` échoue si la substitution n'a plus lieu. Retirer la passe
 * casse une assertion, pas seulement un comportement.
 *
 * ── CE QU'IL FAIT DE PLUS QUE `UuidReferenceFilter` ─────────────────────────────────────────────
 *
 * Il accepte plusieurs valeurs (`?client[]=…&client[]=…`), que le `SearchFilter` sait faire sur les
 * colonnes ordinaires et qui était donc attendu ici aussi. Chaque identifiant est lié à son propre
 * paramètre typé plutôt qu'en bloc : un `IN` sur un tableau demanderait au pilote de deviner le type
 * de chaque élément, et c'est exactement la devinette qui a produit le défaut.
 *
 * Une valeur illisible **ferme** la collection au lieu de l'ouvrir. Rendre tout à qui se trompe de
 * paramètre montrerait des lignes que personne n'a demandées : le sens sûr de l'erreur est celui qui
 * restreint.
 *
 * ── CE QU'IL NE TOUCHE PAS ──────────────────────────────────────────────────────────────────────
 *
 * Tout le reste part au `SearchFilter` d'origine : colonnes scalaires, énumérations, stratégies
 * `partial` et `iexact`, propriétés imbriquées (`client.nom`, qui porte un point et n'est donc pas
 * une propriété de la classe racine). On n'intercepte que ce qu'on sait mieux traiter.
 */
final class UuidAwareSearchFilter implements FilterInterface, SearchFilterInterface
{
    public function __construct(
        private readonly FilterInterface $interne,
        private readonly ManagerRegistry $registre,
    ) {
    }

    public function apply(
        QueryBuilder $queryBuilder,
        \ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $filtres = $context['filters'] ?? null;

        if (\is_array($filtres) && $filtres !== []) {
            $metadonnees = $this->metadonnees($resourceClass);

            if ($metadonnees !== null) {
                // `getDescription()` énumère les propriétés que l'attribut a réellement déclarées :
                // on ne se saisit jamais d'un paramètre que ce filtre n'a pas ouvert.
                $declarees = $this->interne->getDescription($resourceClass);

                foreach ($filtres as $nom => $valeur) {
                    if (!\is_string($nom) || !\array_key_exists($nom, $declarees)) {
                        continue;
                    }
                    if (!$this->identifieParUuid($metadonnees, $nom)) {
                        continue;
                    }

                    $this->appliquer($metadonnees, $nom, $valeur, $queryBuilder, $queryNameGenerator);

                    // Retiré du contexte : sans cela le filtre d'origine rejouerait sa comparaison
                    // de chaîne par-dessus la nôtre, et rendrait de nouveau zéro.
                    unset($context['filters'][$nom]);
                }
            }
        }

        $this->interne->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return $this->interne->getDescription($resourceClass);
    }

    private function metadonnees(string $resourceClass): ?ClassMetadata
    {
        $manager = $this->registre->getManagerForClass($resourceClass);
        if ($manager === null) {
            return null;
        }

        $metadonnees = $manager->getClassMetadata($resourceClass);

        return $metadonnees instanceof ClassMetadata ? $metadonnees : null;
    }

    /**
     * La question n'est pas « le nom ressemble-t-il à une référence » mais « le mapping dit-il que
     * cette propriété s'identifie par un Uuid ». Deux formes cohabitent dans ce dépôt : l'association
     * Doctrine, et la référence libre `?Uuid` que D2 impose entre deux modules.
     */
    private function identifieParUuid(ClassMetadata $metadonnees, string $propriete): bool
    {
        if ($metadonnees->hasAssociation($propriete)) {
            $cible = $metadonnees->getAssociationTargetClass($propriete);
            $manager = $this->registre->getManagerForClass($cible);
            if ($manager === null) {
                return false;
            }

            $metaCible = $manager->getClassMetadata($cible);
            $identifiants = $metaCible->getIdentifierFieldNames();

            return \count($identifiants) === 1
                && ($metaCible->getTypeOfField($identifiants[0]) ?? '') === 'uuid';
        }

        return $metadonnees->hasField($propriete)
            && ($metadonnees->getTypeOfField($propriete) ?? '') === 'uuid';
    }

    private function appliquer(
        ClassMetadata $metadonnees,
        string $propriete,
        mixed $valeur,
        QueryBuilder $queryBuilder,
        \ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface $queryNameGenerator,
    ): void {
        $brutes = \is_array($valeur) ? $valeur : [$valeur];
        $identifiants = [];

        foreach ($brutes as $brute) {
            if (!\is_string($brute) || $brute === '') {
                continue;
            }

            // L'écran envoie une IRI, un script envoie souvent l'identifiant nu : on accepte les
            // deux plutôt que d'imposer une forme. Le dernier segment fait foi.
            $identifiant = str_contains($brute, '/')
                ? substr($brute, (int) strrpos($brute, '/') + 1)
                : $brute;

            if (Uuid::isValid($identifiant)) {
                $identifiants[] = Uuid::fromString($identifiant);
            }
        }

        $alias = $queryBuilder->getRootAliases()[0];

        if ($identifiants === []) {
            // ⚠ On ferme, on n'ouvre pas : une valeur illisible qui rendrait toute la collection
            // montrerait à l'appelant des lignes qu'il n'a jamais demandées.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        // `IDENTITY()` sur une association, la colonne elle-même sur une référence libre : dans les
        // deux cas la comparaison porte sur l'identifiant, et le type `uuid` fait la conversion.
        $chemin = $metadonnees->hasAssociation($propriete)
            ? \sprintf('IDENTITY(%s.%s)', $alias, $propriete)
            : \sprintf('%s.%s', $alias, $propriete);

        $marqueurs = [];
        foreach ($identifiants as $identifiant) {
            $parametre = $queryNameGenerator->generateParameterName($propriete);
            $marqueurs[] = ':'.$parametre;
            // ⚠ Le troisième argument est tout l'objet de ce fichier : sans le type, la comparaison
            // porte une chaîne contre une colonne binaire et ne trouve jamais rien.
            $queryBuilder->setParameter($parametre, $identifiant, 'uuid');
        }

        $queryBuilder->andWhere(\count($marqueurs) === 1
            ? \sprintf('%s = %s', $chemin, $marqueurs[0])
            : \sprintf('%s IN (%s)', $chemin, implode(', ', $marqueurs)));
    }
}
