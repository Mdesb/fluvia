<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBookingItem;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ajoute un article « à la carte » au panier d'une réservation, en **vérifiant que la réservation
 * appartient à l'établissement actif** (RG-SOCLE-05) : `find()` / la désérialisation d'IRI ne passent
 * pas par l'extension de périmètre sur ce chemin d'écriture.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class CreateGroupBookingItemProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof GroupBookingItem) {
            $actif = $this->contexte->etablissementActif();
            if ($actif === null) {
                throw new UnprocessableEntityHttpException('Aucun établissement actif (en-tête X-Etablissement).');
            }
            $etabBooking = $data->getBooking()?->getEtablissement();
            if ($etabBooking === null || !$etabBooking->getId()->equals($actif->getId())) {
                throw new NotFoundHttpException('Réservation introuvable dans l\'établissement actif.');
            }
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
