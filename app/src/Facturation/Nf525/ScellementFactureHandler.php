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

        // ⚠ L'INSTANTANÉ EST CONSERVÉ, PLUS RECONSTRUIT. Sans lui, la vérification relisait les
        // entités VIVANTES : un taux de TVA corrigé — geste légitime, un décret change les taux —
        // faisait dériver l'empreinte recalculée de tout document scellé avec ce taux. Même patron
        // que `OperationScellee`, la seule des trois chaînes qui faisait bien.
        $payload = $this->payloadCanonique($facture);
        $empreinte = $this->calculerEmpreinte($payload, $empreintePrecedente);

        $facture->setNumeroSequence($sequence);
        $facture->setEmpreintePrecedente($empreintePrecedente);
        $facture->setEmpreinte($empreinte);
        $facture->setSignature($this->signer($empreinte));
        $facture->setPayloadCanonique($payload);

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

            // ⚠ DEUX CAS QUE L'ANCIEN CODE CONFONDAIT, ET LE MOT COMPTE.
            //
            // Avec l'instantané stocké, un écart PROUVE une altération : on compare la donnée à
            // elle-même. Sans lui, on ne compare qu'à une reconstruction — et un référentiel qui a
            // bougé depuis suffit à la faire différer, sans que rien n'ait été touché.
            //
            // L'ancien message disait « la donnée a été altérée » dans les deux cas. Le logiciel
            // accusait son utilisateur de falsification pour un geste qu'il l'autorise lui-même à
            // faire. Devant un expert-comptable, c'est le pire mot possible.
            $instantane = $facture->getPayloadCanonique();
            $recalculee = $this->calculerEmpreinte($instantane ?? $this->payloadCanonique($facture), $facture->getEmpreintePrecedente());

            // ⚠ SECONDE GARANTIE, INDEPENDANTE DE LA PREMIERE. L'empreinte prouve que la chaîne est
            // entière ; elle ne dit rien de ce qui a pu être touché en base DEPUIS le scellement,
            // puisqu'on la recalcule depuis une copie que personne n'atteint.
            if ($instantane !== null && !$this->instantaneDecritEncore($instantane, $facture)) {
                $anomalies[] = [
                    'sequence' => $sequence,
                    'probleme' => 'la donnée a été altérée depuis le scellement : le document en base ne correspond plus à ce qui a été scellé',
                ];
            }

            if (!hash_equals($recalculee, $facture->getEmpreinte())) {
                $anomalies[] = [
                    'sequence' => $sequence,
                    'probleme' => $instantane !== null
                        ? 'empreinte incohérente : la donnée a été altérée'
                        : 'non vérifiable : ce document a été scellé avant que l\'instantané ne soit conservé, et sa reconstruction ne redonne pas l\'empreinte — un référentiel a pu changer depuis',
                ];
            } elseif (!hash_equals($this->signer($facture->getEmpreinte()), $facture->getSignature())) {
                // ⚠ ON N'ARRIVE ICI QUE SI LES DEUX CONTROLES PRECEDENTS SONT PASSES, ET CA CHANGE
                // TOUT CE QU'ON PEUT DIRE.
                //
                // L'instantané décrit encore la facture : le contenu en base n'a pas bougé.
                // L'empreinte recalculée égale la scellée : la chaîne est entière.
                // Seule la signature diffère — or `signer()` est `hash_hmac(sha256, empreinte,
                // clé)`, et l'empreinte vient d'être vérifiée identique. Les seules entrées qui
                // peuvent différer sont donc LA CLÉ et la signature stockée.
                //
                // L'ancien message disait « signature invalide », et rien d'autre. Mesuré sur la
                // préproduction : facture scellée le 28/08 à 22:57, fichier portant
                // `NF525_FACTURATION_SEAL_KEY` modifié le 31/08 à 17:33 — une clé remplacée, et un
                // message qui laissait craindre une falsification.
                //
                // ⚠ MAIS ON NE BASCULE PAS DANS L'ERREUR SYMÉTRIQUE. Quelqu'un qui altérerait la
                // donnée ET recalculerait l'empreinte produirait exactement ce résultat, sans
                // pouvoir forger la signature faute de clé. Les deux causes sont donc nommées, et
                // celle qui se vérifie en une commande est désignée comme telle.
                $anomalies[] = [
                    'sequence' => $sequence,
                    'probleme' => 'signature invalide, mais le contenu de ce document est intact : '
                        . 'son empreinte se vérifie et la chaîne est entière. Seule la signature ne '
                        . 'correspond pas. Deux causes possibles, et une seule se vérifie tout de '
                        . 'suite : la clé de scellement n\'est plus celle qui a signé (remplacement '
                        . 'ou restauration d\'environnement), ou la signature stockée a été touchée. '
                        . 'Comparez `NF525_FACTURATION_SEAL_KEY` à celle en vigueur au '
                        . 'scellement avant de conclure.',
                ];
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
    public function reprendreInstantane(Facture $facture): bool
    {
        if ($facture->getPayloadCanonique() !== null) {
            return true;
        }

        if ($facture->getEmpreinte() === '') {
            return false;
        }

        $instantane = $this->payloadCanonique($facture);
        $recalculee = $this->calculerEmpreinte($instantane, $facture->getEmpreintePrecedente());

        if (!hash_equals($recalculee, $facture->getEmpreinte())) {
            return false;
        }

        $facture->setPayloadCanonique($instantane);

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
    private function instantaneDecritEncore(array $instantane, Facture $facture): bool
    {
        $vivant = $this->payloadCanonique($facture);

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
