<?php

declare(strict_types=1);

namespace App\Boutique\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Boutique\State\CurrentStorefrontProvider;

/**
 * `GET /boutique/vitrine-courante` — la boutique désignée par l'HÔTE de la requête (D104).
 *
 * Un front public servi depuis `piscine-ville.fluvia-app.com` n'a pas d'identifiant de vitrine à
 * mettre dans son URL : il en a un dans son nom de domaine. Ce point d'entrée le lui rend.
 *
 * **Pourquoi une ressource autonome et non une opération de plus sur `Vitrine`.** Même raison que
 * `CatalogueVitrine` et `VitrinesPubliques` : `uriTemplate` distinct, aucun identifiant dans le
 * chemin, aucun conflit possible avec les opérations CRUD de l'entité.
 *
 * ⚠ **Aucun écran ne l'appelle encore.** Le frontal public résout toujours par identifiant, et le
 * basculer est un lot du frontal, qui ne m'appartient pas. C'est écrit ici pour que ça ne rejoigne
 * pas en silence la pile « construit et sans aucun écran » : la suite est nommée dans TASKS.md.
 */
#[ApiResource(
    shortName: 'BoutiqueCurrentStorefront',
    operations: [
        new Get(
            uriTemplate: '/boutique/vitrine-courante',
            security: "is_granted('PUBLIC_ACCESS')",
            // ⚠ PAS DE `read: false` ICI. C'est l'etape de lecture qui execute le provider :
            // la desactiver rendait HTTP 200 avec un corps `null` -- une reponse vide qui a l'air
            // valide, y compris sur un hote inconnu ou l'on attend un 404.
            provider: CurrentStorefrontProvider::class,
        ),
    ],
)]
final class CurrentStorefront
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
