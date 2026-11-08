<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Platform\Nf525\SealCheck;
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
                   ON x.point_de_vente_id = o.point_de_vente_id AND x.m = o.numero_sequence'],
            [$this->accountingKeyring, 'comptabilité (par exploitant et journal)',
                'SELECT CONCAT(LOWER(HEX(e.profil_exploitant_id)), "/", LOWER(HEX(e.journal_id))) AS chain_ref, e.numero_sequence AS seq, e.empreinte AS fingerprint, e.signature AS signature, e.seal_key_id AS key_id
                 FROM compta_ecriture_comptable e
                 JOIN (SELECT profil_exploitant_id, journal_id, MAX(numero_sequence) AS m FROM compta_ecriture_comptable WHERE numero_sequence > 0 GROUP BY profil_exploitant_id, journal_id) x
                   ON x.profil_exploitant_id = e.profil_exploitant_id AND x.journal_id = e.journal_id AND x.m = e.numero_sequence'],
            [$this->invoicingKeyring, 'facturation (par exploitant)',
                'SELECT LOWER(HEX(f.profil_exploitant_id)) AS chain_ref, f.numero_sequence AS seq, f.empreinte AS fingerprint, f.signature AS signature, f.seal_key_id AS key_id
                 FROM facturation_facture f
                 JOIN (SELECT profil_exploitant_id, MAX(numero_sequence) AS m FROM facturation_facture WHERE numero_sequence > 0 GROUP BY profil_exploitant_id) x
                   ON x.profil_exploitant_id = f.profil_exploitant_id AND x.m = f.numero_sequence'],
        ];

        $blocked = 0;
        foreach ($chains as [$keyring, $label, $sql]) {
            /** @var SealKeyring $keyring */
            $rows = $this->connection->fetchAllAssociative($sql);
            $bad = 0;
            foreach ($rows as $row) {
                $keyId = $row['key_id'] === null ? null : (string) $row['key_id'];
                $check = $keyring->check((string) $row['fingerprint'], (string) $row['signature'], $keyId);
                if ($check !== SealCheck::Valid) {
                    ++$bad;
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
                '%d chaîne(s) NF525 ne peuvent plus être prolongées : leur dernier maillon ne se vérifie avec aucune clé du trousseau. '
                . 'L\'application REFUSERA d\'y sceller. Restaurez l\'ancienne clé (variable *_SEAL_KEY) ou ajoutez-la '
                . 'aux clés retirées (*_SEAL_RETIRED_KEYS) depuis la sauvegarde hors serveur — infra/README.md, « Trousseau NF525 ».',
                $blocked,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** Ce qu'on affiche de la clé d'un maillon : son identifiant, ou l'absence d'identifiant. */
    private function provenance(?string $id): string
    {
        return $id === null ? 'sans identifiant, antérieur au trousseau' : $id;
    }
}
