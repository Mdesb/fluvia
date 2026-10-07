<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA SERIE DE NUMEROTATION SUIT L'ANNEE, PLUS LA PERIODE COMPTABLE.
 *
 * ── LE DEFAUT, MESURE SUR LA PREPRODUCTION LE 14/09/2026 ────────────────────────────────────────
 *
 * `PeriodeComptableResolver` cree une periode par MOIS (`Y-m-01` -> `Y-m-t`). Le compteur de
 * `facturation_serie_numerotation` etait verrouille sur `(profil, periode, prefixe)`, donc sur le
 * mois — alors que `GenerateurNumeroFacture` compose un numero qui ne porte que l'ANNEE
 * (`FA-2026-00001`) et que `uniq_facture_numero` est unique sur ce numero seul.
 *
 * Consequence : la premiere facture de chaque nouveau mois reprend le numero de la premiere du mois
 * precedent, et la contrainte la rejette. Aucune facture de septembre ne pouvait etre emise sur la
 * preproduction, celle d'aout portant deja `FA-2026-00001`. Le blocage vaut pour tout exploitant
 * des son deuxieme mois de facturation.
 *
 * Le contrat etait pourtant deja ecrit : le docbloc de `ScellementFactureHandler` decrit la serie
 * comme portee par « (profilExploitant, exercice, prefixe) ». C'est l'implementation qui s'en
 * ecartait.
 *
 * ── LE COMPTEUR SE RECALCULE DEPUIS LES NUMEROS REELLEMENT EMIS ─────────────────────────────────
 *
 * ⚠ ON NE FUSIONNE PAS LES COMPTEURS EN PRENANT LEUR MAXIMUM. Ce serait deduire l'etat d'un
 * registre depuis un autre registre qui, precisement, etait faux. On le lit a la SOURCE : le plus
 * grand numero effectivement porte par une facture de cet exploitant, pour ce prefixe et cette
 * annee. Un compteur ainsi recalcule ne peut pas rendre un numero deja pris, meme si les lignes
 * fusionnees etaient incoherentes entre elles.
 *
 * Un exploitant sans aucune facture numerotee sur l'annee repart donc de 0, ce qui est juste.
 *
 * @drop-voulu : `periode_id` disparait — avec sa cle etrangere et son index — parce que c'est
 * ELLE le defaut. Tant que le compteur est porte par la periode, il est mensuel, et le numero
 * qu'il alimente est annuel : les deux ne peuvent pas coexister. La colonne n'est pas remplacee
 * par une autre information, elle est remplacee par `exercice`, qui repond a la meme question a la
 * bonne maille. Aucune donnee n'est perdue : l'annee se deduit de la periode (etape 2), et le
 * compteur se recalcule depuis les numeros reellement emis (etape 4), qui sont la source. Le
 * `down()` rattache chaque serie a la premiere periode de son annee, et refuse plutot que
 * d'inventer s'il n'en trouve aucune.
 *
 * ⚠ LA CHAINE NF525 N'EST PAS TOUCHEE. `facturation_facture.numero_sequence` est une SECONDE
 * sequence, continue par exploitant et sans remise a zero annuelle, calculee par
 * `ScellementFactureHandler` depuis le dernier maillon. Elle ne passe pas par cette table. Aucune
 * facture scellee n'est modifiee ici : la migration ne touche que le compteur.
 */
