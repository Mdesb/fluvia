<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;

/**
 * Résout les facettes/onglets visibles d'un produit selon son type (RG-M1-02 / CA-3).
 *
 * ── DEUX RÉPONSES À UN MÊME DÉSACCORD, ET ELLES NE SE VALENT PAS ───────────────────────────────
 *
 * Un produit peut porter une formule, une carte ou un stock que son type ne déclare pas. Il y a
 * deux façons de le traiter, et le choix dépend entièrement de **qui a provoqué le désaccord** :
 *
 *   · `purgerOrphelins()` DÉTACHE. Réservé à la conversion assistée de type (RG-M1-11), où
 *     l'exploitant a explicitement demandé le changement et où l'écran lui a annoncé les pertes.
 *
 *   · `conflits()` SIGNALE, sans rien toucher. C'est ce qu'appelle l'écriture ordinaire, qui
 *     refuse alors la saisie contradictoire au lieu de l'avaler.
 *
 * ⚠ AVANT LE 30/08, L'ÉCRITURE ORDINAIRE PURGEAIT AUSSI, ET ÇA DÉTRUISAIT DES DONNÉES EN SILENCE.
 * `ProduitProcessor` appelait `purgerOrphelins()` à chaque enregistrement : modifier la **couleur de
 * caisse** d'un produit suffisait à lui faire perdre son stock. Réponse 200, aucun message, aucune
 * trace, et l'exploitant sans aucun moyen de relier la cause à l'effet. Mesuré : deux produits de la
 * préproduction étaient dans ce cas.
 */
final class ResolveurFacettes
{
    /**
     * Les trois entités liées dont la présence dépend d'une facette du type.
     *
     * ⚠ Cette table est la SEULE source : `conflits()` et `purgerOrphelins()` la parcourent toutes
     * les deux. Une quatrième facette à entité liée s'ajoute ici et nulle part ailleurs — deux
     * listes à tenir en accord divergent au premier ajout.
     *
     * @var array<string, string> facette => nom de propriété
     */
    private const LIEES = [
        TypeProduit::FACETTE_FORMULE => 'formule',
        TypeProduit::FACETTE_CARNET => 'carte',
        TypeProduit::FACETTE_STOCK => 'stock',
    ];

    /**
     * Liste des facettes visibles pour un type donné.
     *
     * @return list<string>
     */
    public function facettesVisibles(?TypeProduit $type): array
    {
        return $type?->getFacettes() ?? [];
    }

    public function facetteVisible(?TypeProduit $type, string $facette): bool
    {
        return $type !== null && $type->aFacette($facette);
    }

    /**
     * Facettes que le produit porte alors que son type ne les déclare pas — sans rien modifier.
     *
     * @return array<string, string> facette => nom de propriété, dans l'ordre de self::LIEES
     */
    public function conflits(Produit $produit): array
    {
        $type = $produit->getType();
        $trouves = [];

        foreach (self::LIEES as $facette => $propriete) {
            if (!$this->facetteVisible($type, $facette) && $this->valeur($produit, $propriete) !== null) {
                $trouves[$facette] = $propriete;
            }
        }

        return $trouves;
    }

    /**
     * Purge les entités liées qui ne correspondent pas au type courant (CA-3).
     *
     * ⚠ N'APPELER QUE DEPUIS LA CONVERSION ASSISTÉE. C'est une destruction : le seul contexte où
     * elle est légitime est celui où l'exploitant a demandé le changement de type et où l'écran lui
     * a annoncé ce qu'il perdait. Depuis une écriture ordinaire, voir `conflits()`.
     */
    public function purgerOrphelins(Produit $produit): void
    {
        foreach ($this->conflits($produit) as $propriete) {
            $produit->{'set'.ucfirst($propriete)}(null);
        }
    }

    private function valeur(Produit $produit, string $propriete): ?object
    {
        /** @var ?object $valeur */
        $valeur = $produit->{'get'.ucfirst($propriete)}();

        return $valeur;
    }
}
