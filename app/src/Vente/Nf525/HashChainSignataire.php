<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Platform\Nf525\SealKeyring;
use App\Vente\Nf525\Entity\OperationScellee;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Implémentation par défaut du signataire NF525 (US-L2-11). Chaînage type « blockchain léger » :
 *   empreinte(N) = sha256( canonicalize(payload) . '|' . empreinte(N-1) )
 *   signature(N) = HMAC-SHA256( empreinte(N) , clé active du trousseau )   [placeholder]
 *   sealKeyId(N) = identifiant non réversible de cette clé (#361)
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
        // #361 — le trousseau de `NF525_SEAL_KEY` : clé active sans valeur par défaut (son absence
        // empêche le démarrage), plus les clés retirées qui ne servent qu'à vérifier.
        #[Autowire(service: 'app.nf525.keyring.sales')]
        private readonly SealKeyring $keyring,
    ) {
    }

    public function scelle(OperationAScellerDto $op, ?OperationScellee $precedente): OperationScellee
    {
        // #361 — ÉCHEC FERMÉ : on ne signe jamais par-dessus un dernier maillon que le trousseau ne
        // vérifie plus (clé changée sans que l'ancienne soit gardée en clé retirée).
        if ($precedente !== null) {
            $this->keyring->assertCanExtend(
                $precedente->getEmpreinte(),
                $precedente->getSignature(),
                $precedente->getSealKeyId(),
                sprintf('chaîne de vente du point de vente %s, maillon n° %d', (string) $op->pointDeVente->getId(), $precedente->getNumeroSequence()),
            );
        }

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
        $operation->setSignature($this->keyring->sign($empreinte));
        $operation->setSealKeyId($this->keyring->activeKeyId());

        return $operation;
    }

    public function verifieChaine(iterable $operations): RapportVerification
    {
        return $this->verifieSegment($operations, null, 1);
    }

    /**
     * #342 — la même vérification, sur un SEGMENT de chaîne (celui qu'une archive annuelle emporte) :
     * il commence au rang `$premiereSequence` et s'accroche à `$empreinteAvant`. La chaîne entière en
     * est le cas particulier (rang 1, aucune empreinte avant) — une seule boucle pour les deux.
     */
    public function verifieSegment(iterable $operations, ?string $empreinteAvant, int $premiereSequence): RapportVerification
    {
        $liste = [];
        foreach ($operations as $operation) {
            $liste[] = $operation;
        }
        usort($liste, static fn (OperationScellee $a, OperationScellee $b): int => $a->getNumeroSequence() <=> $b->getNumeroSequence());

        $anomalies = [];
        $attendue = $premiereSequence;
        $empreintePrecedente = $empreinteAvant;

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
            } elseif (null !== $probleme = $this->keyring->problem(
                $this->keyring->check($operation->getEmpreinte(), $operation->getSignature(), $operation->getSealKeyId()),
                $operation->getSealKeyId(),
            )) {
                // #361 — la clé est choisie par l'identifiant du maillon ; « clé inconnue du
                // trousseau » est distinguée de « signature invalide ».
                $anomalies[] = [
                    'sequence' => $seq,
                    'type' => $operation->getTypeOperation()->value,
                    'probleme' => $probleme,
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
