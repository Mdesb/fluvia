<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupParticipant;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ajoute un membre à un groupe, en **vérifiant que le groupe visé appartient à l'établissement actif**
 * (RG-SOCLE-05). Sans cette garde, un appelant pourrait rattacher un participant à un groupe d'un
 * autre établissement : `find()` / la désérialisation d'IRI ne garantissent pas à eux seuls le
 * périmètre — l'extension de périmètre ne filtre que les lectures de collection/item, pas ce chemin
 * d'écriture. On refuse plutôt que de deviner.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class CreateGroupParticipantProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof GroupParticipant) {
            $actif = $this->contexte->etablissementActif();
            if ($actif === null) {
                throw new UnprocessableEntityHttpException('Aucun établissement actif (en-tête X-Etablissement).');
            }
            $groupe = $data->getGroup();
            $etabGroupe = $groupe?->getEtablissement();
            if ($etabGroupe === null || !$etabGroupe->getId()->equals($actif->getId())) {
                // Cloisonnement : hors périmètre = introuvable (comme la lecture), jamais 403.
                throw new NotFoundHttpException('Groupe introuvable dans l\'établissement actif.');
            }
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
