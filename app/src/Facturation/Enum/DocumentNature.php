<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Les trois pièces de la chaîne de vente, avant la facture (FAC-1).
 *
 * **Une entité à natures plutôt que trois entités jumelles.** Un devis, un bon de commande et un bon
 * de livraison portent les mêmes choses : un destinataire, des lignes, des totaux, un état, une
 * filiation. Ce qui les distingue tient en trois phrases, pas en trois schémas. Trois entités
 * auraient triplé la ligne, le calcul des totaux et le constructeur — et divergé au premier correctif
 * appliqué à une seule. C'est le choix déjà fait par `Facture`, qui porte `NatureFacture`.
 *
 * **Ce que chacune engage, et c'est là que la différence compte :**
 */
enum DocumentNature: string
{
    /**
     * Une proposition de prix, valable jusqu'à une date.
     *
     * N'engage que l'émetteur, et seulement jusqu'à l'échéance. C'est la seule pièce qui expire.
     */
    case Quote = 'quote';

    /**
     * L'accord du client sur ce qu'il achète.
     *
     * Engage les deux parties. C'est le document qu'on ressort quand un client conteste ce qu'il a
     * commandé — d'où l'obligation de le figer une fois émis.
     */
    case SalesOrder = 'sales_order';

    /**
     * Ce qui a effectivement été livré.
     *
     * Peut différer de la commande — rupture, livraison partielle — et c'est justement pourquoi il
     * existe : facturer la commande plutôt que la livraison fait payer ce qui n'est pas arrivé.
     */
    case DeliveryNote = 'delivery_note';

    public function libelle(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::SalesOrder => 'Bon de commande',
            self::DeliveryNote => 'Bon de livraison',
        };
    }

    /**
     * La nature qui suit dans la chaîne, ou `null` si c'est la dernière avant la facture.
     *
     * **La chaîne se parcourt dans un sens et un seul.** On ne fabrique pas un devis depuis une
     * livraison ; l'ordre est celui du commerce, pas une commodité de navigation.
     */
    public function suivante(): ?self
    {
        return match ($this) {
            self::Quote => self::SalesOrder,
            self::SalesOrder => self::DeliveryNote,
            self::DeliveryNote => null,
        };
    }

    /** Seul le devis expired : les deux autres constatent un fait, ils ne proposent rien. */
    public function expired(): bool
    {
        return self::Quote === $this;
    }
}
