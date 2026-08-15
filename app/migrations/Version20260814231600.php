<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L4 Comptabilité & Régie (M6) — migration de données : seed du référentiel des moyens de paiement
 * (§3 du plan-compta), **identique au jeu par défaut du stub L2** (`ReferentielReglementStub`) pour
 * non-régression de M2 lors du bascule d'alias (`services.yaml`). Administrable ensuite via
 * `compta.gerer`. Insertion idempotente (INSERT IGNORE, code unique).
 */
final class Version20260814231600 extends AbstractMigration
{
    /** @var list<array{string, string, bool, bool, bool}> code, libellé, autoriseRendu, exigeReference, autoriseDiffere */
    private const MOYENS = [
        ['especes', 'Espèces', true, false, false],
        ['cb', 'Carte bancaire (TPE)', false, true, false],
        ['cheque', 'Chèque', false, false, false],
        ['virement', 'Virement', false, false, false],
        ['cheque_vacances', 'Chèques Vacances', false, false, false],
        ['cheque_culture', 'Chèque Culture', false, false, false],
        ['cheque_loisirs', 'Chèque Loisirs', false, false, false],
        ['pmv', 'Passe / Monnaie de ville (PMV)', false, false, false],
        ['avoir', 'Avoir', false, false, false],
        ['differe', 'Paiement différé', false, false, true],
        ['payfip', 'PayFiP (DGFiP)', false, true, false],
    ];

    public function getDescription(): string
    {
        return 'L4 (Comptabilité & Régie) : seed du référentiel des moyens de paiement (identique au stub L2, RG-M2-02).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::MOYENS as [$code, $libelle, $rendu, $reference, $differe]) {
            $this->addSql(
                'INSERT IGNORE INTO compta_moyen_paiement (id, code, libelle, autorise_rendu, exige_reference, autorise_differe, actif) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [Uuid::v4()->toBinary(), $code, $libelle, $rendu ? 1 : 0, $reference ? 1 : 0, $differe ? 1 : 0],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $codes = array_map(static fn (array $m): string => $m[0], self::MOYENS);
        $this->addSql('DELETE FROM compta_moyen_paiement WHERE code IN (' . implode(',', array_fill(0, \count($codes), '?')) . ')', $codes);
    }
}
