<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Vente\Nf525\Entity\OperationScellee;

/**
 * Implémentation par défaut du signataire NF525 (US-L2-11). Chaînage type « blockchain léger » :
 *   empreinte(N) = sha256( canonicalize(payload) . '|' . empreinte(N-1) )
 *   signature(N) = HMAC-SHA256( empreinte(N) , clé de scellement )   [placeholder]
 * canonicalize() sérialise de façon déterministe (tri récursif des clés) pour rendre l'empreinte
 * reproductible et vérifiable. La séquence est strictement croissante (précédente + 1).
 *
 * ⚠ À VALIDER PAR UN RÉFÉRENT CONFORMITÉ (ne bloque pas le dev) : l'algorithme exact de signature
 * (HMAC vs signature asymétrique/certificat), le périmètre de certification (auto-attestation vs
 * LNE/INFOCERT) et la conservation légale restent à arbitrer avec M6. Le design enfichable garantit
 * que ces choix se traduisent par une nouvelle implémentation de SignataireOperation, sans toucher
 * au domaine Vente/Caisse.
 */
final class HashChainSignataire implements SignataireOperation
{
    public function __construct(
        private readonly string $cleScellement = 'nf525-placeholder-key',
    ) {
    }

    public function scelle(OperationAScellerDto $op, ?OperationScellee $precedente): OperationScellee
    {
        $empreintePrecedente = $precedente?->getEmpreinte();
        $sequence = $precedente === null ? 1 : $precedente->getNumeroSequence() + 1;

        $operation = (new OperationScellee())
            ->setPointDeVente($op->pointDeVente)
            ->setTypeOperation($op->typeOperation)
            ->setCibleType($op->cibleType)
            ->setCibleId($op->cibleId)
            ->setNumeroSequence($sequence)
            ->setEmpreintePrecedente($empreintePrecedente)
            ->setPayloadCanonique($op->payload);

        $empreinte = $this->calculerEmpreinte($op->payload, $empreintePrecedente);
        $operation->setEmpreinte($empreinte);
        $operation->setSignature($this->signer($empreinte));

        return $operation;
    }

    public function verifieChaine(iterable $operations): RapportVerification
    {
        $liste = [];
        foreach ($operations as $operation) {
            $liste[] = $operation;
        }
        usort($liste, static fn (OperationScellee $a, OperationScellee $b): int => $a->getNumeroSequence() <=> $b->getNumeroSequence());

        $anomalies = [];
        $attendue = 1;
        $empreintePrecedente = null;

        foreach ($liste as $operation) {
            $seq = $operation->getNumeroSequence();

            if ($seq !== $attendue) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'type' => $operation->getTypeOperation()->value,
                    'probleme' => sprintf('trou de séquence : attendu %d, trouvé %d', $attendue, $seq),
                ];
                $attendue = $seq;
            }

            if ($operation->getEmpreintePrecedente() !== $empreintePrecedente) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'type' => $operation->getTypeOperation()->value,
                    'probleme' => 'chaînage rompu : empreinte précédente incohérente',
                ];
            }

            $empreinteRecalculee = $this->calculerEmpreinte($operation->getPayloadCanonique(), $operation->getEmpreintePrecedente());
            if (!hash_equals($empreinteRecalculee, $operation->getEmpreinte())) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'type' => $operation->getTypeOperation()->value,
                    'probleme' => 'empreinte incohérente : la donnée a été altérée',
                ];
            } elseif (!hash_equals($this->signer($operation->getEmpreinte()), $operation->getSignature())) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'type' => $operation->getTypeOperation()->value,
                    'probleme' => 'signature invalide',
                ];
            }

            $empreintePrecedente = $operation->getEmpreinte();
            ++$attendue;
        }

        return new RapportVerification($anomalies === [], \count($liste), $anomalies);
    }

    /** @param array<string, mixed> $payload */
    private function calculerEmpreinte(array $payload, ?string $empreintePrecedente): string
    {
        return hash('sha256', $this->canonicalize($payload) . '|' . ($empreintePrecedente ?? ''));
    }

    private function signer(string $empreinte): string
    {
        return hash_hmac('sha256', $empreinte, $this->cleScellement);
    }

    /**
     * Sérialisation déterministe : tri récursif des clés puis JSON sans échappement superflu.
     *
     * @param array<string, mixed> $payload
     */
    private function canonicalize(array $payload): string
    {
        $trie = $this->trierRecursif($payload);

        return json_encode($trie, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $valeur
     *
     * @return array<array-key, mixed>
     */
    private function trierRecursif(array $valeur): array
    {
        // Ne trie que les tableaux associatifs ; préserve l'ordre des listes.
        $estListe = array_is_list($valeur);
        foreach ($valeur as $cle => $sousValeur) {
            if (\is_array($sousValeur)) {
                $valeur[$cle] = $this->trierRecursif($sousValeur);
            }
        }
        if (!$estListe) {
            ksort($valeur);
        }

        return $valeur;
    }
}
