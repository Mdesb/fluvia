<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Offre\Entity\Produit;
use App\Offre\Port\PublicationPrerequisite;

/**
 * UN BILLET SANS ZONE N'OUVRE AUCUNE PORTE (D87) : on ne le met pas en vente là où il y a des portes.
 *
 * Mesuré en préprod le 08/10 : aucune des quatre entrées publiées n'avait de zone, sur des sites où le
 * contrôle d'accès est actif. Chacune se vendait et laissait son acheteur devant le tourniquet.
 *
 * Le contrôle d'accès « actif sur le site », c'est la capacité `controle_acces` de l'établissement
 * (`Fonctionnalites`, la seule source d'activation). Les zones se déclarent PAR SITE
 * (`ProductAccessZoneResolver::spacesFor`) : un produit vendu sur deux sites en exige sur chacun.
 *
 * Seuls les produits qui émettent un titre sont concernés (`TypeProduit::issuesTicket`) : un mug
 * n'ouvre pas de porte, et une prestation réservée tient ses zones de son activité (D89).
 */
final class AccessZonePublicationPrerequisite implements PublicationPrerequisite
{
    public function __construct(
        private readonly Fonctionnalites $features,
        private readonly ProductAccessZoneResolver $zones,
    ) {
    }

    public function missing(Produit $product): array
    {
        if (!$product->getType()?->issuesTicket()) {
            return [];
        }

        $sites = [];
        foreach ($product->getEtablissements() as $site) {
            if ($this->features->estActive($site, CapaciteCode::ControleAcces->value)
                && $this->zones->spacesFor($product->getId(), $site) === []) {
                $sites[] = $site->getNom();
            }
        }

        return $sites === [] ? [] : ['zone_acces' => sprintf(
            "Le contrôle d'accès est actif sur %s : choisissez les zones que ce produit ouvre. Sans zone, ses billets n'ouvrent aucune porte.",
            implode(', ', $sites),
        )];
    }
}
