<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ALIGNE `sport_echeance_sepa` SUR CE QUE LE MAPPING PRODUIT.
 *
 * ── COMMENT L'ÉCART A ÉTÉ TROUVÉ ───────────────────────────────────────────────────────────────
 *
 * `Version20260906100000` a été écrite à la main — c'est la règle ici, un `migrations:diff`
 * ratisserait la dérive des huit autres sessions ouvertes. Écrire à la main veut dire pouvoir se
 * tromper : après l'avoir jouée, `doctrine:schema:update --dump-sql | grep sport_echeance_sepa`
 * rendait deux lignes. C'est la vérification que le docblock de `Version20260906010000` décrit, et
 * elle a servi.
 *
 * ── DEUX ERREURS DE MA MAIN, ET UNE QUI NE L'EST PAS ───────────────────────────────────────────
 *
 * 1. J'ai écrit `COMMENT '(DC2Type:datetime_immutable)'` et `COMMENT '(DC2Type:uuid)'` en copiant
 *    une convention d'un autre dépôt. Ce projet ne les pose pas : Doctrine demandait donc de les
 *    retirer à chaque comparaison. Un commentaire de type qui ne correspond pas au mapping est une
 *    dérive permanente — `schema:update` reste bavard, et le jour où il dit quelque chose de vrai,
 *    plus personne ne le lit.
 *
 * 2. J'ai nommé l'index `idx_sport_echeance_reduction_par`. Doctrine génère `IDX_BD6EBE25AFCB1B7A`
 *    et veut le sien. Le nom lisible n'était pas un progrès : il produisait un `RENAME INDEX` à
 *    perpétuité.
 *
 * 3. ⚠ `cancelled_at` ÉTAIT DÉJÀ DANS L'ÉCART, ET PAS DE MON FAIT. Même cause — un
 *    `COMMENT '(DC2Type:datetime_immutable)'` posé par une migration antérieure. On l'aligne ici
 *    parce que c'est la même table et la même ligne d'`ALTER` : laisser une dérive connue sur une
 *    table qu'on vient de corriger apprendrait à vivre avec.
 *
 * ── POURQUOI UNE MIGRATION DE PLUS PLUTÔT QUE CORRIGER LA PRÉCÉDENTE ───────────────────────────
 *
 * `Version20260906100000` est jouée en préproduction. La réécrire ferait diverger l'arbre de ce que
 * la base a réellement exécuté, et le garde-fou « Migrations immuables » compare précisément les 184
 * migrations à `origin/main` pour refuser ça. Une migration se corrige par la suivante.
 */
final class Version20260906110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sport_echeance_sepa : retire les commentaires DC2Type et adopte le nom d\'index de Doctrine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE sport_echeance_sepa
                CHANGE cancelled_at cancelled_at DATETIME DEFAULT NULL,
                CHANGE reduction_at reduction_at DATETIME DEFAULT NULL,
                CHANGE reduction_par_id reduction_par_id BINARY(16) DEFAULT NULL
        SQL);

        $this->addSql('ALTER TABLE sport_echeance_sepa RENAME INDEX idx_sport_echeance_reduction_par TO IDX_BD6EBE25AFCB1B7A');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_echeance_sepa RENAME INDEX IDX_BD6EBE25AFCB1B7A TO idx_sport_echeance_reduction_par');

        $this->addSql(<<<'SQL'
            ALTER TABLE sport_echeance_sepa
                CHANGE cancelled_at cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                CHANGE reduction_at reduction_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                CHANGE reduction_par_id reduction_par_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'
        SQL);
    }
}
