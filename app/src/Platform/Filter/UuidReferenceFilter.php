<?php

declare(strict_types=1);

namespace App\Platform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * FILTRER PAR UN IDENTIFIANT `Uuid` — ASSOCIATION OU RÉFÉRENCE LIBRE.
 *
 * ── POURQUOI CE FILTRE EXISTE, ET CE QU'IL REMPLACE ─────────────────────────────────────────────
 *
 * Le `SearchFilter` standard, posé sur une propriété dont l'identifiant est un `Uuid`, rend
 * **toujours une liste vide**. Mesuré, pas déduit, sur deux modules sans rapport :
 *
 *     /api/demande_rgpds   ?statut=recue → 2      ?client=<IRI>     → 0
 *     /api/passages        total = 1             ?controleur=<IRI>  → 0
 *                                                ?equipement=<IRI>  → 0
 *                                                ?espace=<IRI>      → 0
 *
 * La cause : l'identifiant est stocké en `BINARY(16)` par le type Doctrine `uuid`. Comparer cette
 * colonne à une chaîne de 36 caractères ne trouve rien — et ne lève rien. Le filtre est donc muet,
 * et une liste vide se lit « il n'y en a pas ».
 *
 * ⚠ CE QUE ÇA DONNAIT SUR L'ÉCRAN LE PLUS SENSIBLE. Les demandes RGPD, ouvertes depuis la fiche de
 * quelqu'un, annonçaient « cette personne n'a jamais demandé l'effacement de ses données » alors
 * qu'elle en avait deux en cours — sur le seul écran qui porte un délai légal d'un mois.
 *
 * ── UNE HYPOTHÈSE ÉCARTÉE, ET C'EST CE QUI A ÉVITÉ UNE FAUSSE CORRECTION ────────────────────────
 *
 * On soupçonnait la jointure du cloisonnement : `PerimetreCrmExtension` joint déjà `o.client` sous un
 * alias fixe. Testé en neutralisant la restriction : le filtre rendait toujours zéro. Sans cette
 * vérification, on aurait « corrigé » une jointure qui n'y était pour rien.
 *
 * ── POURQUOI UN FILTRE À CÔTÉ, ET NON UN `SearchFilter` DÉRIVÉ ──────────────────────────────────
 *
 * Redéfinir `SearchFilter` demanderait de reprendre sa résolution d'IRI, ses stratégies partielles
 * et ses propriétés imbriquées — beaucoup de surface pour un seul comportement à changer. Celui-ci
 * ne traite QUE les identifiants `Uuid` ; les colonnes scalaires restent au `SearchFilter`, qui les
 * gère correctement.
 *
 * On déclare donc les deux côte à côte :
 *
 *     #[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact'])]
 *     #[ApiFilter(UuidReferenceFilter::class, properties: ['client', 'controleur'])]
 *
 * ── UNE VALEUR ILLISIBLE FERME LA COLLECTION ────────────────────────────────────────────────────
 *
 * Rendre la collection ENTIÈRE à qui se trompe de paramètre montrerait à cet appelant des lignes
 * qu'il n'a pas demandées. Le filtre ferme plutôt que d'ouvrir : c'est le sens sûr de l'erreur.
 */
final class UuidReferenceFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass) || !\is_string($value) || $value === '') {
            return;
        }

        $metadata = $this->getClassMetadata($resourceClass);
        $alias = $queryBuilder->getRootAliases()[0];
        $terminal = $property;

        // ── UN SEUL SAUT D'ASSOCIATION, ET DELIBEREMENT UN SEUL ─────────────────────────────
        //
        // `statementImport.bankAccount` rendait zero : `UuidAwareSearchFilter` repare la
        // comparaison chaine/BINARY(16) sur les proprietes de la classe racine, mais sa notice dit
        // qu'il DELEGUE les proprietes imbriquees — « on n'intercepte que ce qu'on sait mieux
        // traiter ». Ce filtre-ci ne les traitait pas davantage. La famille exemptee contenait donc
        // le cas casse, et c'etait celui de l'ecran de rapprochement bancaire : apres un import
        // reussi de quatre lignes, il affichait « Aucune ligne a rapprocher ».
        //
        // Deux sauts et plus ne sont PAS traites : on ne s'en saisit pas plutot que de les traiter
        // a moitie. Un chemin plus long passe donc au filtre standard, avec son comportement connu
        // — c'est un choix visible ici, pas un oubli.
        if (str_contains($property, '.')) {
            $segments = explode('.', $property);
            if (\count($segments) !== 2) {
                return;
            }
            [$relation, $terminal] = $segments;
            if (!$metadata->hasAssociation($relation)) {
                return;
            }

            $metadata = $this->getClassMetadata($metadata->getAssociationTargetClass($relation));
            $aliasJointure = $queryNameGenerator->generateJoinAlias($relation);
            // `innerJoin` : une ligne sans son association n'a de toute facon rien a comparer.
            // La relation est un `ManyToOne`, donc la jointure ne multiplie aucune ligne.
            $queryBuilder->innerJoin(sprintf('%s.%s', $alias, $relation), $aliasJointure);
            $alias = $aliasJointure;
        }

        $estAssociation = $metadata->hasAssociation($terminal);
        if (!$estAssociation && !$metadata->hasField($terminal)) {
            return;
        }

        // L'écran envoie une IRI, un script envoie souvent l'identifiant nu : on accepte les deux
        // plutôt que d'imposer une forme. Le dernier segment fait foi.
        $identifiant = str_contains($value, '/') ? substr($value, (int) strrpos($value, '/') + 1) : $value;

        if (!Uuid::isValid($identifiant)) {
            // ⚠ On ferme, on n'ouvre pas. Une valeur illisible qui rendrait toute la collection
            // montrerait à l'appelant des lignes qu'il n'a jamais demandées.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parametre = $queryNameGenerator->generateParameterName(str_replace('.', '_', $property));

        // `IDENTITY()` sur une association, la colonne elle-même sur une référence libre : dans les
        // deux cas la comparaison porte sur l'identifiant, et le type `uuid` fait la conversion.
        $chemin = $estAssociation
            ? sprintf('IDENTITY(%s.%s)', $alias, $terminal)
            : sprintf('%s.%s', $alias, $terminal);

        $queryBuilder
            ->andWhere(sprintf('%s = :%s', $chemin, $parametre))
            // ⚠ Le troisième argument est tout l'objet de ce fichier : sans le type, la comparaison
            // porte une chaîne contre une colonne binaire et ne trouve jamais rien.
            ->setParameter($parametre, Uuid::fromString($identifiant), 'uuid');
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach (array_keys($this->properties ?? []) as $property) {
            $description[(string) $property] = [
                'property' => (string) $property,
                'type' => 'string',
                'required' => false,
                'description' => 'Filtre exact sur l’identifiant (IRI ou UUID).',
            ];
        }

        return $description;
    }
}
