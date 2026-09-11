<?php

declare(strict_types=1);

namespace App\Membership\Recouvrement;

use App\Crm\Recouvrement\ClientDebtorName;
use App\Recouvrement\Port\DebtorNamePort;
use App\Membership\Entity\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le nom d'un redevable de type `sport.abonnement_fitness` — la référence est l'UUID du contrat.
 *
 * ⚠ ON REND LE PAYEUR, PAS L'ADHÉRENT, ET LA DIFFÉRENCE EST TOUT L'OBJET DE L'ÉCRAN. Un impayé se
 * réclame à celui qui est prélevé. Un parent règle pour son enfant : afficher l'enfant enverrait
 * l'agent réclamer à un mineur, et le nom ne correspondrait à aucun mouvement bancaire.
 *
 * L'adhérent reste utile ailleurs — c'est lui qu'on refuse à la porte — mais pas ici.
 */
final class FitnessSubscriptionDebtorName implements DebtorNamePort
{
    public const TYPE = AbonnementFitnessRedevablePort::TYPE;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function debtorType(): string
    {
        return self::TYPE;
    }

    public function debtorName(string $debtorReference): ?string
    {
        if (!Uuid::isValid($debtorReference)) {
            return null;
        }

        $abonnement = $this->em->getRepository(Membership::class)->find(Uuid::fromString($debtorReference));
        if (!$abonnement instanceof Membership) {
            return null;
        }

        $payeur = $abonnement->getPayeur();

        return $payeur === null ? null : ClientDebtorName::nameOf($payeur);
    }
}
