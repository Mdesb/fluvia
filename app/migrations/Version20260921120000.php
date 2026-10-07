<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Moyen de paiement « prélèvement » (SEPA) au référentiel comptable + son compte de trésorerie par
 * exploitant.
 *
 * POURQUOI. L'encaissement automatique des échéances d'abonnement (SEPA) écrit son règlement au
 * journal ENC. Faute de code « prélèvement » au référentiel, il l'enregistrait sous « virement » :
 * écriture JUSTE (même compte 512), mais libellé imprécis. Ce moyen dédié rend le libellé exact.
 *
 * ⚠ INACTIF (actif = 0). C'est un moyen SYSTÈME : l'encaissement d'un prélèvement SEPA est
 * automatique, il ne se choisit pas à un pavé de caisse. Le service le retrouve par son code ; les
 * listes manuelles (caisse, souscription) écartent les moyens inactifs.
 *
 * ⚠ COMPTE DE TRÉSORERIE = CELUI DU VIREMENT, PAR EXPLOITANT. Un prélèvement crédite le même compte
 * banque (512) qu'un virement. On copie donc, pour chaque profil, le mapping de son « virement ».
 * Idempotent (NOT EXISTS). Écrite à la main (pas de `migrations:diff`, qui ratisserait la dérive des
 * autres sessions) et MIROIR de `ComptaFixtures` : le harnais de test bâtit le schéma depuis le
 * mapping et ne joue jamais les migrations, donc les deux chemins posent la même chose, chacun de son
 * côté. Si tu touches l'un, touche l'autre.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moyen de paiement « prélèvement » (SEPA, inactif) + compte de trésorerie 512 par exploitant.';
    }

    public function up(Schema $schema): void
    {
        // Le moyen, inactif. INSERT IGNORE : le code est unique, un rejeu ne double pas.
        $this->addSql(
            'INSERT IGNORE INTO compta_moyen_paiement '
            . '(id, code, libelle, autorise_rendu, exige_reference, autorise_differe, actif) '
            . 'VALUES (?, ?, ?, 0, 0, 0, 0)',
            [Uuid::v4()->toBinary(), 'prelevement', 'Prélèvement SEPA'],
        );

        // Le compte de trésorerie du prélèvement = celui du virement de CHAQUE exploitant (même 512).
        // NOT EXISTS : n'ajoute que ce qui manque, un rejeu ne double pas.
        $this->addSql(<<<'SQL'
            INSERT INTO accounting_payment_method_treasury_account
                (id, business_profile_id, payment_method_id, treasury_account_id)
            SELECT UNHEX(REPLACE(UUID(), '-', '')),
                   t.business_profile_id,
                   (SELECT id FROM compta_moyen_paiement WHERE code = 'prelevement'),
                   t.treasury_account_id
            FROM accounting_payment_method_treasury_account t
            JOIN compta_moyen_paiement mv ON mv.id = t.payment_method_id AND mv.code = 'virement'
            WHERE NOT EXISTS (
                SELECT 1 FROM accounting_payment_method_treasury_account x
                WHERE x.business_profile_id = t.business_profile_id
                  AND x.payment_method_id = (SELECT id FROM compta_moyen_paiement WHERE code = 'prelevement')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE t FROM accounting_payment_method_treasury_account t
            JOIN compta_moyen_paiement m ON m.id = t.payment_method_id
            WHERE m.code = 'prelevement'
            SQL);
        $this->addSql("DELETE FROM compta_moyen_paiement WHERE code = 'prelevement'");
    }
}
