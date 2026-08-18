<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\FactureDirecteBuilder;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * PATCH /factures/{id} (US-FACT-02, RG-FACT-04) : modification libre d'un brouillon (destinataire,
 * lignes, échéance, conditions) — **tant que brouillon uniquement**. Une facture déjà émise est
 * inaltérable (rejetée ici explicitement, en plus de la garde `FactureInalterableListener`).
 *
 * @implements ProcessorInterface<Facture, Facture>
 */
final class ModifierFactureDirecteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly FactureDirecteBuilder $builder,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        \assert($data instanceof Facture);

        if (!$data->estBrouillon()) {
            throw new ConflictHttpException('Modification interdite : facture déjà émise, donc inaltérable (NF525). Corriger par un avoir.');
        }

        $corps = $this->lecteur->corps();

        if (isset($corps['destinataire']) && \is_array($corps['destinataire'])) {
            $this->builder->appliquerDestinataire($data, $corps['destinataire']);
        }
        if (isset($corps['lignes'])) {
            $this->builder->appliquerLignes($data, $corps);
        }
        if (\is_string($corps['dateEcheance'] ?? null)) {
            $data->setDateEcheance(new \DateTimeImmutable($corps['dateEcheance']));
        }
        if (\is_string($corps['conditionsReglement'] ?? null)) {
            $data->setConditionsReglement($corps['conditionsReglement']);
        }

        $this->em->flush();

        return $data;
    }
}
