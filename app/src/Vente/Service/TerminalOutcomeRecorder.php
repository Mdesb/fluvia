<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Platform\Event\EventBus;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\PaymentAttemptStatus as Status;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalOutcomeSink;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'issue d'un terminal, quand elle arrive (spec TPE lot 1 §1 — « enregistrerIssue »). Voir
 * {@see TerminalOutcomeSink} pour le contrat ; ici, ce qu'il en coûte de le tenir.
 *
 * - Acceptée : un règlement du montant DÉBITÉ (CA-4), avec la clé de la tentative, dans la même
 *   transaction que le passage à `accepted`. Depuis `pending`, `sent`, `unresolved` — et `cancelled` :
 *   une acceptation arrivée après « Annuler » l'emporte, le client a été débité (CA-3). La vente est
 *   relue avant : scellée, rien ne s'écrit (NF525) et la tentative garde ce que le terminal a dit.
 *   ⚠ Après une annulation, la vente a été libérée : si un autre règlement la tient ou l'a soldée
 *   depuis, écrire l'acceptation la réglerait DEUX FOIS. Rien ne s'écrit alors, la tentative reste
 *   `cancelled` et dit « client débité sans règlement, à rembourser » — ni double règlement, ni perte
 *   silencieuse.
 * - Refusée ou annulée par le terminal : la trace du refus (PAY-3) et le statut, ensemble ; l'annonce
 *   part après le commit (D7-bis).
 * - Timeout : rien n'est su — `unresolved`, la vente reste tenue jusqu'à la déclaration (Q-A1).
 *
 * Un montant débité nul, illisible ou SUPÉRIEUR au montant demandé n'est pas un règlement qu'on
 * écrit : la tentative devient `unresolved`, et le caissier déclare ce qu'il lit sur le terminal.
 *
 * @phpstan-import-type Attempt from PaymentAttemptStore
 */
final class TerminalOutcomeRecorder implements TerminalOutcomeSink
{
    /** Les statuts d'où une acceptation écrit son règlement. */
    private const ACCEPTABLE = [Status::Pending, Status::Sent, Status::Unresolved, Status::Cancelled];

    public function __construct(
        private readonly PaymentAttemptStore $attempts,
        private readonly PanierCalculateur $calculateur,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly EventBus $bus,
        private readonly CardRejectionRecorder $refusCarte,
    ) {
    }

    public function record(string $attemptId, ResultatTpe $resultat, ?Uuid $declaredBy = null): ?array
    {
        $tentative = $this->attempts->byId($attemptId);
        if ($tentative === null) {
            return null;
        }

        match (true) {
            $resultat->estAccepte() => $this->accepted($tentative, $resultat, $declaredBy),
            $resultat->statut === StatutTPE::Timeout => $this->ended($tentative, $resultat, Status::Unresolved, [Status::Pending, Status::Sent], $declaredBy),
            default => $this->ended(
                $tentative,
                $resultat,
                $resultat->statut === StatutTPE::Annule ? Status::Cancelled : Status::Refused,
                [Status::Pending, Status::Sent, Status::Unresolved],
                $declaredBy,
            ),
        };

        return $this->attempts->byId($attemptId);
    }

