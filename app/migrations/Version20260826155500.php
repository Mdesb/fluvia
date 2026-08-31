<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le ticket retrouve ses mots : `vente_ligne.libelle_produit` et `libelle_type_tarif`.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (15:55) et non en UTC. DDL relevé par
 * `SHOW CREATE TABLE` sur la table que Doctrine crée depuis le mapping.
 *
 * **Le défaut.** `LigneVente` ne portait que des `Uuid` nus vers le produit et le tarif — tout
 * l'argent, aucun mot. `TicketProcessor` ne renvoyait donc aucune ligne, et **le ticket n'existait
 * que dans l'onglet du caissier** : la page fermée, le document n'était plus reconstituable. Relevé
 * par `claude-H`, qui a refusé de proposer un bouton de réimpression plutôt que de promettre un
 * duplicata vide.
 *
 * **Ces colonnes sont des copies datées, pas des références.** Un ticket dit ce que le produit
 * s'appelait **le jour de la vente**. C'est la même règle que `prix_unitaire`, stocké et jamais
 * recalculé, et que `options_selectionnees`, figé par RG-OPT-09. Les remplacer un jour par une
 * jointure ferait mentir rétroactivement tous les tickets déjà émis.
 *
 * **La reprise de données est un pis-aller, et il faut le dire.** Les lignes existantes reçoivent le
 * nom que le produit porte **aujourd'hui**, pas celui qu'il portait le jour de leur vente — cette
 * information-là n'a jamais été écrite nulle part et ne se reconstitue pas. C'est la meilleure
 * approximation disponible, et elle est exacte pour tout produit jamais renommé, c'est-à-dire
 * l'immense majorité. À partir de cette migration, la question ne se pose plus : le nom est gravé à
 * la création de la ligne.
 *
 * **Une dérive de convention, signalée et non suivie.** L'horloge du serveur indiquait 15:55 quand
 * cette migration a été écrite, et le dépôt portait déjà `Version20260826170000` (commitée à 15:11)
 * et `Version20260826191000` (commitée à 14:54) — deux migrations **datées dans l'avenir**. La mienne
 * porte donc l'heure réelle, ce que D32 demande, et se retrouve numérotée **avant** deux migrations
 * antérieures dans les faits.
 *
 * C'est sans conséquence ici : cette migration est indépendante des deux autres, elle ajoute deux
 * colonnes que personne d'autre ne touche. J'ai préféré signaler l'écart plutôt que de m'y aligner —
 * dater à mon tour dans l'avenir aurait rendu la dérive invisible en m'y ajoutant, et l'ordre des
 * versions ne veut plus rien dire dès que chacun choisit son heure.
 *
 * Une ligne dont la référence ne désigne aucun produit du catalogue reste **nulle**, délibérément :
 * `VenteReservationHandler` pose un identifiant arbitraire quand la réservation n'a pas de produit.
 * Inventer un libellé donnerait à un ticket l'apparence d'un document complet ; un champ nul dit
 * qu'on ne sait pas, ce qui est vrai et se corrige.
 */
final class Version20260826155500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le ticket retrouve ses mots : libelles figes sur vente_ligne.';
    }

    public function up(Schema $schema): void
    {
        // `JSON` et non `LONGTEXT` : sur MariaDB, `JSON` est un alias de `LONGTEXT` qui ajoute
        // lui-même la contrainte `json_valid`. C'est ce que Doctrine émet depuis le mapping, et c'est
        // la forme qu'ont déjà les colonnes JSON du dépôt (`caisse_cloture_z.comptages`). L'écrire à
        // la main aurait produit une colonne qui ressemble à la bonne sans en être une.
        $this->addSql(<<<'SQL'
            ALTER TABLE vente_ligne
                ADD libelle_produit JSON DEFAULT NULL,
                ADD libelle_type_tarif VARCHAR(120) DEFAULT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE vente_ligne l
            INNER JOIN off_produit p ON p.id = l.produit
            SET l.libelle_produit = p.libelle
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE vente_ligne l
            INNER JOIN off_type_tarif t ON t.id = l.type_tarif
            SET l.libelle_type_tarif = t.nom
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne DROP libelle_produit, DROP libelle_type_tarif');
    }
}
