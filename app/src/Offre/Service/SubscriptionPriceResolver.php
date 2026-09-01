<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Formule;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LE PRIX D'UN ABONNEMENT VENDABLE, RÉSOLU AU MÊME ENDROIT POUR TOUTES LES PORTES.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ──────────────────────────────────────────────────────────────
 *
 * Trois chemins vendent un abonnement, et jusqu'ici **un seul appliquait les règles** :
 *
 *     en ligne      `Boutique\Service\SouscriptionAbonnementEnLigneHandler`
 *                   exige la facette SEPA, résout le prix, refuse si aucun ne l'est
 *     guichet       `Sport\Service\SouscriptionAbonnementHandler`
 *                   ne lisait AUCUNE des deux : montant en champ libre, facette jamais lue
 *     réengagement  `Sport\Service\ReengagementHandler`
 *                   idem
 *
 * ⚠ Ce n'était pas « le guichet est en retard » : **les règles vivaient dans le handler en ligne et
 * nulle part ailleurs**. Leur donner deux copies aurait fait apparaître la quatrième divergence au
 * prochain ajout, exactement comme les trois premières sont apparues. D'où un passage unique que
 * les trois portes traversent.
 *
 * ⚠ ARBITRAGE DE MAXIME, 01/09 : « il ne doit pas y avoir de prix libre. » C'est donc le guichet qui
 * s'aligne sur la boutique, et pas l'inverse.
 *
 * ── ⚠ EXTRACTION FIDÈLE, Y COMPRIS DANS CE QU'ELLE A D'IMPARFAIT ──────────────────────────────
 *
 * La boucle retient **la première grille dont le prix se résout**, dans l'ordre où Doctrine rend la
 * collection. Quand un produit porte plusieurs grilles visibles sur le canal, ce choix est donc
 * arbitraire.
 *
 * **Je ne l'ai pas corrigé, et c'est délibéré.** Trier par ordre d'affichage rendrait le choix
 * déterministe — et changerait silencieusement le prix que la boutique applique aujourd'hui, sur
 * des produits en vente. Un tri est une décision commerciale ; l'extraction n'en est pas le moment.
 * Mesuré le 01/09 : les trois produits porteurs de formule n'ont **qu'une seule grille**, donc
 * l'ambiguïté est théorique aujourd'hui et le restera tant que personne n'en ajoute une seconde.
 */
final class SubscriptionPriceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurPrix $resolveurPrix,
    ) {
    }

    /**
     * Le produit vendable derrière une formule.
     *
     * ⚠ LA RELATION VA DE `Produit` VERS `Formule`, ET PAS L'INVERSE. Une formule n'a aucune
     * référence vers son produit, donc la remontée passe par une requête. Elle est SANS AMBIGUÏTÉ :
     * `Produit::$formule` est un `OneToOne` avec `orphanRemoval`, donc une formule appartient à un
     * produit et un seul.
     *
     * C'est le guichet qui en a besoin : il reçoit une formule quand la boutique reçoit un produit.
     */
    public function forFormula(Formule $formule, Canal $canal, \DateTimeImmutable $date): ResolvedSubscriptionPrice
    {
        $produit = $this->em->getRepository(Produit::class)->findOneBy(['formule' => $formule]);
        if (!$produit instanceof Produit) {
            throw new UnprocessableEntityHttpException(
                'Cette formule n\'est portée par aucun produit : rien ne dit ce qu\'elle coûte. '
                . 'Rattachez-la à un produit du catalogue avant de la vendre.',
            );
        }

        return $this->forProduct($produit, $canal, $date);
    }

    public function forProduct(Produit $produit, Canal $canal, \DateTimeImmutable $date): ResolvedSubscriptionPrice
    {
        // RG-M3-17 : sans facette SEPA, l'abonnement n'est pas prélevable — et un échéancier posé
        // dessus serait une promesse qu'aucun mandat ne tiendra.
        $formule = $produit->getFormule();
        if ($formule === null || !$formule->isSepaActif()) {
            throw new UnprocessableEntityHttpException('Ce produit ne porte pas la facette SEPA (RG-M3-17).');
        }

        // RG-M1-01 : la première grille dont le prix se résout sur ce canal. Voir l'avertissement
        // en tête de classe sur le caractère arbitraire de « la première ».
        $typeTarif = null;
        $prix = null;
        foreach ($produit->getGrilles() as $grille) {
            $tarif = $grille->getTypeTarif();
            if (!$tarif instanceof TypeTarif) {
                continue;
            }
            $resolu = $this->resolveurPrix->resoudre($produit, $tarif, $date, $canal);
            if ($resolu !== null) {
                $typeTarif = $tarif;
                $prix = $resolu;
                break;
            }
        }

        if ($typeTarif === null || $prix === null) {
            // ⚠ LE MESSAGE NOMME LE CANAL, et ce n'est pas cosmétique : un produit peut être
            //    parfaitement vendable en ligne et invisible au guichet. « Aucun prix résolu »
            //    tout court enverrait chercher un prix manquant qui existe.
            throw new UnprocessableEntityHttpException(sprintf(
                'Produit non commercialisé sur le canal « %s » : aucun prix résolu. '
                . 'Vérifiez qu\'une grille tarifaire existe et que son type de tarif y est visible.',
                $canal->value,
            ));
        }

        return new ResolvedSubscriptionPrice($produit, $typeTarif, $prix);
    }
}
