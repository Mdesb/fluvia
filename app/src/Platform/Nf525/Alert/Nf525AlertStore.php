<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Écriture de l'alerte NF525 sur la seconde connexion (plan, D-B et D-C). Toutes les requêtes sont
 * paramétrées.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - Une alerte OUVERTE par chaîne, garantie par l'index unique sur `open_chain_key` : INSERT d'abord ; sur
 *   1062 (déjà ouverte, ou course perdue : le second INSERT attend le COMMIT du premier) un UPDATE atomique
 *   dont le WHERE nomme `open_chain_key` (avant MariaDB 11.8 l'optimiseur ne reconnaît pas l'expression).
 * - Les liaisons d'établissement sont un INSERT SIMPLE qui n'attrape QUE 1062 : jamais `INSERT IGNORE`, qui
 *   avale aussi la violation de clé étrangère (1452) et tronque en silence.
 * - Une vérification rompue sur une alerte de refus la fait passer à `origin = verification` (règle la plus
 *   stricte), jamais l'inverse.
 */
final class Nf525AlertStore
{
    private const MAX_DETAIL_LENGTH = 2000;
    private const MAX_REFERENCE_ATTEMPTS = 3;

    /** @var \Closure(): string */
    private readonly \Closure $referenceGenerator;

    /**
     * @param (callable(): string)|null $referenceGenerator remplace le tirage de la référence (tests)
     * @param Nf525AlertOpenedListener|null $listener appelé dans la transaction de l'alerte, à l'ouverture seulement
     */
    public function __construct(
        private readonly Nf525AlertConnection $connections,
        private readonly ?Nf525AlertOpenedListener $listener = null,
        ?callable $referenceGenerator = null,
    ) {
        $this->referenceGenerator = $referenceGenerator !== null
            ? \Closure::fromCallable($referenceGenerator)
            : static fn (): string => 'NF525-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Ouvre l'alerte de la chaîne, ou compte l'événement sur celle qui est déjà ouverte.
     *
     * @param list<Uuid> $establishmentIds établissements à lier (cloisonnement de l'alerte)
     */
    public function open(
        SealChainRef $chain,
        SealRefusalCause $cause,
        SealAlertOrigin $origin,
        string $detail,
        array $establishmentIds,
    ): OpenedAlert {
        $detail = mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);

        return $this->connections->transactional(function (Connection $db, \Closure $budget) use ($chain, $cause, $origin, $detail, $establishmentIds): OpenedAlert {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $result = null;
            // Deux tours : l'alerte refermée entre l'INSERT refusé et l'UPDATE se rouvre au tour suivant.
            for ($round = 0; $round < 2 && $result === null; ++$round) {
                $result = $this->tryInsert($db, $chain, $cause, $origin, $detail, $now)
                    ?? $this->tryUpdate($db, $chain, $cause, $origin, $detail, $now);
            }
            if ($result === null) {
                throw new \RuntimeException('Alerte NF525 : ni ouverture ni mise à jour possibles.');
            }

            foreach ($establishmentIds as $establishmentId) {
                $budget();
                $this->link($db, $result->alertId, $establishmentId);
            }
            if ($result->opened) {
                $this->listener?->onOpened($db, $result, $chain, $establishmentIds);
            }

            return $result;
        });
    }

    /** Ferme l'alerte ouverte de la chaîne SI son origine est celle demandée. Vrai si une alerte a été fermée. */
    public function resolve(string $chainKey, SealAlertOrigin $origin): bool
    {
        return $this->connections->transactional(function (Connection $db) use ($chainKey, $origin): bool {
            $affected = $db->executeStatement(
                "UPDATE platform_nf525_alert SET status = 'resolved', resolved_at = :now WHERE open_chain_key = :key AND origin = :origin",
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'key' => $chainKey, 'origin' => $origin->value],
            );

            return (int) $affected > 0;
        });
    }

    private function tryInsert(Connection $db, SealChainRef $chain, SealRefusalCause $cause, SealAlertOrigin $origin, string $detail, string $now): ?OpenedAlert
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; ++$attempt) {
            $id = Uuid::v4();
            $reference = ($this->referenceGenerator)();
            try {
                $db->executeStatement(
                    "INSERT INTO platform_nf525_alert
                        (id, reference, chain_type, chain_key, chain_label, point_of_sale_id, operator_profile_id, journal_id,
                         cause, origin, status, refusal_count, detection_count, first_event_at, last_event_at, technical_detail)
                     VALUES (:id, :reference, :type, :key, :label, :pos, :profile, :journal,
                         :cause, :origin, 'open', :refusals, :detections, :now, :now, :detail)",
                    [
                        'id' => $id->toBinary(),
                        'reference' => $reference,
                        'type' => $chain->type->value,
                        'key' => $chain->key(),
                        'label' => $chain->label(),
                        'pos' => $chain->pointOfSaleId?->toBinary(),
                        'profile' => $chain->operatorProfileId?->toBinary(),
                        'journal' => $chain->journalId?->toBinary(),
                        'cause' => $cause->value,
                        'origin' => $origin->value,
                        'refusals' => $origin === SealAlertOrigin::Refusal ? 1 : 0,
                        'detections' => $origin === SealAlertOrigin::Verification ? 1 : 0,
                        'now' => $now,
                        'detail' => $detail,
                    ],
                );

                return new OpenedAlert($reference, true, $id->toRfc4122());
            } catch (UniqueConstraintViolationException $e) {
                if (!str_contains($e->getMessage(), 'uniq_nf525_alert_reference')) {
                    return null; // déjà ouverte sur cette chaîne
                }
            }
        }

        throw new \RuntimeException('Alerte NF525 : trois collisions de référence de suite.');
    }

    private function tryUpdate(Connection $db, SealChainRef $chain, SealRefusalCause $cause, SealAlertOrigin $origin, string $detail, string $now): ?OpenedAlert
    {
        $counter = $origin === SealAlertOrigin::Verification ? 'detection_count' : 'refusal_count';
        $promote = $origin === SealAlertOrigin::Verification ? ", origin = 'verification'" : '';
        $affected = $db->executeStatement(
            "UPDATE platform_nf525_alert
             SET {$counter} = {$counter} + 1, last_event_at = :now, cause = :cause, technical_detail = :detail{$promote}
             WHERE open_chain_key = :key",
            ['now' => $now, 'cause' => $cause->value, 'detail' => $detail, 'key' => $chain->key()],
        );
        if ((int) $affected === 0) {
            return null;
        }
        $row = $db->fetchAssociative('SELECT id, reference FROM platform_nf525_alert WHERE open_chain_key = :key', ['key' => $chain->key()]);
        if ($row === false) {
            return null;
        }

        return new OpenedAlert((string) $row['reference'], false, Uuid::fromBinary((string) $row['id'])->toRfc4122());
    }

    private function link(Connection $db, string $alertId, Uuid $establishmentId): void
    {
        try {
            $db->executeStatement(
                'INSERT INTO platform_nf525_alert_establishment (alert_id, establishment_id) VALUES (:alert, :establishment)',
                ['alert' => Uuid::fromString($alertId)->toBinary(), 'establishment' => $establishmentId->toBinary()],
            );
        } catch (UniqueConstraintViolationException) {
            // Déjà liée : attendu à chaque événement. Toute autre erreur (1452, 1205) fait échouer l'écriture.
        }
    }
}
