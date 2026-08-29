<?php

declare(strict_types=1);

namespace App\Crm\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Filtre `?client=` sur les demandes RGPD — l'IRI ou l'identifiant nu, au choix.
 *
 * ── POURQUOI PAS LE `SearchFilter` STANDARD ─────────────────────────────────────────────────────
 *
 * Il était déclaré, et il rendait TOUJOURS une liste vide. Mesuré, pas déduit : sur une collection
 * contenant deux demandes du même client,
 *
 *     ?statut=recue   →  200, total 2      la colonne scalaire répond
 *     ?client=<IRI>   →  200, total 0      l'association ne répond pas
 *
 * — et les deux propriétés étaient déclarées dans la MÊME annotation.
 *
 * ⚠ CE QUE ÇA DONNAIT SUR CET ÉCRAN-LÀ. Ouvert depuis la fiche de quelqu'un, il affichait « cette
 * personne n'a jamais demandé l'effacement de ses données » alors qu'elle en avait deux en cours —
 * sur le seul écran de l'application qui porte un délai légal d'un mois. Le pire genre de faux : une
 * réponse rassurante, sans erreur, sur un sujet où l'on ne repasse pas.
 *
 * ── CE QUI A ÉTÉ ÉCARTÉ AVANT D'ARRIVER ICI ─────────────────────────────────────────────────────
 *
 * L'hypothèse de départ était la jointure du cloisonnement : `PerimetreCrmExtension` joint déjà
 * `o.client` sous un alias fixe, et deux jointures sur la même association auraient pu déplacer la
 * condition. **Testé en neutralisant la restriction : le filtre rendait toujours zéro.** L'hypothèse
 * était fausse, et l'avoir vérifiée a évité de « corriger » une jointure qui n'avait rien fait.
 *
 * Reste la cause qui colle : l'identifiant de `Client` est un type Doctrine personnalisé (`uuid`,
 * stocké en `BINARY(16)`). Comparer une colonne binaire à une chaîne de 36 caractères ne trouve rien
 * — et ne lève rien. C'est la même famille que `SaleCustomerFilter` et `ProductRefFilter`, à ceci
 * près que le champ est ici une vraie association et non une référence libre.
 *
 * ⚠ CE FILTRE NE RÉPARE QUE CETTE RESSOURCE. Une douzaine d'autres entités du dépôt déclarent un
 * `SearchFilter` sur une association (`project`, `beneficiaire`, `etablissement`, `utilisateur`,
 * `role`, `auteur`…) et **aucun test n'en exerce un seul**. Elles sont probablement muettes de la
 * même façon. C'est un balayage à faire, pas une correction à recopier douze fois.
 */
final class PrivacyRequestCustomerFilter extends AbstractFilter
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
        if ($property !== 'client' || !\is_string($value) || $value === '') {
            return;
        }

        // L'écran envoie une IRI, un script envoie souvent l'identifiant nu : on accepte les deux
        // plutôt que d'imposer une forme. Le dernier segment fait foi.
        $identifiant = str_contains($value, '/') ? substr($value, (int) strrpos($value, '/') + 1) : $value;

        if (!Uuid::isValid($identifiant)) {
            // ⚠ Une valeur illisible ne doit pas rendre la collection ENTIÈRE : ce serait montrer les
            // demandes de tout le monde à qui se trompe de paramètre. On ferme.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parametre = $queryNameGenerator->generateParameterName('clientRgpd');

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.client) = :%s', $alias, $parametre))
            // ⚠ Le troisième argument est tout l'objet de ce fichier : sans le type, la comparaison
            // porte une chaîne contre une colonne binaire et ne trouve jamais rien.
            ->setParameter($parametre, Uuid::fromString($identifiant), 'uuid');
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            'client' => [
                'property' => 'client',
                'type' => 'string',
                'required' => false,
                'description' => 'Demandes RGPD de ce client (IRI ou UUID).',
            ],
        ];
    }
}
