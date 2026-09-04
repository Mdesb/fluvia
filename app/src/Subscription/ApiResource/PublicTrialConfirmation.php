<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\ConfirmTrialProcessor;

/**
 * La confirmation d'adresse qui ouvre l'essai — troisième étape du tunnel sans paiement (ED-5).
 *
 * **Un POST, alors que le visiteur arrive par un lien.** Le lien du courriel ouvre une page de la
 * vitrine, et c'est elle qui appelle cette route. Faire ouvrir l'établissement par le `GET` du lien
 * lui-même mettrait un effet de bord irréversible derrière une requête que les antivirus, les
 * pré-visualiseurs de messagerie et les robots d'indexation déclenchent sans personne devant l'écran.
 *
 * **Idempotente** : rejouer la même confirmation rend le même résultat sans rien recréer. Voir
 * {@see \App\Subscription\Service\SubscriptionFunnel::confirmEmailAndStartTrial()}.
 *
 * ⚠ **@sans-suppression: défaire est une résiliation, et une résiliation n'est jamais publique.**
 *
 * Cette opération crée bel et bien, et lourdement : un groupe, une région, un établissement, un
 * compte administrateur. Exposer le `Delete` symétrique donnerait à une route publique le pouvoir de
 * détruire un locataire entier — exactement ce qu'un `Delete` est censé rendre sûr, et qui le rend
 * ici catastrophique.
 *
 * Ce qui défait un essai existe, et vit ailleurs : `subscription:trials:expire` le **suspend** à
 * l'échéance (RG-ED-06, aucune donnée supprimée), et la résiliation appartient à l'éditeur, sur son
 * écran, avec son autorisation. L'absence de `Delete` ici n'est pas un oubli : c'est le refus de
 * mettre une destruction irréversible derrière une route que personne n'authentifie.
 */
#[ApiResource(
    shortName: 'PublicTrialConfirmation',
    operations: [
        new Post(
            uriTemplate: '/editor/trial-confirmations',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: ConfirmTrialProcessor::class,
        ),
    ],
)]
final class PublicTrialConfirmation
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** Le nom de la structure, tel qu'il a été saisi — la page confirme au visiteur ce qui a été ouvert. */
    public string $companyName = '';

    /** Fin de l'essai, en ISO 8601. La page l'affiche : un essai dont on ignore le terme inquiète. */
    public string $trialEndsAt = '';
}
