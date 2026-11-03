<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Enum\PeriodiciteFormule;
use App\Offre\Port\PublicationPrerequisite;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Garde de publication (RG-M1-09 / CA-11) : un produit ne passe en « Publié » que s'il se vend tel
 * que son type le promet. Renvoie la liste PRÉCISE de ce qui manque, chaque fois avec la phrase qui
 * dit quoi faire (vide = publiable).
 *
 * ⚠ ELLE NE REGARDAIT QUE LE LIBELLÉ, LE SITE, LE CANAL, LE PRIX ET LA CATÉGORIE COMPTABLE (étude du
 * 08/10). Une carte sans carte devenait à la borne un billet sans crédit, donc illimité ; un abonnement
 * sans formule se vendait comme un article simple. La garde exige donc aussi ce que le TYPE suppose,
 * et ce que les autres modules déclarent par `PublicationPrerequisite` (zone d'accès, créneau).
 *
 * Elle joue au passage brouillon → publié, et à la conversion de type d'un produit publié
 * (`ConvertirProcessor`). Un produit déjà publié n'est jamais dépublié par elle.
 */
final class PublicationGuard
{
    /** @param iterable<PublicationPrerequisite> $prerequisites */
    public function __construct(
        #[AutowireIterator(PublicationPrerequisite::TAG)]
        private readonly iterable $prerequisites = [],
    ) {
    }

    /**
     * @return list<string> codes des prérequis manquants (vide si publiable)
     */
    public function prerequisManquants(Produit $produit): array
    {
        return array_keys($this->missing($produit));
    }

    /**
     * @return array<string, string> code => phrase pour l'exploitant
     */
    public function missing(Produit $produit): array
    {
        $type = $produit->getType();
        $missing = array_filter([
            'libelle' => $produit->getLibelle() === [] ? 'Donnez-lui un libellé.' : null,
            'site' => $produit->getEtablissements()->isEmpty() ? 'Choisissez au moins un site où le vendre.' : null,
            'canal' => $produit->getCanaux() === [] ? 'Choisissez au moins un canal de vente.' : null,
            'prix' => !$produit->aPrixValide() ? 'Donnez-lui au moins un prix.' : null,
            'categorie_comptable' => !$produit->aCategorieComptable() ? 'Rattachez-le à une catégorie comptable.' : null,
            'carte' => $type?->aFacette(TypeProduit::FACETTE_CARNET) && $produit->getCarte() === null
                ? "Créez sa carte, avec le nombre d'entrées qu'elle donne : sans elle, elle se vendrait comme un billet sans limite d'entrées."
                : null,
            'formule' => $type?->aFacette(TypeProduit::FACETTE_FORMULE) ? $this->formulaMissing($produit) : null,
        ]);

        foreach ($this->prerequisites as $prerequisite) {
            $missing += $prerequisite->missing($produit);
        }

        return $missing;
    }

    /**
     * ⚠ UNE FORMULE « PERSONNALISÉE » NE SE SOUSCRIT PAS : `SouscriptionAbonnementHandler` la refuse,
     * faute de cadence de prélèvement (`MembershipPeriodicity::depuisFormule`). Publiée, elle
     * s'afficherait et échouerait à la vente — sauf vendue comme produit simple, sans souscription.
     */
    private function formulaMissing(Produit $produit): ?string
    {
        $formule = $produit->getFormule();
        if ($formule === null) {
            return 'Créez sa formule (périodicité, prélèvement) : sans elle, il se vendrait comme un article simple, sans échéancier.';
        }
        if ($formule->getPeriodicite() === PeriodiciteFormule::Personnalise
            && ($produit->getChampsPerso()['venteSansSouscription'] ?? false) !== true) {
            return 'Choisissez une périodicité mensuelle ou annuelle : une formule « personnalisée » ne peut pas encore être souscrite.';
        }

        return null;
    }

    public function estPubliable(Produit $produit): bool
    {
        return $this->missing($produit) === [];
    }
}
