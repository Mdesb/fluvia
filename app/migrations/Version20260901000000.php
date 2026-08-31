<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE SEULE SOURCE POUR L'IDENTITÉ LÉGALE DU VENDEUR.
 *
 * ── LE DÉFAUT, QUE J'AI INTRODUIT MOI-MÊME ───────────────────────────────────────────────────────
 *
 * Les mêmes faits — dénomination, adresse, numéro de TVA — vivaient à **deux endroits** depuis le
 * 31/08 au soir :
 *
 *     ParametreFacturationEtablissement::mentionsLegalesEmetteur   tableau JSON libre, RENSEIGNÉ
 *     ProfilExploitant::raisonSociale / adresse / tvaIntra…        champs structurés, VIDES
 *
 * Le rendu de facture lisait le premier ; le contrôle EN 16931 lisait le second. **Deux sources
 * donnaient déjà deux réponses** — l'une disait « Régie piscine A, 1 rue de la Piscine », l'autre
 * disait « il manque la raison sociale ».
 *
 * ⚠ Sur un document **opposable**, deux identités du vendeur selon le chemin, et aucune règle
 * disant laquelle gagne. Relevé par `b8` sur une lecture d'`allaccess-37`, qui l'a formulé
 * exactement : *si le lot EN 16931 veut devenir la source, il faut que ce soit un remplacement
 * explicite, pas un second chemin.*
 *
 * ── POURQUOI L'ENTITÉ GAGNE ──────────────────────────────────────────────────────────────────────
 *
 * Un tableau JSON libre ne se valide pas champ par champ. EN 16931 exige des termes distincts
 * (BT-27, BT-31, BT-35/37/38/40) qu'une plateforme contrôle un par un. Et l'identité légale
 * appartient à l'entité qui facture — là où vit déjà le SIREN — pas aux paramètres d'un module.
 *
 * ── CE QUE CETTE MIGRATION DÉPLACE, ET CE QU'ELLE NE FABRIQUE PAS ────────────────────────────────
 *
 * ⚠ D66-ter interdit à une migration de **fabriquer** de la donnée métier. Celle-ci n'en fabrique
 * aucune : elle **déplace** un fait qui existe déjà, d'une colonne vers une autre, et seulement
 * là où la destination est vide. L'alternative — laisser le fait dans l'ancienne colonne et lire
 * l'autre — est précisément le défaut qu'on referme.
 *
 * Les formes coïncident, vérifié sur la donnée réelle : le sous-objet `adresse` du tableau porte
 * déjà `{rue, cp, ville, pays}`, exactement ce qu'attend `ProfilExploitant::adresse`.
 *
 * L'ancienne colonne n'est **pas supprimée ici**. Retirer une colonne se fait en deux temps : le
 * code cesse de l'écrire, on déploie, puis on la supprime. Elle est marquée obsolète dans l'entité.
 */
final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L’identité légale du vendeur rejoint le profil d’exploitant : une seule source.';
    }

    public function up(Schema $schema): void
    {
        // ⚠ SEULEMENT LÀ OÙ LA DESTINATION EST VIDE. Si quelqu'un a déjà renseigné le profil, c'est
        // lui qui fait foi : on n'écrase pas une saisie récente par une valeur ancienne.
        $this->addSql(<<<'SQL'
            UPDATE compta_profil_exploitant p
            JOIN facturation_parametre f ON f.profil_exploitant_id = p.id
            SET p.raison_sociale = JSON_UNQUOTE(JSON_EXTRACT(f.mentions_legales_emetteur, '$.denomination'))
            WHERE p.raison_sociale IS NULL
              AND JSON_EXTRACT(f.mentions_legales_emetteur, '$.denomination') IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE compta_profil_exploitant p
            JOIN facturation_parametre f ON f.profil_exploitant_id = p.id
            SET p.tva_intracommunautaire = JSON_UNQUOTE(JSON_EXTRACT(f.mentions_legales_emetteur, '$.tvaIntra'))
            WHERE p.tva_intracommunautaire IS NULL
              AND JSON_EXTRACT(f.mentions_legales_emetteur, '$.tvaIntra') IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE compta_profil_exploitant p
            JOIN facturation_parametre f ON f.profil_exploitant_id = p.id
            SET p.adresse = JSON_EXTRACT(f.mentions_legales_emetteur, '$.adresse')
            WHERE p.adresse IN ('[]', '{}')
              AND JSON_EXTRACT(f.mentions_legales_emetteur, '$.adresse') IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // ⚠ ON NE REMET RIEN DANS L'ANCIENNE COLONNE : elle n'a jamais été vidée, elle porte encore
        // ses valeurs. Revenir en arrière consiste à cesser de lire le profil, pas à défaire une
        // copie — et effacer le profil détruirait une saisie que quelqu'un aurait pu y faire depuis.
        $this->addSql('SELECT 1');
    }
}
