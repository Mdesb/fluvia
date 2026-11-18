<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Platform\Nf525\KeyRun;
use App\Platform\Nf525\SealCheck;
use App\Platform\Nf525\SealKeyOrder;
use App\Platform\Nf525\SealKeyring;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * #361 — LES TROUSSEAUX NF525 VÉRIFIENT-ILS ENCORE LE DERNIER MAILLON DE CHAQUE CHAÎNE ?
 *
 * Lancée par les scripts de déploiement après les migrations. Si une clé active a changé sans que
 * l'ancienne soit gardée au trousseau, l'application refusera de sceller sur cette chaîne (échec
 * fermé, `SealKeyring::assertCanExtend`) : on veut l'apprendre AU DÉPLOIEMENT, pas à la première vente.
 *
 * #369 — ET L'ORDRE DES CLÉS SUR TOUTE LA LONGUEUR. Au-delà du dernier maillon, la commande lit, pour
 * chaque chaîne (vente par point de vente, compta par exploitant et journal, facturation par exploitant), la
 * première et la dernière séquence de CHAQUE identifiant de clé présent (GROUP BY), y compris ceux qui ne sont
 * pas au trousseau : le refus de sceller ne les voit pas (limite D-3 du plan), la commande oui. Une chaîne où
 * deux clés s'entrelacent (`A,B,A`) ou où un maillon sans identifiant suit un maillon signé est BLOQUÉE
 * même si son dernier maillon est valide : exit 1. Les identifiants sont comparés octet par octet (`BINARY`),
 * la collation de la colonne les confondant sinon.
 *
 * MESURE AVANT FUSION, EN LECTURE SEULE : voir infra/README.md, « Trousseau NF525 » (appel exact).
 *
 * LECTURE SEULE : aucun maillon n'est écrit, aucune alerte n'est persistée, aucune clé n'est affichée
 * (seulement des identifiants non réversibles). SQL direct sur les trois tables, pour ne dépendre
 * d'aucun module.
 */
