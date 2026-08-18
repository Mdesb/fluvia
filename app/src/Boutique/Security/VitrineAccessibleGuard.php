<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use App\Boutique\Entity\Vitrine;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Garde d'accessibilité publique d'une vitrine (revue de sécurité — faille majeure) : une vitrine dont
 * l'établissement est inactif (`Etablissement::isActif()`), ou dont le canal `en_ligne` a été coupé
 * (`Vitrine::getCanauxActifs()`, RG-M3-01), n'est **jamais** exposée publiquement — ni son catalogue,
 * ni sa fiche, ni l'ouverture d'un panier. Même règle que `VitrinesPubliquesProvider` (déjà correcte),
 * factorisée ici pour être appliquée à tous les autres points d'entrée publics
 * (`CatalogueVitrineProvider`, `Get /boutique/vitrines/{id}`, `OuvrirPanierProcessor`).
 */
final class VitrineAccessibleGuard
{
    public function estAccessible(Vitrine $vitrine): bool
    {
        $etablissement = $vitrine->getEtablissement();

        return $etablissement !== null
            && $etablissement->isActif()
            && \in_array('en_ligne', $vitrine->getCanauxActifs(), true);
    }

    public function verifier(Vitrine $vitrine): void
    {
        if (!$this->estAccessible($vitrine)) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }
    }
}
