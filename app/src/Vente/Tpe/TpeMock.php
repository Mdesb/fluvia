<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation mock du connecteur TPE pour L2. Le résultat par défaut est « accepté » avec un n°
 * de transaction ; l'issue peut être forcée (refuse/annule/timeout) soit par programme via
 * forcerIssue(), soit — pour les tests fonctionnels sans état partagé entre requêtes — via l'en-tête
 * de requête « X-Tpe-Simule » (accepte|refuse|annule|timeout). Permet de vérifier qu'un refus/timeout
 * n'ajoute aucun règlement (CA-10).
 */
final class TpeMock implements TerminalPaiementInterface
{
    public const HEADER_SIMULATION = 'X-Tpe-Simule';

    private ?StatutTPE $issueForcee = null;

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function forcerIssue(?StatutTPE $statut): void
    {
        $this->issueForcee = $statut;
    }

    public function demander(PointDeVente $pointDeVente, string $montant): ResultatTpe
    {
        $statut = $this->issueForcee ?? $this->issueDepuisRequete() ?? StatutTPE::Accepte;

        if ($statut === StatutTPE::Accepte) {
            return new ResultatTpe($statut, 'TPE-' . strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 12)));
        }

        return new ResultatTpe($statut, null);
    }

    private function issueDepuisRequete(): ?StatutTPE
    {
        $requete = $this->requestStack?->getCurrentRequest();
        $valeur = $requete?->headers->get(self::HEADER_SIMULATION);

        return $valeur !== null ? StatutTPE::tryFrom($valeur) : null;
    }
}
