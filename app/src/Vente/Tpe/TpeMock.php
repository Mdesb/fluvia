<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Vente\Enum\StatutTPE;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Le terminal SIMULÉ (connecteur « simule »), derrière le même port asynchrone que les vrais. Il
 * répond aussitôt, par le chemin de l'issue ({@see TerminalOutcomeSink}), comme un connecteur réel le
 * ferait plus tard. L'issue est programmable :
 * - par programme : `programmer()` (un `ResultatTpe` complet : partiel, ticket…) ou `forcerIssue()` ;
 * - par l'en-tête « X-Tpe-Simule » : accepte | refuse | annule | timeout | attente — « attente » ne
 *   répond pas, la tentative reste `sent` et l'issue se livre ensuite par le chemin de l'issue.
 *
 * ⚠ REFUSE TANT QUE `TPE_SIMULE_AUTORISE` N'EST PAS POSÉ (décision de Maxime du 07/10). Câblé partout,
 * il acceptait toute carte sans terminal, et l'en-tête laissait n'importe quel appelant choisir
 * l'issue. Posé en test (`.env.test`) et pour la démonstration en préprod ; le défaut est le refus.
 * Un drapeau et non `APP_ENV`, comme `SEPA_TRANSMISSION_SIMULEE` : la préprod tourne sous `prod`.
 *
 * 422 et non 503 (la règle des ports non raccordés) : en `prod`, API Platform remplace le détail de
 * toute erreur 5xx par « Internal Server Error », et le caissier ne saurait pas qu'il doit encaisser
 * autrement. Un 4xx dit aussi que rien n'est parti au terminal (contrat du port).
 */
final class TpeMock implements TerminalPaiementInterface
{
    public const HEADER_SIMULATION = 'X-Tpe-Simule';
    public const CONNECTOR = 'simule';
    /** L'issue « pas encore de réponse » de l'en-tête. */
    public const NO_ANSWER = 'attente';

    private ?ResultatTpe $programme = null;

    /** @var list<string> les identifiants des tentatives envoyées, puis annulées (préfixe « annuler: ») */
    private array $journal = [];

    public function __construct(
        private readonly TerminalOutcomeSink $issues,
        private readonly ?RequestStack $requestStack = null,
        #[Autowire('%env(bool:TPE_SIMULE_AUTORISE)%')]
        private readonly bool $simulationAutorisee = false,
    ) {
    }

    public function connector(): string
    {
        return self::CONNECTOR;
    }

    /** Deux fois le `proxy_read_timeout` de nginx : la valeur d'avant le port asynchrone, que les tests de retard connaissent. */
    public function staleAfterSeconds(): int
    {
        return 120;
    }

    /** L'issue des prochaines demandes ; nulle = celle de l'en-tête, sinon « accepte ». */
    public function programmer(?ResultatTpe $resultat): void
    {
        $this->programme = $resultat;
    }

    public function forcerIssue(?StatutTPE $statut): void
    {
        $this->programme = $statut !== null ? new ResultatTpe($statut) : null;
    }

    /** @return list<string> */
    public function journal(): array
    {
        return $this->journal;
    }

    public function demarrer(TerminalAttempt $tentative): void
    {
        if (!$this->simulationAutorisee) {
            throw new UnprocessableEntityHttpException(
                'Aucun terminal de paiement configuré : la carte n\'a pas été débitée et aucun règlement '
                . 'n\'est enregistré. Encaissez par un autre moyen.'
            );
        }
        $this->journal[] = $tentative->id;

        $enTete = $this->requestStack?->getCurrentRequest()?->headers->get(self::HEADER_SIMULATION);
        if ($this->programme === null && $enTete === self::NO_ANSWER) {
            return;
        }
        $resultat = $this->programme ?? new ResultatTpe(($enTete !== null ? StatutTPE::tryFrom($enTete) : null) ?? StatutTPE::Accepte);
        if ($resultat->estAccepte() && $resultat->reference === null) {
            $resultat = new ResultatTpe(
                StatutTPE::Accepte,
                'TPE-' . strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 12)),
                $resultat->montantDebite,
                $resultat->numeroAutorisation,
                $resultat->transactionTerminal,
                $resultat->identifiantTerminal,
                $resultat->reseau,
                $resultat->ticketClient,
                $resultat->ticketCommercant,
            );
        }

        $this->issues->record($tentative->id, $resultat);
    }

    public function annuler(TerminalAttempt $tentative): void
    {
        $this->journal[] = 'annuler:' . $tentative->id;
    }
}
