<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Platform\Event\EventBus;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\PaymentAttemptStatus as Status;
use App\Vente\Enum\StatutTPE;
use App\Vente\Port\ReferentielReglementInterface;
use App\Vente\Tpe\TerminalAttempt;
use App\Vente\Tpe\TerminalPaiementInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\MaxUuid;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;

/**
 * Encaisse un règlement de l'écran (`POST /ventes/{id}/paiements`) par une TENTATIVE écrite avant
 * l'effet (G-3, G-5, G-6 du ticket opposable ; D-4, D-5 du plan).
 *
 *  1. Une clé qui a déjà sa tentative rend son issue, sans terminal ni porte-monnaie.
 *  2. Sinon, une tentative `pending` est validée en base ; elle tient la vente, et une seconde demande
 *     reçoit « en cours » (409 `payment_in_progress`). Un appel sans clé en reçoit une du serveur : il
 *     est sérialisé comme les autres, il n'est simplement pas rejouable.
 *  3. La vente tenue est relue en base (`refresh`) : un règlement validé entre la lecture de la requête
 *     et la prise de la vente compte dans le reste dû.
 *  4. Sans terminal : débit du porte-monnaie, règlement et clôture de la tentative dans UNE transaction.
 *     Avec terminal : la demande part HORS transaction — aucun verrou tenu pendant l'attente (C-9) — et
 *     rend la main tout de suite (port asynchrone, spec TPE lot 1) : la tentative passe `sent`. L'issue
 *     arrive plus tard par `TerminalOutcomeSink`, qui écrit le règlement ou la trace du refus.
 *  5. Les événements partent après le commit (D7-bis).
 *
 * Un terminal muet au-delà du délai de son connecteur laisse la tentative `unresolved` : la vente reste
 * tenue, rien n'y repart au terminal, et la seule sortie est la déclaration du caissier ({@see declare()},
 * Q-A1, D122). Pendant l'attente, l'écran la scrute ({@see judged()}) et « Annuler » la libère ({@see cancel()}).
 *
 * La synchronisation hors ligne et le no-show appellent `PaiementHandler::encaisser()` directement,
 * sans tentative : la première est inchangée (Q-A2) ; le no-show se sérialise sur sa facturation, dans
 * une seule transaction (`DebitPmvStrategie`, lot 4).
 *
 * @phpstan-import-type Attempt from PaymentAttemptStore
 */
final class SettlementCoordinator
{
    /**
     * Une tentative `pending` — la demande qui l'a écrite est en cours — au-delà de deux fois le
     * `proxy_read_timeout` de nginx (60 s) : cette demande n'attend plus personne. Une tentative `sent`
     * se juge au délai de son connecteur ({@see TerminalPaiementInterface::staleAfterSeconds()}).
     */
    public const STALE_AFTER_SECONDS = 120;

