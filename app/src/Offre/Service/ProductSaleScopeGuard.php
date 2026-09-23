<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Ce produit est-il commercialisé sur cet établissement ? » — la règle socle du catalogue, posée
 * sur UN produit tenu en main, là où les extensions Doctrine ne s'appliquent pas (un Processor qui
 * fait `getRepository(Produit::class)->findOneBy(...)` sort de `PerimetreProduitExtension`).
 *
 * CONVENTION SOCLE, identique à `PerimetreProduitExtension` / `CatalogueVitrineProvider` : une liste
 * d'établissements VIDE = socle = vendu partout ; une liste non vide = uniquement ces sites. Un
 * produit assigné à des sites n'est donc pas souscriptible ailleurs.
 *
 * **404 et non 403** (D3) : hors de son périmètre, le produit n'existe pas ici.
 */
final class ProductSaleScopeGuard
{
    public function assertSoldAt(Produit $produit, Etablissement $establishment): void
    {
        $etablissements = $produit->getEtablissements();
        if ($etablissements->isEmpty()) {
            return; // socle : vendu partout
        }
        foreach ($etablissements as $e) {
            if ((string) $e->getId() === (string) $establishment->getId()) {
                return;
            }
        }

        throw new NotFoundHttpException('Ce produit n\'est pas commercialisé sur cet établissement.');
    }
}
