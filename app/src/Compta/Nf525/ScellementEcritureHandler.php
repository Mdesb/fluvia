<?php

declare(strict_types=1);

namespace App\Compta\Nf525;

use App\Compta\Entity\EcritureComptable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prolongement du chaînage NF525 côté écritures comptables (US-L4-09, CA-13). Réutilise le **procédé**
 * de `App\Vente\Nf525\HashChainSignataire` (sha256 chaîné + HMAC placeholder, canonicalisation
 * déterministe) — même algorithme, même statut « ⚠ à valider par un référent conformité » (point
 * EXPERT #4/5, §8 du plan). Implémentation propre à M6 (pas une réutilisation littérale du port
 * `App\Vente\Nf525\SignataireOperation`, qui est structurellement lié à `App\Caisse\Entity\
 * PointDeVente` — M2 n'est pas modifié) : la chaîne est scopée par `(profilExploitant, journal)` au
 * lieu de `pointDeVente`, avec les champs `numeroSequence/empreinte/empreintePrecedente/signature`
 * embarqués directement sur `EcritureComptable` (pas de table dupliquée), conformément au §6 du plan.
 */
final class ScellementEcritureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        // Clé HMAC de scellement NF525 injectée depuis l'environnement, SANS valeur par défaut : son
        // absence empêche le démarrage (échec fermé). Une clé publique/codée en dur casserait
        // l'inaltérabilité légale NF525 (on pourrait altérer une écriture puis re-signer). Chaîne
        // dédiée Compta, distincte de `NF525_SEAL_KEY` (Vente).
        #[Autowire(env: 'NF525_COMPTA_SEAL_KEY')]
        private readonly string $cleScellement,
    ) {
    }

    /** Scelle l'écriture (calcule et affecte séquence/empreinte/signature). Ne flush pas. */
    public function sceller(EcritureComptable $ecriture): EcritureComptable
    {
        $precedente = $this->dernierMaillon($ecriture);
        $empreintePrecedente = $precedente?->getEmpreinte();
        $sequence = $precedente === null ? 1 : $precedente->getNumeroSequence() + 1;

        $payload = $this->payloadCanonique($ecriture);
        $empreinte = $this->calculerEmpreinte($payload, $empreintePrecedente);

        $ecriture->setNumeroSequence($sequence);
        $ecriture->setEmpreintePrecedente($empreintePrecedente);
        $ecriture->setEmpreinte($empreinte);
        $ecriture->setSignature($this->signer($empreinte));

        // ⚠ L'INSTANTANÉ EST CONSERVÉ, PLUS RECONSTRUIT — même raison que côté factures : le payload
        // lit `$ligne->getTauxTva()?->getTaux()`, donc un taux corrigé faisait dériver l'empreinte
        // recalculée de toute écriture scellée avec lui.
        $ecriture->setPayloadCanonique($payload);

        return $ecriture;
    }

    public function dernierMaillon(EcritureComptable $ecriture): ?EcritureComptable
    {
        return $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('IDENTITY(e.journal) = :journal')
            ->andWhere('e.numeroSequence > 0')
            ->setParameter('profil', $ecriture->getProfilExploitant()?->getId(), 'uuid')
            ->setParameter('journal', $ecriture->getJournal()?->getId(), 'uuid')
            ->orderBy('e.numeroSequence', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recalcule la chaîne d'un journal et détecte les ruptures (CA-13) : trou de séquence, empreinte
     * incohérente, signature invalide.
     *
     * @param iterable<EcritureComptable> $ecritures
     *
     * @return array{intacte: bool, nbOperations: int, anomalies: list<array<string, mixed>>}
     */
    public function verifieChaine(iterable $ecritures): array
    {
        $liste = [];
        foreach ($ecritures as $e) {
            $liste[] = $e;
        }
        usort($liste, static fn (EcritureComptable $a, EcritureComptable $b): int => $a->getNumeroSequence() <=> $b->getNumeroSequence());

        $anomalies = [];
        $attendue = 1;
        $empreintePrecedente = null;

        foreach ($liste as $e) {
            $seq = $e->getNumeroSequence();
            if ($seq !== $attendue) {
                $anomalies[] = ['sequence' => $seq, 'probleme' => sprintf('trou de séquence : attendu %d, trouvé %d', $attendue, $seq)];
                $attendue = $seq;
            }
            if ($e->getEmpreintePrecedente() !== $empreintePrecedente) {
                $anomalies[] = ['sequence' => $seq, 'probleme' => 'chaînage rompu : empreinte précédente incohérente'];
            }

            // ⚠ DEUX CAS QUE L'ANCIEN CODE CONFONDAIT. Avec l'instantané stocké, un écart PROUVE
            // une altération. Sans lui, on ne compare qu'à une reconstruction, et un référentiel qui
            // a bougé depuis suffit à la faire différer — sans que rien n'ait été touché.
            $instantane = $e->getPayloadCanonique();
            $empreinteRecalculee = $this->calculerEmpreinte($instantane ?? $this->payloadCanonique($e), $e->getEmpreintePrecedente());

            // ⚠ SECONDE GARANTIE, INDEPENDANTE DE LA PREMIERE — même raison que côté factures :
            // l'empreinte recalculée depuis l'instantané ne voit pas une ligne touchée en base.
            if ($instantane !== null && !$this->instantaneDecritEncore($instantane, $e)) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'probleme' => 'la donnée a été altérée depuis le scellement : l\'écriture en base ne correspond plus à ce qui a été scellé',
                ];
            }

            if (!hash_equals($empreinteRecalculee, $e->getEmpreinte())) {
                $anomalies[] = [
                    'sequence' => $seq,
                    'probleme' => $instantane !== null
                        ? 'empreinte incohérente : la donnée a été altérée'
                        : 'non vérifiable : ce document a été scellé avant que l\'instantané ne soit conservé, et sa reconstruction ne redonne pas l\'empreinte — un référentiel a pu changer depuis',
                ];
            } elseif (!hash_equals($this->signer($e->getEmpreinte()), $e->getSignature())) {
                $anomalies[] = ['sequence' => $seq, 'probleme' => 'signature invalide'];
            }

            $empreintePrecedente = $e->getEmpreinte();
            ++$attendue;
        }

        return ['intacte' => $anomalies === [], 'nbOperations' => \count($liste), 'anomalies' => $anomalies];
    }

    /** @return array<string, mixed> */
    private function payloadCanonique(EcritureComptable $ecriture): array
    {
        $lignes = [];
        foreach ($ecriture->getLignes() as $ligne) {
            $lignes[] = [
                'id' => (string) $ligne->getId(),
                'compte' => $ligne->getCompte()?->getNumero(),
                'debit' => $ligne->getDebitCentimes(),
                'credit' => $ligne->getCreditCentimes(),
                'taux' => $ligne->getTauxTva()?->getTaux(),
            ];
        }
        // Tri déterministe par identifiant : `getLignes()` (collection OneToMany sans ORDER BY explicite)
        // peut être retournée dans un ordre différent entre le scellement (juste après construction en
        // mémoire) et une vérification ultérieure (rechargée depuis la base) — sans ce tri, l'empreinte
        // recalculée ne serait pas reproductible (CA-13).
        usort($lignes, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'journal' => $ecriture->getJournal()?->getCode(),
            'date' => $ecriture->getDateEcriture()->format('Y-m-d'),
            'venteOrigine' => (string) $ecriture->getVenteOrigine(),
            'lignes' => $lignes,
        ];
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

    /** @param array<string, mixed> $payload */
    private function canonicalize(array $payload): string
    {
        return json_encode($this->trierRecursif($payload), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $valeur
     *
     * @return array<array-key, mixed>
     */
    private function trierRecursif(array $valeur): array
    {
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

    /**
     * REPREND L'INSTANTANÉ D'UN DOCUMENT SCELLÉ AVANT QU'ON NE LE CONSERVE — SI ET SEULEMENT SI ON
     * PEUT PROUVER QUE C'EST BIEN LE SIEN.
     *
     * ⚠ La condition n'est pas une précaution, elle sépare une reprise d'une fabrication de preuve.
     *
     * Si l'instantané recalculé aujourd'hui redonne EXACTEMENT l'empreinte scellée hier, il est
     * démontré que c'est celui d'origine : une empreinte sha256 ne se retrouve pas par hasard. La
     * coïncidence est la preuve.
     *
     * Sinon, on ne sait pas si un référentiel a bougé ou si la donnée a été touchée. Écrire un
     * instantané dans ce cas fabriquerait la preuve qu'on prétend conserver — c'est précisément le
     * geste qu'un contrôle reprocherait. On laisse donc `null`, et la vérification dit
     * « non vérifiable » au lieu d'accuser.
     *
     * Arbitré par Maxime le 31/08.
     *
     * @return bool vrai si le document porte désormais son instantané
     */
    public function reprendreInstantane(EcritureComptable $ecriture): bool
    {
        if ($ecriture->getPayloadCanonique() !== null) {
            return true;
        }

        if ($ecriture->getEmpreinte() === '') {
            return false;
        }

        $instantane = $this->payloadCanonique($ecriture);
        $recalculee = $this->calculerEmpreinte($instantane, $ecriture->getEmpreintePrecedente());

        if (!hash_equals($recalculee, $ecriture->getEmpreinte())) {
            return false;
        }

        $ecriture->setPayloadCanonique($instantane);

        return true;
    }


    /**
     * L'instantané décrit-il ENCORE le document ? Question distincte de « l'empreinte tient-elle ».
     *
     * ⚠ Vérifier l'empreinte contre l'instantané stocké supprime les fausses accusations — mais
     * cesse aussi de voir une ligne altérée en base : l'empreinte se recalcule alors depuis une
     * copie que personne n'a touchée, donc elle correspond toujours. Les deux garanties sont
     * indépendantes et il faut les deux.
     *
     * **Seul `taux` est neutralisé**, parce qu'il vient d'un référentiel PARTAGÉ que l'exploitant a
     * le droit de corriger. Tout le reste appartient au document — montants, désignations,
     * quantités, numéro, dates, destinataire — et le garde d'inaltérabilité en interdit la
     * modification. Un écart sur eux EST une altération, et le mot est mérité.
     *
     * @param array<string, mixed> $instantane
     */
    private function instantaneDecritEncore(array $instantane, EcritureComptable $ecriture): bool
    {
        $vivant = $this->payloadCanonique($ecriture);

        // Le taux est recopié depuis l'instantané : c'est la seule valeur dont on accepte qu'elle
        // ait bougé sans que le document n'ait été touché.
        foreach ($vivant['lignes'] as $index => $ligne) {
            if (isset($instantane['lignes'][$index]['taux'])) {
                $vivant['lignes'][$index]['taux'] = $instantane['lignes'][$index]['taux'];
            }
        }

        return $vivant == $instantane;
    }

}
