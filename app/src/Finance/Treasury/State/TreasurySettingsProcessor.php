<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/api/treasury_settings` (§0.2 point 4 du plan, D8 explicite) : `establishment` du corps
 * revérifié via `PerimetreEtablissementVerificateur` (403 sinon) — un seul réglage par établissement.
 *
 * Addendum FIN-4 (alertes de trésorerie proactives, §0.6 de `plan-treasury-cash-alerts.md`) : un
 * `PATCH` qui pose `cashAlertThresholdCents = null` (désactivation du seuil) résout **silencieusement**
 * toute `TreasuryCashAlert` `open` de cet établissement — sinon une alerte resterait `open`
 * indéfiniment, orpheline d'un seuil qui n'existe plus, et `finance:treasury:verifier-seuils` ne la
 * reverrait jamais puisqu'elle ignore les établissements sans seuil configuré. **Aucun** événement
 * (§2 « exclu » de la spec — annoncer la résorption n'est pas demandé).
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

        if ($data->getCashAlertThresholdCents() === null) {
            $maintenant = new \DateTimeImmutable();
            $ouvertes = $this->em->getRepository(TreasuryCashAlert::class)->findBy([
                'establishment' => $etablissement->getId(),
                'status' => CashAlertStatus::Open,
            ]);
            foreach ($ouvertes as $alerte) {
                $alerte->setStatus(CashAlertStatus::Resolved)->setResolvedAt($maintenant);
                $this->em->persist($alerte);
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