#[AsCommand(
    name: 'app:nf525:check-keys',
    description: 'Vérifie, en lecture seule, que chaque chaîne NF525 peut encore être prolongée avec le trousseau en place.',
)]
final class CheckSealKeysCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'app.nf525.keyring.sales')]
        private readonly SealKeyring $salesKeyring,
        #[Autowire(service: 'app.nf525.keyring.accounting')]
        private readonly SealKeyring $accountingKeyring,
        #[Autowire(service: 'app.nf525.keyring.invoicing')]
        private readonly SealKeyring $invoicingKeyring,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $chains = [
            [$this->salesKeyring, 'vente (par point de vente)',
                'SELECT LOWER(HEX(o.point_de_vente_id)) AS chain_ref, o.numero_sequence AS seq, o.empreinte AS fingerprint, o.signature AS signature, o.seal_key_id AS key_id
                 FROM nf525_operation_scellee o
                 JOIN (SELECT point_de_vente_id, MAX(numero_sequence) AS m FROM nf525_operation_scellee GROUP BY point_de_vente_id) x
                   ON x.point_de_vente_id = o.point_de_vente_id AND x.m = o.numero_sequence',
                'SELECT LOWER(HEX(point_de_vente_id)) AS chain_ref, BINARY seal_key_id AS key_id, MIN(numero_sequence) AS first_seq, MAX(numero_sequence) AS last_seq
                 FROM nf525_operation_scellee GROUP BY point_de_vente_id, BINARY seal_key_id'],
            [$this->accountingKeyring, 'comptabilité (par exploitant et journal)',
                'SELECT CONCAT(LOWER(HEX(e.profil_exploitant_id)), "/", LOWER(HEX(e.journal_id))) AS chain_ref, e.numero_sequence AS seq, e.empreinte AS fingerprint, e.signature AS signature, e.seal_key_id AS key_id
                 FROM compta_ecriture_comptable e
                 JOIN (SELECT profil_exploitant_id, journal_id, MAX(numero_sequence) AS m FROM compta_ecriture_comptable WHERE numero_sequence > 0 GROUP BY profil_exploitant_id, journal_id) x
                   ON x.profil_exploitant_id = e.profil_exploitant_id AND x.journal_id = e.journal_id AND x.m = e.numero_sequence',
                'SELECT CONCAT(LOWER(HEX(profil_exploitant_id)), "/", LOWER(HEX(journal_id))) AS chain_ref, BINARY seal_key_id AS key_id, MIN(numero_sequence) AS first_seq, MAX(numero_sequence) AS last_seq
                 FROM compta_ecriture_comptable WHERE numero_sequence > 0 GROUP BY profil_exploitant_id, journal_id, BINARY seal_key_id'],
            [$this->invoicingKeyring, 'facturation (par exploitant)',
                'SELECT LOWER(HEX(f.profil_exploitant_id)) AS chain_ref, f.numero_sequence AS seq, f.empreinte AS fingerprint, f.signature AS signature, f.seal_key_id AS key_id
                 FROM facturation_facture f
                 JOIN (SELECT profil_exploitant_id, MAX(numero_sequence) AS m FROM facturation_facture WHERE numero_sequence > 0 GROUP BY profil_exploitant_id) x
                   ON x.profil_exploitant_id = f.profil_exploitant_id AND x.m = f.numero_sequence',
                'SELECT LOWER(HEX(profil_exploitant_id)) AS chain_ref, BINARY seal_key_id AS key_id, MIN(numero_sequence) AS first_seq, MAX(numero_sequence) AS last_seq
                 FROM facturation_facture WHERE numero_sequence > 0 GROUP BY profil_exploitant_id, BINARY seal_key_id'],
        ];

        $blocked = 0;
        foreach ($chains as [$keyring, $label, $sql, $runsSql]) {
            /** @var SealKeyring $keyring */
            $rows = $this->connection->fetchAllAssociative($sql);
            $badRefs = []; // références de chaînes bloquées : une chaîne fautive aux deux contrôles compte UNE fois
            foreach ($rows as $row) {
                $keyId = $row['key_id'] === null ? null : (string) $row['key_id'];
                $check = $keyring->check((string) $row['fingerprint'], (string) $row['signature'], $keyId);
                if ($check !== SealCheck::Valid) {
                    $badRefs[(string) $row['chain_ref']] = true;
                    $io->writeln(sprintf(
                        '  ✗ %s — chaîne %s, dernier maillon n° %d : %s (clé du maillon : %s)',
                        $keyring->variable(),
                        (string) $row['chain_ref'],
                        (int) $row['seq'],
                        $check === SealCheck::UnknownKey ? 'clé inconnue du trousseau' : 'signature invalide avec toutes les clés du trousseau',
                        $this->provenance($keyId),
                    ));
                }
            }
            foreach ($this->checkOrder($io, $keyring, $runsSql) as $ref) {
                $badRefs[$ref] = true;
            }
            $bad = \count($badRefs);
            $blocked += $bad;
            $io->writeln(sprintf(
                '%s %s : %d chaîne(s), %d bloquée(s) — clé active %s, %d clé(s) retirée(s).',
                $bad === 0 ? '✓' : '✗',
                $label,
                \count($rows),
                $bad,
                $keyring->activeKeyId(),
                \count($keyring->retiredKeyIds()),
            ));
        }

        if ($blocked > 0) {
            $io->error(sprintf(
                '%d chaîne(s) NF525 bloquée(s). Deux causes possibles. (1) Le dernier maillon ne se vérifie avec aucune clé du '
                . 'trousseau : l\'application REFUSERA d\'y sceller ; restaurez l\'ancienne clé ou ajoutez-la aux clés retirées '
                . '(*_SEAL_RETIRED_KEYS) depuis la sauvegarde hors serveur. (2) Une clé quittée par la chaîne y est revenue '
                . '(régression de clé : ancienne clé reposée comme clé active, ou maillon forgé) : ne remettez jamais une ancienne clé, '
                . 'passez à une clé NEUVE. Règle et procédure : infra/README.md, « Trousseau NF525 ».',
                $blocked,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * #369 — la règle d'ordre sur TOUTE la longueur de chaque chaîne (toutes les clés présentes, trousseau ou non).
     * Une ligne `✗` par chaîne en régression ; rend les références de ces chaînes. L'appelant les fusionne avec
     * celles du contrôle du dernier maillon, pour qu'une chaîne en défaut des deux ne compte qu'une fois.
     *
     * @return list<string>
     */
    private function checkOrder(SymfonyStyle $io, SealKeyring $keyring, string $runsSql): array
    {
        /** @var array<string, array<string, array{0: int, 1: int}>> $byChain référence => (clé 'n' | 'k<id>') => [première, dernière] */
        $byChain = [];
        /** @var array<string, array<string, ?string>> $ids */
        $ids = [];
        foreach ($this->connection->fetchAllAssociative($runsSql) as $row) {
            $keyId = $row['key_id'] === null || $row['key_id'] === '' ? null : (string) $row['key_id'];
            $slot = $keyId === null ? 'n' : 'k' . $keyId;
            $ref = (string) $row['chain_ref'];
            $first = (int) $row['first_seq'];
            $last = (int) $row['last_seq'];
            $byChain[$ref][$slot] = isset($byChain[$ref][$slot])
                ? [min($byChain[$ref][$slot][0], $first), max($byChain[$ref][$slot][1], $last)]
                : [$first, $last];
            $ids[$ref][$slot] = $keyId;
        }

        $bad = [];
        foreach ($byChain as $ref => $bounds) {
            try {
                $runs = [];
                foreach ($bounds as $slot => [$first, $last]) {
                    $runs[] = new KeyRun($ids[$ref][$slot], $first, $last);
                }
                $conflicts = SealKeyOrder::conflicts($runs);
            } catch (\InvalidArgumentException) {
                $bad[] = (string) $ref;
                $io->writeln(sprintf('  ✗ %s — chaîne %s : séquence impossible (≤ 0) ou intervalles illisibles, l\'ordre des clés ne peut pas être établi.', $keyring->variable(), $ref));
                continue;
            }
            if ($conflicts !== []) {
                $bad[] = (string) $ref;
                $io->writeln(sprintf(
                    '  ✗ %s — chaîne %s : régression de clé, la clé %s et la clé %s s\'entrelacent (une clé quittée est revenue ; clé active : %s).',
                    $keyring->variable(),
                    $ref,
                    SealKeyring::safeId($conflicts[0][0]),
                    SealKeyring::safeId($conflicts[0][1]),
                    $keyring->activeKeyId(),
                ));
            }
        }

        return $bad;
    }

    /** Ce qu'on affiche de la clé d'un maillon : son identifiant, ou l'absence d'identifiant. */
    private function provenance(?string $id): string
    {
        return $id === null ? 'sans identifiant, antérieur au trousseau' : SealKeyring::safeId($id);
    }
}
