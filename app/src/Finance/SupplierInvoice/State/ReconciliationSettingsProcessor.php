<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\ReconciliationSettings;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Écriture (Post/Patch) de `ReconciliationSettings` (même patron que `ExpenseAccountMappingProcessor`,
 * FIN-1, §3 point 4 du plan) : `businessProfile` doit être couvert par l'établissement actif — revérifié
 * avant tout `persist`/`flush`, échec fermé (404).
 *
 * @implements ProcessorInterface<ReconciliationSettings, ReconciliationSettings>
 */
final class ReconciliationSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReconciliationSettings
    {
        \assert($data instanceof ReconciliationSettings);

        $etablissementActif = $this->contexte->etablissementActif();
        $profil = $data->getBusinessProfile();

        if ($etablissementActif === null || $profil === null || !$profil->couvre($etablissementActif)) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        if (!isset($uriVariables['id'])) {
            $existant = $this->em->getRepository(ReconciliationSettings::class)->findOneBy(['businessProfile' => $profil->getId()]);
            if ($existant !== null) {
                throw new UnprocessableEntityHttpException('Un réglage de rapprochement existe déjà pour ce profil exploitant.');
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
