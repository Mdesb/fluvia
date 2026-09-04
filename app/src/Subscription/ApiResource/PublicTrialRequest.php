<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\RequestTrialProcessor;

/**
 * La demande d'essai gratuit — deuxième étape du tunnel sans paiement (ED-5).
 *
 * **Séparée de l'ouverture du panier, et pas par goût du découpage.** Le panier rend un prix
 * **calculé par le serveur** ; le prospect doit pouvoir le lire avant de s'engager. Fondre les deux
 * appels en un ferait valider une composition dont il n'a vu que le total que sa propre page avait
 * additionné — et c'est la manière la plus sûre de perdre sa confiance au premier prélèvement.
 *
 * **@sans-suppression: cette opération ne crée aucune ligne.** Elle publie un fait, et l'abonné
 * pose une empreinte de jeton sur un abonnement qui existait déjà. Rien n'apparaît en base qu'un
 * `Delete` pourrait retirer ; ce qui se défait — l'abonnement lui-même, la fiche prospect — se défait
 * là où il a été créé, à l'étape du panier.
 *
 * **Ce que la réponse ne contient pas.** Ni jeton, ni lien de confirmation. Le secret ne circule que
 * par le courriel : le rendre ici permettrait de confirmer une adresse qu'on ne lit pas, ce qui
 * annulerait la seule garde de ce tunnel.
 */
#[ApiResource(
    shortName: 'PublicTrialRequest',
    operations: [
        new Post(
            uriTemplate: '/editor/trial-requests',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: RequestTrialProcessor::class,
        ),
    ],
)]
final class PublicTrialRequest
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** L'adresse à laquelle le courriel de confirmation vient de partir, partiellement masquée. */
    public string $maskedEmail = '';

    /** Nombre d'heures pendant lesquelles le lien reste valable — la page le dit au visiteur. */
    public int $confirmationHours = 0;
}
