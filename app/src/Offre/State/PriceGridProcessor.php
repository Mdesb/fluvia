<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde de cohérence tarifaire sur le PATCH d'une case de grille (RG-M1-09, prolongement de
 * `PublicationGuard`).
 *
 * ── POURQUOI CETTE GARDE EXISTE ─────────────────────────────────────────────────────────────────
 *
 * `PublicationGuard` exige ≥1 prix valide POUR PUBLIER, et il n'est rejoué nulle part ensuite. Un
 * produit pouvait donc être publié avec un prix, puis se le faire vider par un simple PATCH : il
 * restait « Publié », devenait invendable, et RIEN ne le signalait — ni statut, ni erreur, ni trace.
 * La même règle s'applique maintenant à la seconde porte.
 *
 * ⚠ Elle ne refuse QUE le cas où il ne resterait AUCUN prix valide sur un produit PUBLIÉ. Vider un
 * tarif parmi plusieurs reste permis : un prix null veut dire « non commercialisé » (≠ gratuit,
 * CA-5), et c'est un geste métier légitime tant qu'il reste de quoi vendre.
 *
 * ── LES DEUX CHEMINS, ET POURQUOI ON NE PEUT PAS SE FIER AUX COLLECTIONS ─────────────────────────
 *
 * Deux écritures peuvent dépouiller un produit publié, et elles passent toutes deux par ce PATCH :
 * mettre `prix` à null, ou déplacer la case vers un AUTRE produit. Le second chemin interdit de
 * s'appuyer sur `Produit::aPrixValide()` : `setProduit()` est une affectation simple, donc la
 * collection `grilles` du produit de destination ne contient pas encore la case, et celle du produit
 * d'origine la contient toujours. Les deux réponses seraient fausses, en sens inverse — un refus
 * imaginaire d'un côté, un feu vert imaginaire de l'autre.
 *
 * On interroge donc la BASE, qui porte encore l'état d'avant écriture (le processor tourne avant le
 * flush), et on y rajoute à la main l'effet de la case en cours. C'est exact quel que soit l'état
 * d'hydratation de Doctrine.
 *
 * @implements ProcessorInterface<GrilleTarifaire, GrilleTarifaire>
 */
final class PriceGridProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<GrilleTarifaire, GrilleTarifaire> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof GrilleTarifaire) {
            $this->refuseLeavingAPublishedProductPriceless($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Les deux produits concernés par l'écriture : celui que la case quitte, celui qu'elle rejoint.
     * Quand la case ne bouge pas, c'est le même, et on ne le vérifie qu'une fois.
     */
    private function refuseLeavingAPublishedProductPriceless(GrilleTarifaire $grid): void
    {
        $destination = $grid->getProduit();
        $origin = $this->productBeforeWrite($grid);

        $affected = [];
        foreach ([$origin, $destination] as $product) {
            if ($product instanceof Produit && !\in_array($product, $affected, true)) {
                $affected[] = $product;
            }
        }

        foreach ($affected as $product) {
            if ($product->getStatut() !== StatutProduit::Publie) {
                continue;
            }
            if ($this->willStillHaveAPrice($product, $grid)) {
                continue;
            }

            throw new UnprocessableEntityHttpException(sprintf(
                'Ce tarif est le dernier prix renseigné du produit publié « %s » : le vider le '
                .'rendrait invendable sans qu\'aucun statut ne le signale. Dépublier le produit '
                .'d\'abord, ou renseigner un autre tarif.',
                $product->getCode()
            ));
        }
    }

    /**
     * Le produit auquel la case appartenait AVANT cette écriture. Doctrine garde l'entité elle-même
     * dans les données d'origine d'une association « to-one ». Absent pour une case neuve.
     */
    private function productBeforeWrite(GrilleTarifaire $grid): ?Produit
    {
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($grid);
        $before = $original['produit'] ?? null;

        return $before instanceof Produit ? $before : null;
    }

    /**
     * Restera-t-il un prix à ce produit une fois l'écriture passée ? On compte en base les AUTRES
     * cases qui portent un prix, puis on ajoute l'effet de celle qu'on est en train d'écrire.
     */
    private function willStillHaveAPrice(Produit $product, GrilleTarifaire $grid): bool
    {
        $others = (int) $this->em->createQuery(
            'SELECT COUNT(g.id) FROM '.GrilleTarifaire::class.' g '
            .'WHERE g.produit = :product AND g.id <> :grid AND g.prix IS NOT NULL'
        )
            // ⚠ L'IDENTIFIANT ET SON TYPE, JAMAIS L'ENTITE. Passer le Produit laisse Doctrine
            // deduire le type du parametre, et il ne trouve pas le type applicatif « uuid » :
            // la comparaison ne rapproche alors AUCUNE ligne et le decompte rend 0. Un zero qui
            // ne repond pas a la question posee — ici, il faisait refuser TOUT vidage de prix
            // sur un produit publie, et les deux cas de refus passaient par accident.
            ->setParameter('product', $product->getId(), UuidType::NAME)
            ->setParameter('grid', $grid->getId(), UuidType::NAME)
            ->getSingleScalarResult();

        if ($others > 0) {
            return true;
        }

        // La case en cours ne sauve le produit que si elle lui reste attachée AVEC un prix.
        return $grid->getProduit() === $product && $grid->getPrix() !== null;
    }
}
