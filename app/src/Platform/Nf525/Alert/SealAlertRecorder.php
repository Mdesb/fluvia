<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

use App\Platform\Entity\Nf525Alert;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealRefusalCause;
use App\Platform\Nf525\SealingRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POINT D'ENTRÉE UNIQUE DES HANDLERS DE SCELLEMENT ET DES VÉRIFICATIONS (plan, D-A, D-G, D-I).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - MEILLEUR EFFORT SUR L'ALERTE, JAMAIS SUR LE REFUS : tout `\Throwable` (table absente pendant la
 *   fenêtre de déploiement, verrou 1205, deadlock 1213, panne de connexion, panne de cloche) est journalisé
 *   puis avalé ; `recordRefusal` rend alors l'exception D'ORIGINE, sans référence : le 409 part quand même.
 * - Le journal ne reçoit que la classe et le code de l'exception, et l'identité de la chaîne : jamais le
 *   message brut (il pourrait porter une requête, une clé, un paramètre de connexion).
 * - `resolveOnSeal` ne ferme que les alertes d'origine `refusal` ; un SELECT indexé sur la connexion de
 *   l'appelant décide s'il y a quelque chose à fermer : aucune écriture sinon. L'UPDATE, lui, passe par la
 *   seconde connexion (pas de verrou croisé avec la vente).
 * - Une vérification intacte ne ferme que l'origine `verification`.
 */
final class SealAlertRecorder
{
    private const DEFAULT_BROKEN_DETAIL = 'Chaîne rompue détectée par une vérification.';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Nf525AlertStore $store,
        private readonly SealChainEstablishments $establishments,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** Rend l'exception AVEC référence, ou l'exception d'origine si l'alerte n'a pas pu s'écrire. */
    public function recordRefusal(SealChainRef $chain, SealingRefusedException $refusal): SealingRefusedException
    {
        try {
            $opened = $this->store->open(
                $chain,
                $refusal->cause(),
                SealAlertOrigin::Refusal,
                $refusal->technicalDetail(),
                $this->establishments->forChain($chain),
            );

            return $refusal->withReference($opened->reference);
        } catch (\Throwable $e) {
            $this->logFailure('NF525 : alerte de refus non enregistrée, le refus part sans référence.', $chain, $e);

            return $refusal;
        }
    }

    /** Rompue : ouvre ou met à jour l'alerte et rend sa référence. Intacte : ferme l'alerte de vérification, rend null. */
    public function recordVerification(SealChainRef $chain, bool $intact, ?string $detail = null): ?string
    {
        try {
            if ($intact) {
                // Pré-lecture indexée sur la connexion de l'appelant : une vérification intacte sans alerte
                // ouverte (le cas courant) n'ouvre pas de seconde connexion.
                if ($this->hasOpenAlert($chain, SealAlertOrigin::Verification)) {
                    $this->store->resolve($chain->key(), SealAlertOrigin::Verification);
                }

                return null;
            }

            return $this->store->open(
                $chain,
                SealRefusalCause::ChainBroken,
                SealAlertOrigin::Verification,
                $detail ?? self::DEFAULT_BROKEN_DETAIL,
                $this->establishments->forChain($chain),
            )->reference;
        } catch (\Throwable $e) {
            $this->logFailure('NF525 : résultat de vérification non enregistré dans l\'alerte.', $chain, $e);

            return null;
        }
    }

    /** Un scellement dont les gardes passent referme l'alerte d'origine `refusal` de la chaîne. Ne lève jamais. */
    public function resolveOnSeal(SealChainRef $chain): void
    {
        try {
            if (!$this->hasOpenAlert($chain, SealAlertOrigin::Refusal)) {
                return;
            }
            $this->store->resolve($chain->key(), SealAlertOrigin::Refusal);
        } catch (\Throwable $e) {
            $this->logFailure('NF525 : alerte de refus non refermée (table absente ou panne), le scellement continue.', $chain, $e, false);
        }
    }

    private function logFailure(string $message, SealChainRef $chain, \Throwable $e, bool $critical = true): void
    {
        try {
            $context = ['chain_key' => $chain->key(), 'exception_class' => $e::class, 'code' => $e->getCode()];
            $critical ? $this->logger->critical($message, $context) : $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Meilleur effort strict : une panne du journal ne doit jamais remplacer le 409.
        }
    }

    private function hasOpenAlert(SealChainRef $chain, SealAlertOrigin $origin): bool
    {
        $table = $this->em->getClassMetadata(Nf525Alert::class)->getTableName();

        return $this->em->getConnection()->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE open_chain_key = :key AND origin = :origin LIMIT 1', $table),
            ['key' => $chain->key(), 'origin' => $origin->value],
        ) !== false;
    }
}
