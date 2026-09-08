<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `accounting_payment_method_treasury_account` : le compte de trésorerie par (exploitant, moyen de paiement).
 *
 * ── POURQUOI UNE TABLE ET PAS UNE COLONNE ───────────────────────────────────────────────────────
 *
 * `compta_moyen_paiement` est un référentiel GLOBAL — aucune colonne de rattachement — tandis que
 * `compta_compte_comptable` porte `profil_exploitant_id`. Une colonne `compte_tresorerie_id` sur le
 * moyen aurait donc fait partager UN compte à tous les exploitants : les encaissements de chacun
 * seraient allés dans le grand livre du premier qui l'a renseigné. Rien ne l'aurait signalé —
 * l'écriture reste équilibrée et le solde du client revient à zéro.
 *
 * ── LES DÉFAUTS SONT POSÉS ICI, ET C'EST UN CHOIX DE MAXIME (08/09) ─────────────────────────────
 *
 * Sans eux, la facturation s'arrêterait net au déploiement : aucun moyen n'ayant de compte, tout
 * règlement serait refusé jusqu'à ce que quelqu'un configure onze moyens sur chaque exploitant.
 *
 *     espèces                → 531000 Caisse          (repli : premier compte 531x du profil)
 *     CB, virement, PayFiP   → 512000 Banque          (repli : premier compte 512x)
 *     chèques (4 variantes)  → 511200 Chèques à encaisser (repli : premier compte 511x)
 *
 * ⚠ LE NUMÉRO EXACT D'ABORD, LE PRÉFIXE SEULEMENT EN REPLI — ET C'EST UNE CORRECTION.
 * La première version ne prenait que `MIN(numero)` sur le préfixe. Joué à blanc sur la préproduction
 * avant d'écrire quoi que ce soit, ça donnait, pour les quatre moyens « chèque », le compte **511000
 * « Recettes à classer — régie »** au lieu de **511200 « Chèques à encaisser »** : les chèques
 * seraient tombés dans un compte d'attente de régie, sur toutes les installations, sans que rien ne
 * proteste. Le préfixe reste néanmoins nécessaire en repli, parce que les deux semis de plan
 * comptable du dépôt ne posent pas les mêmes numéros — `AccountingChartSeeder` a 531000, 511200 et
 * 512000 ; le semis de démonstration n'a ni 531x ni 511200.
 *
 * ⚠ RIEN N'EST POSÉ POUR `avoir`, `differe` ET `pmv`. Ce ne sont pas des mouvements de trésorerie : un
 * avoir s'impute sur le compte de tiers, un paiement différé n'encaisse rien. Leur absence de compte
 * n'est pas un oubli à rattraper — c'est ce qui les distingue. Mesuré avant de trancher : aucun de ces
 * trois codes n'est utilisé comme moyen de règlement dans le dépôt.
 *
 * ⚠ UN PROFIL SANS COMPTE CORRESPONDANT NE REÇOIT PAS DE LIGNE, et c'est voulu : son premier
 * encaissement refusera en nommant le moyen et l'exploitant, plutôt que d'écrire sur un compte
 * choisi au hasard.
 */
final class Version20260908091500 extends AbstractMigration
{
    /** Par groupe de moyens : le compte EXACT préféré, puis le préfixe de repli. */
    private const CORRESPONDANCES = [
        ['exact' => '531000', 'prefixe' => '531%', 'codes' => ['especes']],
        ['exact' => '512000', 'prefixe' => '512%', 'codes' => ['cb', 'virement', 'payfip']],
        ['exact' => '511200', 'prefixe' => '511%', 'codes' => ['cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs']],
    ];

    public function getDescription(): string
    {
        return 'accounting_payment_method_treasury_account : compte de trésorerie par (exploitant, moyen), avec ses défauts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounting_payment_method_treasury_account (
            id BINARY(16) NOT NULL,
            business_profile_id BINARY(16) NOT NULL,
            payment_method_id BINARY(16) NOT NULL,
            treasury_account_id BINARY(16) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_treasury_account_profile_method (business_profile_id, payment_method_id),
            KEY IDX_51C9CB1DC591A13 (business_profile_id),
            KEY IDX_51C9CB1D5AA1164F (payment_method_id),
            KEY IDX_51C9CB1D1E713C9D (treasury_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('ALTER TABLE accounting_payment_method_treasury_account ADD CONSTRAINT FK_51C9CB1DC591A13 FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE accounting_payment_method_treasury_account ADD CONSTRAINT FK_51C9CB1D5AA1164F FOREIGN KEY (payment_method_id) REFERENCES compta_moyen_paiement (id)');
        $this->addSql('ALTER TABLE accounting_payment_method_treasury_account ADD CONSTRAINT FK_51C9CB1D1E713C9D FOREIGN KEY (treasury_account_id) REFERENCES compta_compte_comptable (id)');

        foreach (self::CORRESPONDANCES as $correspondance) {
            foreach ($correspondance['codes'] as $code) {
                // Requête paramétrée de bout en bout : aucune concaténation (CLAUDE.md).
                // `COALESCE(exact, préfixe)` porte la préférence, et `MIN` rend le repli déterministe
                // quand un profil porte plusieurs comptes du même préfixe.
                $this->addSql(
                    'INSERT INTO accounting_payment_method_treasury_account (id, business_profile_id, payment_method_id, treasury_account_id)
                     SELECT UNHEX(REPLACE(UUID(), :tiret, :vide)), p.id, m.id, c.id
                       FROM compta_profil_exploitant p
                       JOIN compta_moyen_paiement m ON m.code = :code
                       JOIN compta_compte_comptable c
                         ON c.profil_exploitant_id = p.id
                        AND c.numero = (
                              SELECT COALESCE(
                                       MIN(CASE WHEN c2.numero = :exact THEN c2.numero END),
                                       MIN(CASE WHEN c2.numero LIKE :prefixe THEN c2.numero END)
                                     )
                                FROM compta_compte_comptable c2
                               WHERE c2.profil_exploitant_id = p.id
                            )',
                    [
                        'tiret' => '-',
                        'vide' => '',
                        'code' => $code,
                        'exact' => $correspondance['exact'],
                        'prefixe' => $correspondance['prefixe'],
                    ],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accounting_payment_method_treasury_account');
    }
}
