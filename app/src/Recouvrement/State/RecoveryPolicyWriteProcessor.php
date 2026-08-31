<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Création/modification de `PolitiqueRecouvrement` (D3/D8/D41) : `etablissement` n'est plus dans le
 * groupe d'écriture (voir l'entité), donc jamais désormais posé depuis le corps de la requête client.
 * À la création, il est résolu depuis le contexte établissement actif (même pattern que
 * `App\Crm\State\ClientEcritureProcessor`) — un client ne peut donc plus rattacher une politique à un
 * autre établissement que celui de son périmètre serveur. À la modification, l'entité chargée porte
 * déjà son `etablissement` d'origine : n'étant plus dénormalisé depuis le corps, il ne peut pas être
 * changé.
 *
 * @implements ProcessorInterface<PolitiqueRecouvrement, PolitiqueRecouvrement>
 */
final class RecoveryPolicyWriteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<PolitiqueRecouvrement, PolitiqueRecouvrement> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof PolitiqueRecouvrement);

        if ($data->getEtablissement() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if (!$etablissement instanceof Etablissement) {
                // Échec fermé (D3/D8) : à la création sans établissement actif (en-tête X-Etablissement
                // absent/invalide), on refuse proprement plutôt que de laisser un `null` provoquer une
                // 500 sur la contrainte DB.
                throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
            }
            $data->setEtablissement($etablissement);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
