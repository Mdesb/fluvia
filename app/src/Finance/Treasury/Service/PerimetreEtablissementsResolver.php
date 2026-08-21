<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Liste les établissements où l'utilisateur courant possède effectivement une `Affectation`
 * (RG-SOCLE-05) — même patron que `RosterProvider::etablissementsAutorises()` (`App\Personnel`),
 * factorisé ici pour les quatre vues calculées de Treasury (`TreasuryPositionProvider`,
 * `PaymentScheduleProvider`, `CashflowForecastProvider`, `DiscrepancyDashboardProvider`) qui n'ont pas
 * d'identifiant d'entité à revérifier individuellement (agrégats sur tout le périmètre de l'appelant).
 *
 * Retourne des identifiants **binaires** (`Uuid::toBinary()`), pas des objets `Uuid` : un DQL
 * `IN (:param)` ne fait passer chaque élément par `UuidType::convertToDatabaseValue()` que si le
 * paramètre est bindé avec `Doctrine\DBAL\ArrayParameterType::BINARY` (même patron que
 * `RosterProvider`) — un objet `Uuid` brut dans le tableau ne matcherait silencieusement rien contre une
 * colonne `BINARY(16)`.
 */
final class PerimetreEtablissementsResolver
{
    private const UUID_IMPOSSIBLE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @return list<string> Chaînes binaires (16 octets), non vide (sentinelle impossible si aucune affectation). */
    public function etablissementsAutorises(): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [Uuid::fromString(self::UUID_IMPOSSIBLE)->toBinary()];
        }

        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $utilisateur]);

        $ids = [];
        foreach ($affectations as $affectation) {
            $etablissement = $affectation->getEtablissement();
            if ($etablissement !== null) {
                $ids[(string) $etablissement->getId()] = $etablissement->getId()->toBinary();
            }
        }

        return $ids === [] ? [Uuid::fromString(self::UUID_IMPOSSIBLE)->toBinary()] : array_values($ids);
    }
}
