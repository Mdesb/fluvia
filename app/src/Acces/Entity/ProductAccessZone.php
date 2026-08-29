<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * « CE PRODUIT OUVRE TELLE OU TELLE ZONE » — la déclaration qui manquait.
 *
 * `DroitAcces::$authorisedSpaces` existait, `ValidationPassageHandler` s'en servait, et rien dans le
 * dépôt ne pouvait l'écrire : aucun groupe d'écriture, aucun appelant de `addAuthorisedSpace()`.
 * Toute collection restait vide, donc « ouvre tout », donc la règle ne refusait jamais rien.
 *
 * ── POURQUOI LA DÉCLARATION VIT SUR LE PRODUIT ET NON SUR LE DROIT ──────────────────────────────
 *
 * Un `DroitAcces` naît d'une VENTE. Y porter la règle obligerait à la poser billet par billet, après
 * coup, sur chaque titre vendu — personne ne le ferait. Elle se déclare donc une fois, sur le
 * produit, et `ProductAccessZoneResolver` en dépose une COPIE sur le droit à la projection.
 *
 * La copie n'est pas une commodité : c'est le droit que les terminaux embarquent pour décider HORS
 * LIGNE. Une règle qui ne vivrait que côté produit serait inapplicable par un lecteur déconnecté,
 * c'est-à-dire précisément quand elle compte.
 *
 * ── LA RÉFÉRENCE DE PRODUIT EST UN `Uuid` NU, ET C'EST VOULU (D2) ───────────────────────────────
 *
 * `App\Acces` ne dépend pas de `App\Offre`. Le produit est désigné par son identifiant, jamais par
 * une relation Doctrine — même patron que `DroitAcces::$produitRef`, déjà en place. Aucune contrainte
 * de base ne garantit donc l'existence du produit ; c'est le prix assumé de l'indépendance.
 *
 * ⚠ Corollaire D58 : toute comparaison sur `productRef` doit passer le type `uuid` en troisième
 * argument de `setParameter()`. Sans lui la requête ne trouve rien — et ne lève rien.
 *
 * ── AUCUNE LIGNE = AUCUNE RESTRICTION ───────────────────────────────────────────────────────────
 *
 * Un produit sans déclaration ouvre toutes les zones, comme avant. C'est ce qui rend la
 * fonctionnalité déployable : elle n'existe que là où quelqu'un l'a demandée. Fermer par défaut
 * refuserait des porteurs qui ont payé, sur un mécanisme dont ils ignorent l'existence.
 *
 * ── PAS ENCORE DE `#[ApiResource]`, ET C'EST DÉLIBÉRÉ ───────────────────────────────────────────
 *
 * Exposer quatre opérations qu'aucun écran n'appelle, c'est refaire exactement la faute que cette
 * classe répare : livrer un mécanisme que personne ne peut atteindre. Le garde-fou d'écart le refuse,
 * et il a raison. Les opérations s'ouvriront dans le commit qui apporte l'écran, pas avant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'access_product_zone')]
#[ORM\UniqueConstraint(name: 'uniq_product_zone', columns: ['product_ref', 'space_id'])]
class ProductAccessZone
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * Le produit vendu. Référence libre vers `App\Offre\Entity\Produit` (D2).
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $productRef;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false)]
    private EspaceAcces $space;

    /**
     * Cloisonnement : une déclaration appartient à l'établissement qui l'a posée. Le jour où
     * l'écriture s'ouvre, il sera estampillé depuis la session serveur et jamais lu du corps (D41).
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Etablissement $establishment;

    /**
     * ⚠ TOUT EST EXIGÉ À LA CONSTRUCTION, ET C'EST CE QUI REND L'OBJET SÛR.
     *
     * Une propriété `?T = null` sur une colonne NOT NULL passe PHP, passe Doctrine, et échoue au
     * `flush()` — hors de toute validation. Exiger les trois valeurs ici rend cet état inatteignable
     * plutôt que détectable.
     */
    public function __construct(Uuid $productRef, EspaceAcces $space, Etablissement $establishment)
    {
        $this->id = Uuid::v4();
        $this->productRef = $productRef;
        $this->space = $space;
        $this->establishment = $establishment;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProductRef(): Uuid
    {
        return $this->productRef;
    }

    public function getSpace(): EspaceAcces
    {
        return $this->space;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }
}