    public function __construct(
        private readonly PaiementHandler $handler,
        private readonly PaymentAttemptStore $attempts,
        private readonly ReferentielReglementInterface $referentiel,
        private readonly PanierCalculateur $calculateur,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly EventBus $bus,
        private readonly TerminalPaiementInterface $terminal,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     *
     * @throws PaymentAttemptConflict une autre tentative tient la vente
     */
    public function settle(Vente $vente, array $donnees): array
    {
        // L'`id` fourni vaut clé (D-3) : à défaut de clé, il en tient lieu pour la tentative — sauf nul
        // ou max, admis comme `id` (lot 1) mais pas comme clé : constants, ils ne désignent aucune demande.
        $cleFournie = $this->handler->idempotencyKey($donnees);
        $id = $this->handler->providedId($donnees);
        $cle = $cleFournie ?? ($id instanceof NilUuid || $id instanceof MaxUuid ? null : $id);
        $tentative = $cle !== null ? $this->attempts->byKey($cle) : null;
        if ($tentative === null) {
            // Un règlement écrit avant ce lot (sans tentative) : la garde du lot 1.
            $deja = $this->handler->replay($vente, $donnees, $cleFournie);
            if ($deja !== null) {
                return self::replayed($deja);
            }

            $cle ??= Uuid::v4();
            $moyen = \is_string($donnees['moyen'] ?? null) ? $donnees['moyen'] : '';
            $centimes = $this->handler->requestedAmountCents($vente, $donnees);
            // Au-delà de ce que tient `NUMERIC(10, 2)`, l'écriture de la tentative tomberait en 500.
            if (abs($centimes) >= 10_000_000_000) {
                throw new UnprocessableEntityHttpException('Montant du règlement hors limites.');
            }
            $montant = $this->calculateur->decimal($centimes);
            $this->releaseIfStale($this->attempts->holding($vente));
            try {
                $tentative = $this->attempts->open(
                    $vente,
                    $cle,
                    mb_substr($moyen, 0, 32),
                    isset($donnees['montant']) ? $montant : null,
                    $montant,
                    $this->referentiel->moyen($moyen)?->exigeReference ?? false,
                );
            } catch (UniqueConstraintViolationException) {
                // La même clé, écrite un instant plus tôt par une autre demande — sinon une autre tient la vente.
                return $this->resume($this->attempts->byKey($cle) ?? throw $this->busy($vente), $vente, $donnees);
            }

            return $this->run($tentative, $vente, $donnees);
        }

        return $this->resume($tentative, $vente, $donnees);
    }

    /**
     * La déclaration du caissier sur une tentative sans issue connue (G-6, Q-A1, D122), seule sortie
     * d'un terminal muet. « accepte » écrit le règlement avec la clé et le montant de la tentative et
     * la référence du ticket CB, SANS terminal ; « non_passe » libère la vente. Qui, quand et quelle
     * tentative restent écrits sur la tentative. Rejouée à l'identique, elle rend son issue.
     *
     * Une réponse tardive du terminal et une déclaration se disputent la même tentative : chacune ne la
     * fait passer que depuis `unresolved`, et l'index unique de la clé du règlement n'en laisse écrire
     * qu'un. La perdante n'écrit rien.
     *
     * @param array<string, mixed> $donnees { tentative, issue: accepte|non_passe, referenceCarte? }
     *
     * @return array{attempt: Attempt, status: Status, paiement: Paiement|null, dejaEnregistre: bool}
     *
     * @throws PaymentAttemptConflict la tentative est encore en cours, ou a trouvé son issue autrement
     */
    public function declare(Vente $vente, array $donnees, Utilisateur $par): array
    {
        $issue = match ($donnees['issue'] ?? null) {
            'accepte' => Status::DeclaredAccepted,
            'non_passe' => Status::DeclaredNotProcessed,
            default => throw new UnprocessableEntityHttpException('issue vaut « accepte » ou « non_passe » : ce qu\'affiche le terminal.'),
        };
        $reference = \is_string($donnees['referenceCarte'] ?? null) ? trim($donnees['referenceCarte']) : '';
        if ($issue === Status::DeclaredAccepted && ($reference === '' || mb_strlen($reference) > 64)) {
            throw new UnprocessableEntityHttpException('« Accepté » exige la référence du ticket CB imprimé par le terminal (64 caractères au plus).');
        }
        $tentative = $this->attemptOnSale($vente, $donnees['tentative'] ?? null)
            ?? throw new UnprocessableEntityHttpException('Aucune tentative de règlement de cet identifiant sur cette vente.');
        $id = $tentative['id'];

        $statut = $this->releaseIfStale($tentative);
        // Rejouée à l'identique : son issue. Une autre référence CB n'est pas « la même » déclaration.
        if ($statut === $issue && ($issue === Status::DeclaredNotProcessed || $tentative['cardReference'] === $reference)) {
            $paiement = $issue === Status::DeclaredAccepted ? $this->attempts->paymentWithKey($tentative['key']) : null;

            return ['attempt' => $tentative, 'status' => $issue, 'paiement' => $paiement !== null ? $this->em->find(Paiement::class, $paiement) : null, 'dejaEnregistre' => true];
        }
        if ($statut !== Status::Unresolved) {
            throw $statut === Status::Pending || $statut === Status::Sent
                ? PaymentAttemptConflict::inProgress($tentative) : PaymentAttemptConflict::outcomeKnown($this->attempts->byId($id) ?? $tentative);
        }
        if ($issue === Status::DeclaredNotProcessed) {
            if (!$this->attempts->declare($id, $issue, $par->getId(), null, null)) {
                throw PaymentAttemptConflict::outcomeKnown($this->attempts->byId($id) ?? $tentative);
            }

            return ['attempt' => $tentative, 'status' => $issue, 'paiement' => null, 'dejaEnregistre' => false];
        }

        $this->em->refresh($vente);
        if ($vente->estScellee()) {
            throw new ConflictHttpException('Vente validée : encaissement clos (NF525).');
        }
        try {
            $paiement = $this->connection->transactional(function () use ($vente, $tentative, $id, $par, $reference): Paiement {
                $paiement = new Paiement();
                $paiement->setMoyenCode($tentative['method']);
                $paiement->setMontant($tentative['amount']);
                $paiement->setRendu('0.00');
                $paiement->setStatutTPE(StatutTPE::Accepte);
                $paiement->setRefTPE($reference);
                $paiement->setCleIdempotence($tentative['key']);
                $vente->addPaiement($paiement);
                $this->calculateur->recalculerVente($vente);
                $this->em->flush();
                if (!$this->attempts->declare($id, Status::DeclaredAccepted, $par->getId(), $reference, $paiement->getId())) {
                    throw PaymentAttemptConflict::outcomeKnown($this->attempts->byId($id) ?? $tentative);
                }

                return $paiement;
            });
        } catch (UniqueConstraintViolationException|RetryableException) {
            // La réponse tardive du terminal a écrit le règlement de cette clé juste avant, ou les deux
            // écritures se sont interbloquées (MariaDB en a annulé une) : on relit qui l'a emporté.
            $relue = $this->attempts->byId($id) ?? $tentative;
            throw $relue['status'] === Status::Unresolved ? PaymentAttemptConflict::inProgress($relue) : PaymentAttemptConflict::outcomeKnown($relue);
        }

        return ['attempt' => $tentative, 'status' => $issue, 'paiement' => $paiement, 'dejaEnregistre' => false];
    }

    /**
     * La clé a déjà sa tentative : on rend son issue, ou on rejoue une demande qui n'a rien fait bouger.
     *
     * @param Attempt              $tentative
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     */
    private function resume(array $tentative, Vente $vente, array $donnees): array
    {
        if ($tentative['sale'] !== PaymentAttemptStore::hex($vente->getId())) {
            throw new UnprocessableEntityHttpException(PaiementHandler::AUTRE_VENTE);
        }
        // G-4 : la clé désigne une DEMANDE — le moyen et le montant demandé, absent compris.
        if (!$this->sameRequest($tentative, $vente, $donnees)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Cette clé désigne déjà une demande de %s en « %s » sur cette vente (%s) ; celle-ci diffère par le moyen ou le montant '
                . 'et n\'a rien encaissé. Un nouveau règlement prend une nouvelle clé : vérifiez le reste dû avant d\'encaisser de nouveau.',
                $tentative['requested'] !== null ? $tentative['requested'] . ' €' : 'le reste dû',
                $tentative['method'],
                $tentative['status']->label(),
            ));
        }

