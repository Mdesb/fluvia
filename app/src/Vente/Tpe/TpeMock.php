<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation mock du connecteur TPE pour L2. Le résultat par défaut est « accepté » avec un n°
 * de transaction ; l'issue peut être forcée (refuse/annule/timeout) soit par programme via
 * forcerIssue(), soit — pour les tests fonctionnels sans état partagé entre requêtes — via l'en-tête
 * de requête « X-Tpe-Simule » (accepte|refuse|annule|timeout). Permet de vérifier qu'un refus/timeout
 * n'ajoute aucun règlement (CA-10).
 *
 * ⚠ REFUSE TANT QUE `TPE_SIMULE_AUTORISE` N'EST PAS POSÉ (décision de Maxime du 07/10). Câblé partout,
 * il acceptait toute carte sans terminal, et l'en-tête laissait n'importe quel appelant choisir
 * l'issue. Posé en test (`.env.test`) et pour la démonstration en préprod ; le défaut est le refus.
 * Un drapeau et non `APP_ENV`, comme `SEPA_TRANSMISSION_SIMULEE` : la préprod tourne sous `prod`.
 *
 * 422 et non 503 (la règle des ports non raccordés) : en `prod`, API Platform remplace le détail de
 * toute erreur 5xx par « Internal Server Error », et le caissier ne saurait pas qu'il doit encaisser
 * autrement. C'est un moyen impossible ici, comme ses voisins de `PaiementHandler`.
 */
final class TpeMock implements TerminalPaiementInterface
{
    public const HEADER_SIMULATION = 'X-Tpe-Simule';

    private ?StatutTPE $issueForcee = null;

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        #[Autowire('%env(bool:TPE_SIMULE_AUTORISE)%')]
        private readonly bool $simulationAutorisee = false,
    ) {
    }

    public function forcerIssue(?StatutTPE $statut): void
    {
        $this->issueForcee = $statut;
    }

    public function demander(PointDeVente $pointDeVente, string $montant): ResultatTpe
    {
        if (!$this->simulationAutorisee) {
            throw new UnprocessableEntityHttpException(
                'Aucun terminal de paiement configuré : la carte n\'a pas été débitée et aucun règlement '
                . 'n\'est enregistré. Encaissez par un autre moyen.'
            );
        }

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
