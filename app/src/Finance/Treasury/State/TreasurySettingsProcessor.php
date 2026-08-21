<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/api/treasury_settings` (§0.2 point 4 du plan, D8 explicite) : `establishment` du corps
 * revérifié via `PerimetreEtablissementVerificateur` (403 sinon) — un seul réglage par établissement.
 *
 * @implements ProcessorInterface<TreasurySettings, TreasurySettings>
 */
final class TreasurySettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TreasurySettings
    {
        \assert($data instanceof TreasurySettings);

        $etablissement = $this->perimetre->verifier($data->getEstablishment());

        if (!isset($uriVariables['id'])) {
            $existant = $this->em->getRepository(TreasurySettings::class)->findOneBy(['establishment' => $etablissement->getId()]);
            if ($existant !== null) {
                throw new UnprocessableEntityHttpException('Un réglage de trésorerie existe déjà pour cet établissement.');
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