        return match ($this->releaseIfStale($tentative)) {
            // Au terminal, le règlement peut être PARTIEL (CA-4) : il se retrouve par sa clé, pas par son montant.
            Status::Accepted, Status::DeclaredAccepted => self::replayed(($tentative['usesTerminal'] ? $this->paymentOf($tentative) : $this->handler->replay($vente, $donnees, $tentative['key']))
                ?? throw new \LogicException('Tentative acceptée sans règlement : ' . $tentative['id'])),
            Status::Refused, Status::Cancelled => ['paiement' => null, 'statutTPE' => $tentative['terminal'], 'dejaEnregistre' => true],
            Status::Failed => $this->rerun($tentative, $vente, $donnees),
            // La même demande, après un rechargement (CA-5) : elle attend toujours le terminal, rien n'y repart.
            Status::Sent => self::waiting($this->attempts->byKey($tentative['key']) ?? $tentative),
            // Relue : le jugement de péremption a pu changer son statut et sa raison.
            Status::Pending, Status::Unresolved => throw PaymentAttemptConflict::holding($this->attempts->byKey($tentative['key']) ?? $tentative),
            // Le caissier a lu « non passé » sur le terminal : cette clé ne repart pas (une tentative par clé, D-4).
            Status::DeclaredNotProcessed => throw new UnprocessableEntityHttpException('Ce règlement a été déclaré « non passé » : il n\'a rien encaissé, '
                . 'et sa clé ne repart pas au terminal. Un nouveau règlement prend une nouvelle clé.'),
        };
    }

    /**
     * Rien n'a bougé : la même demande repart, avec la même tentative, si personne d'autre ne tient la vente.
     *
     * @param Attempt              $tentative
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     */
    private function rerun(array $tentative, Vente $vente, array $donnees): array
    {
        $this->releaseIfStale($this->attempts->holding($vente));
        try {
            $reprise = $this->attempts->move($tentative['id'], [Status::Failed], Status::Pending);
        } catch (UniqueConstraintViolationException) {
            throw $this->busy($vente);
        }
        if (!$reprise) {
            throw PaymentAttemptConflict::inProgress(); // une autre demande l'a reprise à l'instant
        }

        return $this->run($tentative, $vente, $donnees);
    }

    /**
     * @param Attempt              $tentative tenue par cette demande, `pending`
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     */
    private function run(array $tentative, Vente $vente, array $donnees): array
    {
        try {
            $this->em->refresh($vente);
            $donnees['cleIdempotence'] = $tentative['key']->toRfc4122();
            $montant = $this->calculateur->decimal($this->handler->requestedAmountCents($vente, $donnees));
            if ($montant !== $tentative['amount'] && !$this->attempts->setAmount($tentative['id'], $montant)) {
                throw PaymentAttemptConflict::inProgress();
            }
        } catch (\Throwable $e) {
            // Rien n'est parti : la vente ne reste pas tenue par une tentative sans effet.
            $this->attempts->move($tentative['id'], [Status::Pending], Status::Failed, reason: $e->getMessage());

            throw $e;
        }

        if ($tentative['usesTerminal']) {
            // Relue : son montant vient d'être recalculé sur la vente tenue.
            return $this->viaTerminal($this->attempts->byId($tentative['id']) ?? $tentative, $vente, $donnees);
        }
        $evenements = new SettlementEvents();
        $resultat = $this->withoutTerminal($tentative, $vente, $donnees, $evenements);
        $evenements->publishTo($this->bus);

        return $resultat;
    }

    /**
     * @param Attempt              $tentative
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     */
    private function withoutTerminal(array $tentative, Vente $vente, array $donnees, SettlementEvents $evenements): array
    {
        try {
            return $this->connection->transactional(function () use ($tentative, $vente, $donnees, $evenements): array {
                $resultat = $this->handler->encaisser($vente, $donnees, $evenements);
                $this->em->flush();
                // Faux : la tentative a été jugée périmée et close entre-temps — la vente a pu être
                // reprise par une autre demande. Tout est annulé, débit compris.
                if (!$this->attempts->move($tentative['id'], [Status::Pending], Status::Accepted, payment: $resultat['paiement']?->getId())) {
                    throw PaymentAttemptConflict::inProgress();
                }

                return $resultat;
            });
        } catch (\Throwable $e) {
            // La transaction est annulée, débit du porte-monnaie compris : rien n'a bougé.
            $this->attempts->move($tentative['id'], [Status::Pending], Status::Failed, reason: $e->getMessage());

            throw $e;
        }
    }

    /**
     * La demande part au terminal, HORS transaction et sans verrou (C-9), et la main revient aussitôt.
     * Son issue arrive par `TerminalOutcomeSink` — tout de suite pour le terminal simulé, plus tard pour
     * un vrai : on rend ce que la tentative est devenue, `sent` compris.
     *
     * @param Attempt              $tentative tenue par cette demande, `pending`, son montant à jour
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool, tentative?: Attempt}
     */
    private function viaTerminal(array $tentative, Vente $vente, array $donnees): array
    {
        try {
            $this->handler->checkTerminalRequest($vente, $donnees);
            $pdv = $vente->getPointDeVente()
                ?? throw new UnprocessableEntityHttpException('Aucun point de vente sur cette vente : aucun terminal à solliciter.');
            $this->attempts->setConnector($tentative['id'], $this->terminal->connector());
        } catch (\Throwable $e) {
            // Rien n'est parti : la vente ne reste pas tenue par une tentative sans effet.
            $this->attempts->move($tentative['id'], [Status::Pending], Status::Failed, reason: $e->getMessage());

            throw $e;
        }

        try {
            $this->terminal->demarrer(new TerminalAttempt($tentative['id'], $tentative['key'], $tentative['method'], $tentative['amount'], $pdv));
        } catch (\Throwable $e) {
            // Un 4xx dit que rien n'est parti (contrat de `TerminalPaiementInterface`) ; toute autre erreur
            // laisse l'issue inconnue. Faux si l'issue est déjà écrite : elle tient.
            $sansEffet = $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
            $this->attempts->move($tentative['id'], [Status::Pending], $sansEffet ? Status::Failed : Status::Unresolved, reason: $e->getMessage());

            throw $e;
        }
        // Faux quand l'issue est déjà arrivée (le terminal simulé répond aussitôt) : elle tient.
        $this->attempts->move($tentative['id'], [Status::Pending], Status::Sent);
        $relue = $this->attempts->byId($tentative['id']) ?? $tentative;

        return match ($relue['status']) {
            Status::Accepted => ['paiement' => $this->paymentOf($relue) ?? throw new \LogicException('Tentative acceptée sans règlement : ' . $relue['id']), 'statutTPE' => StatutTPE::Accepte, 'dejaEnregistre' => false],
            Status::Refused, Status::Cancelled => ['paiement' => null, 'statutTPE' => $relue['terminal'], 'dejaEnregistre' => false],
            Status::Unresolved => ['paiement' => null, 'statutTPE' => $relue['terminal'] ?? StatutTPE::Timeout, 'dejaEnregistre' => false],
            Status::Pending, Status::Sent => self::waiting($relue, false),
            // Déclarée par le caissier pendant que le terminal tardait : sa déclaration tient.
            default => throw PaymentAttemptConflict::outcomeKnown($relue),
        };
    }

    /**
     * Une tentative `pending` depuis plus de {@see STALE_AFTER_SECONDS} : la demande qui l'a écrite a
     * disparu. Avec terminal, elle devient `unresolved` (il a pu débiter) ; sans, la base tranche — un
     * règlement porte sa clé (`accepted`) ou non (`failed`).
     *
     * @param Attempt|null $tentative
     *
     * @return Status|null son statut une fois jugée, relu en base
     */
    private function releaseIfStale(?array $tentative): ?Status
    {
        $delai = ($tentative['status'] ?? null) === Status::Sent ? $this->terminal->staleAfterSeconds() : self::STALE_AFTER_SECONDS;
        if ($tentative === null || !\in_array($tentative['status'], [Status::Pending, Status::Sent], true)
            || $tentative['startedAt'] > new \DateTimeImmutable('-' . $delai . ' seconds')) {
            return $tentative['status'] ?? null;
        }

        $sansIssue = sprintf('Sans issue après %d s', $delai);
        if ($tentative['usesTerminal']) {
            $this->attempts->move($tentative['id'], [Status::Pending, Status::Sent], Status::Unresolved, reason: $sansIssue . ' : le terminal a pu débiter.');
        } else {
            $paiement = $this->attempts->paymentWithKey($tentative['key']);
            $this->attempts->move(
                $tentative['id'],
                [Status::Pending],
                $paiement !== null ? Status::Accepted : Status::Failed,
                reason: $paiement !== null ? null : $sansIssue . ' : aucun règlement écrit.',
                payment: $paiement,
            );
        }

        return $this->attempts->byKey($tentative['key'])['status'] ?? null;
    }

    /**
     * La tentative qui tient la vente, une fois sa péremption jugée : une demande morte depuis plus de
     * {@see STALE_AFTER_SECONDS} ne se présente plus « en cours ». Pour la validation et la clôture.
     *
     * @return Attempt|null
     */
    public function holdingAttempt(Vente $vente): ?array
    {
        $tenante = $this->attempts->holding($vente);
        if ($tenante === null) {
            return null;
        }
        $this->releaseIfStale($tenante);

        return $this->attempts->holding($vente);
    }

    /** Une autre tentative tient la vente : « en cours », ou « issue inconnue » si c'est un terminal muet. */
    private function busy(Vente $vente): PaymentAttemptConflict
    {
        $tenante = $this->attempts->holding($vente);

        return $tenante !== null ? PaymentAttemptConflict::holding($tenante) : PaymentAttemptConflict::inProgress();
    }

    /**
     * @param Attempt              $tentative
     * @param array<string, mixed> $donnees
     */
    private function sameRequest(array $tentative, Vente $vente, array $donnees): bool
    {
        if (($donnees['moyen'] ?? null) !== $tentative['method']) {
            return false;
        }
        if (!isset($donnees['montant'])) {
            return $tentative['requested'] === null;
        }

        return $tentative['requested'] !== null
            && $this->handler->requestedAmountCents($vente, $donnees) === $this->calculateur->centimes($tentative['requested']);
    }

    /**
     * La tentative de cet identifiant (rendu à l'écran) si elle porte sur CETTE vente — une vente que
     * le fournisseur a déjà trouvée dans le périmètre de l'appelant (cloisonnement, CA-8) ; sinon nulle.
     *
     * @return Attempt|null
     */
    public function attemptOnSale(Vente $vente, mixed $identifiant): ?array
    {
        $tentative = \is_string($identifiant) && Uuid::isValid($identifiant)
            ? $this->attempts->byId(PaymentAttemptStore::hex(Uuid::fromString($identifiant))) : null;

        return $tentative !== null && $tentative['sale'] === PaymentAttemptStore::hex($vente->getId()) ? $tentative : null;
    }

    /**
     * Une tentative, son délai jugé — ce que l'écran scrute pendant l'attente du terminal (CA-1, CA-5).
     *
     * @param Attempt $tentative
     *
     * @return Attempt
     */
    public function judged(array $tentative): array
    {
        $this->releaseIfStale($tentative);

        return $this->attempts->byId($tentative['id']) ?? $tentative;
    }

    /**
     * « Annuler » pendant l'attente du terminal (CA-3). Le terminal est prié d'abandonner, et la
     * tentative passe `cancelled` sans l'attendre : la vente est libérée. Si son issue est arrivée
     * avant, elle tient (409 `payment_outcome_known`) ; si elle arrive après, une acceptation
     * l'emporte encore ({@see TerminalOutcomeRecorder}) — le client a été débité. Rejouée, rend son issue.
     *
     * Une tentative `unresolved` ne s'annule pas : la carte a pu être débitée, elle se déclare.
     *
     * @param array<string, mixed> $donnees { tentative }
     *
     * @return array{attempt: Attempt, dejaEnregistre: bool}
     *
     * @throws PaymentAttemptConflict
     */
    public function cancel(Vente $vente, array $donnees): array
    {
        $tentative = $this->attemptOnSale($vente, $donnees['tentative'] ?? null);
        if ($tentative === null || !$tentative['usesTerminal']) {
            throw new UnprocessableEntityHttpException('Aucun règlement au terminal de cet identifiant sur cette vente.');
        }
        $statut = $this->releaseIfStale($tentative);
        $relue = $this->attempts->byId($tentative['id']) ?? $tentative;
        if ($statut === Status::Cancelled) {
            return ['attempt' => $relue, 'dejaEnregistre' => true];
        }
        if ($statut !== Status::Sent) {
            throw match ($statut) {
                Status::Pending => PaymentAttemptConflict::inProgress($relue),
                Status::Unresolved => PaymentAttemptConflict::outcomeUnknown($relue),
                default => PaymentAttemptConflict::outcomeKnown($relue),
            };
        }

        $raison = null;
        $pdv = $vente->getPointDeVente();
        try {
            if ($pdv !== null) {
                $this->terminal->annuler(new TerminalAttempt($relue['id'], $relue['key'], $relue['method'], $relue['amount'], $pdv));
            }
        } catch (\Throwable $e) {
            // Le terminal n'a pas pu être prévenu : la caisse cesse d'attendre quand même. S'il accepte ensuite, son acceptation gagne.
            $raison = 'Annulation non transmise au terminal : ' . $e->getMessage();
        }
        if (!$this->attempts->move($relue['id'], [Status::Sent], Status::Cancelled, StatutTPE::Annule, $raison)) {
            // L'issue est arrivée entre la lecture et l'annulation : elle tient.
            throw PaymentAttemptConflict::outcomeKnown($this->attempts->byId($relue['id']) ?? $relue);
        }

        return ['attempt' => $this->attempts->byId($relue['id']) ?? $relue, 'dejaEnregistre' => false];
    }

    /**
     * Le règlement écrit sous la clé de la tentative.
     *
     * @param Attempt $tentative
     */
    private function paymentOf(array $tentative): ?Paiement
    {
        $id = $this->attempts->paymentWithKey($tentative['key']);

        return $id !== null ? $this->em->find(Paiement::class, $id) : null;
    }

    /**
     * Elle attend le terminal : rien n'est encaissé, l'écran scrute la tentative.
     *
     * @param Attempt $tentative
     *
     * @return array{paiement: null, statutTPE: null, dejaEnregistre: bool, tentative: Attempt}
     */
    private static function waiting(array $tentative, bool $dejaEnregistre = true): array
    {
        return ['paiement' => null, 'statutTPE' => null, 'dejaEnregistre' => $dejaEnregistre, 'tentative' => $tentative];
    }

    /** @return array{paiement: Paiement, statutTPE: StatutTPE|null, dejaEnregistre: true} */
    private static function replayed(Paiement $paiement): array
    {
        return ['paiement' => $paiement, 'statutTPE' => $paiement->getStatutTPE(), 'dejaEnregistre' => true];
    }
}
