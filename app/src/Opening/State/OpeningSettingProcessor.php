<?php

declare(strict_types=1);

namespace App\Opening\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Opening\Entity\OpeningSetting;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cocher — ou décocher — « le planning fait loi » sur l'établissement actif.
 *
 * L'établissement vient du contexte pour la même raison que partout ailleurs : accepter un
 * identifiant du corps laisserait activer le refus hors horaires chez le voisin, c'est-à-dire
 * fermer sa porte à distance.
 *
 * @implements ProcessorInterface<OpeningSetting, OpeningSetting>
 */
final readonly class OpeningSettingProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<OpeningSetting, OpeningSetting> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof OpeningSetting);

        if ($data->getEstablishment() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if (!$etablissement instanceof Etablissement) {
                throw new UnprocessableEntityHttpException('Aucun établissement actif.');
            }
            $data->setEstablishment($etablissement);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
