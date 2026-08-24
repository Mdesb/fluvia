<?php

declare(strict_types=1);

namespace App\Social\Port;

use App\Social\Dto\PublicationOutcome;
use App\Social\Dto\PublicationRequest;
use App\Social\Enum\SocialNetwork;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port de publication — un adaptateur par réseau (D14, SOC-2).
 *
 * Le port se justifie ici et pas ailleurs : c'est le seul endroit du module où il existe **plusieurs
 * implémentations réelles** du même geste. Le dépôt fait déjà exactement cela pour le signataire NF525
 * et l'extracteur OCR. Un port sans deuxième implémentation n'est qu'une classe de plus à traverser.
 *
 * Le tag est porté par l'interface (`AutoconfigureTag`) : un adaptateur neuf est enregistré sans une
 * ligne de configuration, et surtout sans toucher `config/services.yaml`, qui appartient à
 * l'intégrateur.
 *
 * **L'adaptateur ne connaît ni Doctrine, ni l'établissement, ni le coffre.** Il reçoit un jeton déjà
 * déchiffré et rend un résultat ou lève. C'est ce qui permet de le tester contre un client HTTP simulé
 * sans base de données — et ce qui garantit qu'un adaptateur ne peut pas, par construction, lire le
 * jeton d'un autre établissement.
 */
#[AutoconfigureTag('social.publisher')]
interface SocialPublisher
{
    public function supports(SocialNetwork $network): bool;

    /**
     * @throws \App\Social\Exception\SocialPublishingException si le réseau refuse ou ne répond pas
     */
    public function publish(PublicationRequest $request): PublicationOutcome;
}
