<?php

declare(strict_types=1);

namespace App\Platform\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Platform\State\LoadedBuildProvider;

/**
 * `GET /plateforme/version-chargee` — LE COMMIT QUE PHP A RÉELLEMENT CHARGÉ, pas celui du disque.
 *
 * ── POURQUOI `version.json` NE SUFFIT PAS ────────────────────────────────────────────────────────
 *
 * `version.json` est publié par le `rsync` du frontal : il décrit **la moitié frontale** du produit.
 * Le serveur, lui, ne suit pas le disque — `opcache.validate_timestamps=0` fige le code au dernier
 * redémarrage de FPM. Constaté le 31/08 : un correctif de cloisonnement écrit à 13:38 sur un
 * conteneur démarré à 13:31 n'était pas chargé, et rien ne pouvait le dire.
 *
 * C'est « poussé n'est pas servi » un cran plus bas : **fusionné n'est pas chargé.**
 *
 * ⚠ **Lire le fichier dans le conteneur ne prouve rien.** Le volume est monté, donc le fichier est
 * frais ; opcache sert quand même une image figée. La seule mesure qui vaut vient du processus
 * lui-même.
 *
 * ── POURQUOI CE MARQUEUR NE PEUT PAS MENTIR DANS LE BON SENS ─────────────────────────────────────
 *
 * Le commit est écrit dans un **fichier PHP** que FPM charge comme n'importe quel autre. Si opcache
 * sert du code figé, il sert aussi cette constante figée — l'instrument hérite exactement du défaut
 * qu'il mesure. Un horodatage de démarrage de conteneur, lui, dirait quand FPM a démarré, pas quel
 * code il a chargé : il resterait juste après un redémarrage sans déploiement.
 *
 * ⚠ **Aucun écran ne l'appelle, et c'est voulu.** Elle est consommée par `deploy-preprod.sh`, qui
 * boucle en la comparant à ce qu'il vient de livrer. Une interface n'aurait rien à en faire.
 *
 * @sans-ecran: consommée par le déploiement pour vérifier que FPM a chargé le code livré, jamais par une interface.
 */
#[ApiResource(
    shortName: 'PlatformLoadedBuild',
    operations: [
        new Get(
            uriTemplate: '/plateforme/version-chargee',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: LoadedBuildProvider::class,
        ),
    ],
)]
final class LoadedBuild
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