    /** @param Attempt $tentative */
    private function accepted(array $tentative, ResultatTpe $resultat, ?Uuid $par): void
    {
        $id = $tentative['id'];
        $reference = self::reference($resultat);
        if (!\in_array($tentative['status'], self::ACCEPTABLE, true)) {
            $this->late($tentative, $resultat);

            return;
        }
        $debite = $this->debited($tentative, $resultat);
        if ($debite === null) {
            $raison = sprintf('Montant débité incohérent (%s € pour %s € demandés) : déclarez ce qu\'affiche le terminal.', $resultat->montantDebite ?? '?', $tentative['amount']);
            if (!$this->attempts->move($id, [Status::Pending, Status::Sent, Status::Unresolved], Status::Unresolved, StatutTPE::Accepte, $raison)) {
                $this->attempts->note($id, $raison);
            }

            return;
        }

        $vente = $this->em->find(Vente::class, Uuid::fromBinary((string) hex2bin($tentative['sale'])));
        if (!$vente instanceof Vente) {
            return;
        }
        $this->em->refresh($vente);
        // Seule une tentative annulée peut trouver sa vente scellée : tenue, la vente ne se valide pas.
        if ($vente->estScellee()) {
            $this->attempts->note($id, sprintf('Accepté par le terminal (réf. %s) après la validation de la vente : client débité sans règlement, à rembourser.', $reference ?? '—'));

            return;
        }
        $apresAnnulation = $tentative['status'] === Status::Cancelled;

        try {
            $ecrit = $this->connection->transactional(function () use ($tentative, $vente, $resultat, $debite, $reference, $apresAnnulation, $par): bool {
                // Annulée, la vente a été libérée : un autre règlement a pu la tenir ou la solder depuis.
                // L'écrire alors réglerait deux fois la même vente — la carte ET ce qui l'a remplacée.
                // La lecture verrouillante sérialise avec une tentative qui s'ouvrirait (index unique).
                if ($apresAnnulation) {
                    $this->em->refresh($vente);
                    if ($this->attempts->holding($vente, true) !== null
                        || $this->calculateur->centimes($debite) > $this->calculateur->centimes($vente->getResteAPayer())) {
                        return false;
                    }
                }
                $paiement = new Paiement();
                $paiement->setMoyenCode($tentative['method']);
                $paiement->setMontant($debite);
                $paiement->setRendu('0.00');
                $paiement->setStatutTPE(StatutTPE::Accepte);
                $paiement->setRefTPE($reference);
                $paiement->setCleIdempotence($tentative['key']);
                $vente->addPaiement($paiement);
                $this->calculateur->recalculerVente($vente);
                $this->em->flush();
                $raison = $apresAnnulation ? sprintf('Accepté par le terminal (réf. %s) après l\'annulation : le règlement est écrit.', $reference ?? '—') : null;
                if (!$this->attempts->move($tentative['id'], self::ACCEPTABLE, Status::Accepted, StatutTPE::Accepte, $raison, $paiement->getId())) {
                    throw PaymentAttemptConflict::outcomeKnown($this->attempts->byId($tentative['id']) ?? $tentative);
                }
                $this->attempts->setTerminalDetails($tentative['id'], $debite, $resultat, $par);

                return true;
            });
            // La même acceptation livrée deux fois ne note qu'une fois.
            if (!$ecrit && !str_contains((string) ($this->attempts->byId($id)['reason'] ?? ''), 'à rembourser')) {
                $this->attempts->setTerminalDetails($id, $debite, $resultat, $par);
                $this->attempts->note($id, sprintf(
                    'Accepté par le terminal (réf. %s) après l\'annulation, la vente étant réglée ou en cours de règlement autrement : client débité de %s € sans règlement, à rembourser.',
                    $reference ?? '—',
                    str_replace('.', ',', $debite), // sans point : la phrase se montre jusqu'au sien
                ));
            }
        } catch (PaymentAttemptConflict|UniqueConstraintViolationException|RetryableException) {
            // Un autre chemin (déclaration, même issue livrée deux fois) l'a emporté juste avant : il tient.
            $this->late($this->attempts->byId($id) ?? $tentative, $resultat);
        } catch (\Throwable $e) {
            // Le terminal a accepté, l'écriture a échoué : la carte est débitée sans règlement écrit. Il
            // faudra une déclaration — la raison garde la référence, pour qu'elle n'ait pas à être devinée.
            $raison = sprintf('Accepté par le terminal (réf. %s), règlement non écrit : ', $reference ?? '—') . $e->getMessage();
            if (!$this->attempts->move($id, [Status::Pending, Status::Sent, Status::Unresolved], Status::Unresolved, StatutTPE::Accepte, $raison)) {
                $this->attempts->note($id, $raison);
            }

            throw $e;
        }
    }

