<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\ComplementaryProduct;
use App\Offre\Enum\ComplementMode;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * UNE VENTE NE SE VALIDE PAS SANS SES COMPLÉMENTS OBLIGATOIRES.
 *
 * ── CE QUE `Required` VEUT DIRE, ET CE QU'IL NE VEUT PAS DIRE ───────────────────────────────────
 *
 * Il est réservé à ce qu'une règle **extérieure** impose — un règlement intérieur, une obligation
 * légale : le bonnet de bain exigé à l'entrée du bassin. Jamais à une préférence commerciale.
 *
 * ⚠ PARCE QUE LE JOUR OÙ LE COMPLÉMENT MANQUE, LA VENTE DEVIENT IMPOSSIBLE. C'est voulu quand c'est
 * le bonnet ; c'est une file d'attente arrêtée quand c'est une serviette. Les modes `facultatif` et
 * `suggere` existent précisément pour que `Required` reste rare.
 *
 * ── ON VÉRIFIE LA PRÉSENCE, PAS LA QUANTITÉ ────────────────────────────────────────────────────
 *
 * `defaultQuantity` est un défaut de caisse, pas une contrainte : une famille de quatre prend
 * quatre casiers, un client seul en prend un, et l'agent corrige. Exiger que la quantité corresponde
 * transformerait une commodité en règle, et ferait refuser des ventes parfaitement légitimes.
 *
 * ── LE CONTRÔLE PASSE AVANT LA TRANSACTION ─────────────────────────────────────────────────────
 *
 * Un refus ne doit rien avoir commencé à écrire. On lit, on refuse, et rien n'a bougé — plutôt que
 * de découvrir le manquant au milieu d'un décrément de stock.
 */
final class RequiredComplementGuard
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @throws UnprocessableEntityHttpException si un complément obligatoire manque
     */
    public function verifier(Vente $vente): void
    {
        /** @var array<string, true> $productsPresents */
        $productsPresents = [];
        foreach ($vente->getLignes() as $ligne) {
            $productsPresents[(string) $ligne->getProduit()] = true;
        }

        if ($productsPresents === []) {
            return;
        }

        // ⚠ CHAQUE IDENTIFIANT A SON PROPRE PARAMÈTRE TYPÉ, ET CE N'EST PAS UN EXCÈS DE ZÈLE.
        //
        // Une première version passait la LISTE d'`Uuid` à un `IN (:produits)` sans type. La
        // comparaison portait alors des chaînes contre des colonnes `BINARY(16)` : elle ne trouvait
        // rien, ne levait rien, et la garde se taisait exactement comme si aucun complément n'était
        // obligatoire. C'est le défaut des 145 filtres corrigés la veille, commis ici même.
        //
        // Le test l'a attrapé parce qu'il mesure l'EFFET — la vente est-elle refusée — et non le
        // mécanisme. Un test qui aurait vérifié « la garde a été appelée » serait passé.
        $marqueurs = [];
        $parametres = [];
        foreach (array_keys($productsPresents) as $index => $identifiant) {
            $marqueurs[] = ':product'.$index;
            $parametres['product'.$index] = Uuid::fromString($identifiant);
        }

        $requete = $this->em->createQuery(sprintf(
            'SELECT c FROM %s c WHERE IDENTITY(c.product) IN (%s) AND c.mode = :mode',
            ComplementaryProduct::class,
            implode(', ', $marqueurs),
        ));

        foreach ($parametres as $nom => $valeur) {
            $requete->setParameter($nom, $valeur, 'uuid');
        }

        /** @var list<ComplementaryProduct> $liens */
        $liens = $requete->setParameter('mode', ComplementMode::Required->value)->getResult();

        $manquants = [];
        foreach ($liens as $lien) {
            $complement = $lien->getComplement();
            $parent = $lien->getProduct();
            if ($complement === null || $parent === null) {
                continue;
            }

            if (!isset($productsPresents[(string) $complement->getId()])) {
                // Le libellé du catalogue, pas l'identifiant : l'agent lit un message à la caisse,
                // et un UUID ne lui dit pas quoi ajouter.
                $manquants[] = sprintf('%s (exigé par %s)', $this->libelle($complement), $this->libelle($parent));
            }
        }

        if ($manquants !== []) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Complément obligatoire manquant : %s.',
                implode(', ', array_unique($manquants)),
            ));
        }
    }

    /**
     * Le libellé lisible d'un produit.
     *
     * `Produit::$libelle` est un tableau par langue : on prend la première valeur plutôt que de
     * supposer une langue, et l'on retombe sur le code si le libellé est vide — un message qui dit
     * « (sans nom) » n'aide personne à la caisse.
     */
    private function libelle(\App\Offre\Entity\Produit $product): string
    {
        foreach ($product->getLibelle() as $valeur) {
            if (\is_string($valeur) && $valeur !== '') {
                return $valeur;
            }
        }

        return $product->getCode();
    }
}
