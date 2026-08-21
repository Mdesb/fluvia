<?php

declare(strict_types=1);

namespace App\Facturation\Nf525;

use App\Facturation\Entity\Facture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chaînage NF525 des documents commerciaux (`plan-facturation.md` §0.3, constitution §4.5).
 *
 * Même raisonnement que M6 (`App\Compta\Nf525\ScellementEcritureHandler`, dont le commentaire
 * assume déjà la duplication) : Facturation duplique le **procédé** (empreinte sha256 chaînée +
 * HMAC, canonicalisation déterministe) et **non le code**, pour ne pas coupler les domaines.
 * La chaîne est scopée par **`profilExploitant` seul** — factures et avoirs partagent la même
 * chaîne chronologique, `nature` figurant dans le payload canonique (même précédent que M2 où
 * `OperationScellee` mélange Vente et Avoir sur un même point de vente).
 *
 * Deux garanties **indépendantes** cohabitent, ne pas les confondre :
 *  - le **numéro métier légal** (`FA-2026-00001`), séquence continue par
 *    `(profilExploitant, exercice, préfixe)` portée par `SerieNumerotation` sous verrou pessimiste ;
 *  - la **chaîne d'intégrité** (`numeroSequence`/`empreinte`/`empreintePrecedente`/`signature`)
 *    calculée ici, qui prouve qu'aucun document n'a été altéré ni retiré après coup.
 *
 * ⚠ EXPERT #5 (`spec-facturation.md` §9) — procédé cryptographique et périmètre de certification
 * restent à valider par un référent conformité, comme pour M2 et M6.
 */
final class ScellementFactureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        // Aucune valeur par defaut : la cle vient de l'environnement, et son absence doit empecher le
        // demarrage plutot que produire des factures scellees avec une cle que n'importe qui peut lire
        // dans le code. Troisieme occurrence du meme motif en trois jours - le garde-fou des secrets
        // en dur existe desormais pour qu'il n'y en ait pas de quatrieme.
        #[Autowire(env: 'NF525_FACTURATION_SEAL_KEY')]
        private readonly string $cleScellement,
    ) {
    }

    /** Scelle le document (séquence/empreinte/signature). Ne flush pas : l'appelant maîtrise la transaction. */
    public function sceller(Facture $facture): Facture
    {
        $precedente = $this->dernierMaillon($facture);
        $empreintePrecedente = $precedente?->getEmpreinte();
        $sequence = $precedente === null ? 1 : $precedente->getNumeroSequence() + 1;

        $empreinte = $this->calculerEmpreinte($this->payloadCanonique($facture), $empreintePrecedente);

        $facture->setNumeroSequence($sequence);
        $facture->setEmpreintePrecedente($empreintePrecedente);
        $facture->setEmpreinte($empreinte);
        $facture->setSignature($this->signer($empreinte));

        return $facture;
    }

    public function dernierMaillon(Facture $facture): ?Facture
    {
        return $this->em->getRepository(Facture::class)->createQueryBuilder('f')
            ->andWhere('IDENTITY(f.profilExploitant) = :profil')
            ->andWhere('f.numeroSequence > 0')
            ->setParameter('profil', $facture->getProfilExploitant()?->getId(), 'uuid')
            ->orderBy('f.numeroSequence', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recalcule la chaîne d'un exploitant et détecte les ruptures : trou de séquence, empreinte
     * incohérente (donnée altérée), signature invalide.
     *
     * @param iterable<Facture> $factures
     *
     * @return array{intacte: bool, nbDocuments: int, anomalies: list<array<string, mixed>>}
     */
    public function verifieChaine(iterable $factures): array
    {
        $liste = [];
        foreach ($factures as $facture) {
            $liste[] = $facture;
        }
        usort($liste, static fn (Facture $a, Facture $b): int => $a->getNumeroSequence() <=> $b->getNumeroSequence());

        $anomalies = [];
        $attendue = 1;
        $empreintePrecedente = null;

        foreach ($liste as $facture) {
            $sequence = $facture->getNumeroSequence();
            if ($sequence !== $attendue) {
                $anomalies[] = ['sequence' => $sequence, 'probleme' => sprintf('trou de séquence : attendu %d, trouvé %d', $attendue, $sequence)];
                $attendue = $sequence;
            }
            if ($facture->getEmpreintePrecedente() !== $empreintePrecedente) {
                $anomalies[] = ['sequence' => $sequence, 'probleme' => 'chaînage rompu : empreinte précédente incohérente'];
            }

            $recalculee = $this->calculerEmpreinte($this->payloadCanonique($facture), $facture->getEmpreintePrecedente());
            if (!hash_equals($recalculee, $facture->getEmpreinte())) {
                $anomalies[] = ['sequence' => $sequence, 'probleme' => 'empreinte incohérente : la donnée a été altérée'];
            } elseif (!hash_equals($this->signer($facture->getEmpreinte()), $facture->getSignature())) {
                $anomalies[] = ['sequence' => $sequence, 'probleme' => 'signature invalide'];
            }

            $empreintePrecedente = $facture->getEmpreinte();
            ++$attendue;
        }

        return ['intacte' => $anomalies === [], 'nbDocuments' => \count($liste), 'anomalies' => $anomalies];
    }

    /** @return array<string, mixed> */
    private function payloadCanonique(Facture $facture): array
    {
        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            $lignes[] = [
                'id' => (string) $ligne->getId(),
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'ht' => $ligne->getMontantHT(),
                'tva' => $ligne->getMontantTva(),
                'taux' => $ligne->getTauxTvaValeur(),
            ];
        }
        // Tri déterministe par identifiant : la collection OneToMany peut revenir dans un ordre
        // différent entre le scellement (construction en mémoire) et une vérification ultérieure
        // (rechargée depuis la base) — sans ce tri l'empreinte ne serait pas reproductible.
        usort($lignes, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'numero' => $facture->getNumero(),
            'nature' => $facture->getNature()->value,
            'origine' => $facture->getOrigine()->value,
            'dateEmission' => $facture->getDateEmission()?->format('Y-m-d H:i:s'),
            'destinataire' => $facture->getDestinataire()?->denomination(),
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'factureCorrigee' => (string) $facture->getFactureCorrigee()?->getId(),
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
}