final class Version20260914170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'La serie de numerotation des factures est portee par l annee, plus par la periode comptable.';
    }

    public function up(Schema $schema): void
    {
        // 1. La colonne, sans defaut definitif : elle est renseignee juste apres.
        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD exercice SMALLINT UNSIGNED NOT NULL DEFAULT 0');

        // 2. L'annee de chaque ligne, lue sur le debut de sa periode. Une periode va du 1er au
        //    dernier jour d'un mois : elle ne chevauche jamais deux annees.
        $this->addSql(<<<'SQL'
            UPDATE facturation_serie_numerotation s
            JOIN compta_periode_comptable p ON p.id = s.periode_id
            SET s.exercice = YEAR(p.date_debut)
            SQL);

        // 3. On ecarte les doublons AVANT de poser la contrainte : plusieurs mois d'une meme annee
        //    ont pu produire plusieurs lignes pour le meme couple (profil, prefixe). On garde la
        //    plus ancienne par son identifiant — le choix est indifferent, puisque l'etape 4
        //    recalcule sa valeur depuis les factures emises.
        $this->addSql(<<<'SQL'
            DELETE s FROM facturation_serie_numerotation s
            JOIN facturation_serie_numerotation garde
              ON garde.profil_exploitant_id = s.profil_exploitant_id
             AND garde.exercice = s.exercice
             AND garde.prefixe = s.prefixe
             AND garde.id < s.id
            SQL);

        // 4. Le compteur se recalcule depuis les numeros REELLEMENT emis. `FA-2026-00042` se decoupe
        //    en trois segments sur le tiret : le prefixe, l'annee, la sequence. `CAST` ignore les
        //    zeros de tete. `COALESCE` a 0 pour un exploitant qui n'a encore rien numerote.
        $this->addSql(<<<'SQL'
            UPDATE facturation_serie_numerotation s
            SET s.dernier_numero = COALESCE((
                SELECT MAX(CAST(SUBSTRING_INDEX(f.numero, '-', -1) AS UNSIGNED))
                FROM facturation_facture f
                WHERE f.profil_exploitant_id = s.profil_exploitant_id
                  AND f.numero IS NOT NULL
                  AND SUBSTRING_INDEX(f.numero, '-', 1) = s.prefixe
                  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f.numero, '-', 2), '-', -1) AS UNSIGNED) = s.exercice
            ), 0)
            SQL);

        // 5. La periode s'en va : sa cle etrangere, son index, puis la colonne.
        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP FOREIGN KEY FK_4D41722DF384C1CF');
        $this->addSql('DROP INDEX IDX_4D41722DF384C1CF ON facturation_serie_numerotation');
        $this->addSql('DROP INDEX uniq_facturation_serie ON facturation_serie_numerotation');
        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP periode_id');

        // 6. La contrainte qui remplace l'ancienne, sur l'annee.
        $this->addSql('CREATE UNIQUE INDEX uniq_facturation_serie ON facturation_serie_numerotation (profil_exploitant_id, exercice, prefixe)');
    }

    /**
     * ⚠ LE RETOUR EN ARRIERE NE RECONSTITUE PAS LA PERIODE, ET IL NE LE PEUT PAS.
     *
     * Une ligne annuelle a pu naitre de la fusion de douze lignes mensuelles : rien ne dit laquelle
     * rendre. On rattache donc chaque serie a la PREMIERE periode de son annee pour cet exploitant,
     * ce qui satisfait la contrainte sans pretendre restaurer l'etat d'avant. Une serie dont
     * l'exploitant n'a aucune periode sur l'annee ne peut pas etre rattachee : la migration refuse
     * plutot que d'en inventer une.
     */
    public function down(Schema $schema): void
    {
        // ⚠ LE CONTROLE PASSE AVANT TOUT, ET IL INTERROGE L'ETAT ACTUEL — PAS CELUI D'APRES.
        //
        // Ma premiere version ajoutait `periode_id`, la remplissait, puis comptait les lignes
        // restees nulles. Elle echouait a tous les coups :
        //
        //     SQLSTATE[42S22]: Unknown column 'periode_id' in 'WHERE'
        //
        // `addSql()` EMPILE ; les instructions ne partent qu'apres le retour de la methode. Un
        // `$this->connection->fetchOne()` ecrit au milieu, lui, part IMMEDIATEMENT — donc avant
        // l'ALTER qui cree la colonne qu'il interroge. Les deux mecanismes se lisent dans le meme
        // sens de haut en bas et ne s'executent pas dans cet ordre.
        //
        // Le controle porte donc sur ce qui existe DEJA : chaque serie a-t-elle au moins une periode
        // dans son annee ? S'il manque quoi que ce soit, on refuse avant d'avoir touche au schema,
        // ce qui vaut mieux que d'echouer a mi-chemin.
        $orphelines = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM facturation_serie_numerotation s
            WHERE NOT EXISTS (
                SELECT 1 FROM compta_periode_comptable p
                WHERE p.profil_exploitant_id = s.profil_exploitant_id
                  AND YEAR(p.date_debut) = s.exercice
            )
            SQL);

        $this->abortIf(
            $orphelines > 0,
            sprintf(
                'Retour en arriere impossible : %d serie(s) n ont aucune periode comptable sur leur annee. '
                . 'Creez-la, ou rattachez-les a la main, avant de redescendre cette migration.',
                $orphelines,
            )
        );

        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD periode_id BINARY(16) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE facturation_serie_numerotation s
            SET s.periode_id = (
                SELECT p.id FROM compta_periode_comptable p
                WHERE p.profil_exploitant_id = s.profil_exploitant_id
                  AND YEAR(p.date_debut) = s.exercice
                ORDER BY p.date_debut ASC
                LIMIT 1
            )
            SQL);

        $this->addSql('ALTER TABLE facturation_serie_numerotation MODIFY periode_id BINARY(16) NOT NULL');
        $this->addSql('DROP INDEX uniq_facturation_serie ON facturation_serie_numerotation');
        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP exercice');
        $this->addSql('CREATE UNIQUE INDEX uniq_facturation_serie ON facturation_serie_numerotation (profil_exploitant_id, periode_id, prefixe)');
        $this->addSql('CREATE INDEX IDX_4D41722DF384C1CF ON facturation_serie_numerotation (periode_id)');
        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD CONSTRAINT FK_4D41722DF384C1CF FOREIGN KEY (periode_id) REFERENCES compta_periode_comptable (id)');
    }
}