    /**
     * Refus, annulation ou timeout du terminal : le statut et la trace du refus, dans une transaction.
     *
     * @param Attempt      $tentative
     * @param list<Status> $depuis
     */
    private function ended(array $tentative, ResultatTpe $resultat, Status $vers, array $depuis, ?Uuid $par): void
    {
        $evenements = new SettlementEvents();
        try {
            $bouge = $this->connection->transactional(function () use ($tentative, $resultat, $vers, $depuis, $par, $evenements): bool {
                if (!$this->attempts->move($tentative['id'], $depuis, $vers, $resultat->statut)) {
                    return false;
                }
                $this->attempts->setTerminalDetails($tentative['id'], null, $resultat, $par);
                $vente = $this->em->find(Vente::class, Uuid::fromBinary((string) hex2bin($tentative['sale'])));
                if ($vente instanceof Vente) {
                    $this->refusCarte->record($vente, $tentative['method'], $tentative['amount'], $resultat->statut, self::reference($resultat), $evenements);
                    $this->em->flush();
                }

                return true;
            });
        } catch (\Throwable $e) {
            // Le terminal a refusé : aucun argent n'a bougé, seule la trace du refus n'a pas pu s'écrire.
            $this->attempts->move($tentative['id'], $depuis, $vers, $resultat->statut, $e->getMessage());

            throw $e;
        }

        if (!$bouge) {
            $this->late($this->attempts->byId($tentative['id']) ?? $tentative, $resultat);

            return;
        }
        $evenements->publishTo($this->bus);
    }

    /**
     * Arrivée après son issue : celle-ci tient. Si le terminal la contredit — accepté après « non
     * passé » (client débité sans règlement), refusé après « accepté » (règlement sans débit) —, sa
     * réponse reste écrite sur la tentative, pour le rapprochement. La même issue livrée deux fois ne
     * note rien.
     *
     * @param Attempt $tentative
     */
    private function late(array $tentative, ResultatTpe $resultat): void
    {
        $meme = match ($resultat->statut) {
            StatutTPE::Accepte => $tentative['status'] === Status::Accepted,
            StatutTPE::Annule => $tentative['status'] === Status::Cancelled,
            StatutTPE::Refuse => $tentative['status'] === Status::Refused,
            StatutTPE::Timeout => $tentative['status'] === Status::Unresolved,
        };
        if ($meme) {
            return;
        }
        $reference = self::reference($resultat);
        $this->attempts->note($tentative['id'], sprintf(
            '%s, le terminal a répondu « %s »%s',
            \in_array($tentative['status'], [Status::DeclaredAccepted, Status::DeclaredNotProcessed], true)
                ? 'Après la déclaration du caissier' : sprintf('Après son issue (%s)', $tentative['status']->label()),
            $resultat->statut->value,
            $reference !== null ? sprintf(' (réf. %s)', $reference) : '',
        ));
    }

    /**
     * Le montant à régler : celui débité, sinon celui demandé ; nul s'il est illisible, nul ou supérieur au demandé.
     *
     * @param Attempt $tentative
     */
    private function debited(array $tentative, ResultatTpe $resultat): ?string
    {
        if ($resultat->montantDebite === null) {
            return $tentative['amount'];
        }
        if (!is_numeric($resultat->montantDebite)) {
            return null;
        }
        $centimes = $this->calculateur->centimes(number_format((float) $resultat->montantDebite, 2, '.', ''));

        return $centimes > 0 && $centimes <= $this->calculateur->centimes($tentative['amount']) ? $this->calculateur->decimal($centimes) : null;
    }

    private static function reference(ResultatTpe $resultat): ?string
    {
        $reference = $resultat->reference ?? $resultat->transactionTerminal ?? $resultat->numeroAutorisation;

        return $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 64) : null;
    }
}
