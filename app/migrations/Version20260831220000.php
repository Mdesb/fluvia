<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les groupes d'options produit appartiennent enfin a quelqu'un.
 *
 * ⚠ CE QUI FUYAIT, MESURE LE 31/08 : un groupe d'options cree sur un etablissement etait visible
 * depuis n'importe quel autre. `Catalogue.jsx` les liste et `ProduitOptionsModal.jsx` les attache
 * aux produits — la consequence n'est donc pas theorique : les options d'un exploitant
 * apparaissaient dans le catalogue d'un concurrent, et `ValeurOption` expose `impactType` et
 * `impactValeur`, c'est-a-dire leur effet sur le prix.
 *
 * ── POURQUOI UNE COLONNE, ET NON UNE ENTREE D'EXTENSION ─────────────────────────────────────────
 *
 * Les autres cloisonnements poses ce jour-la ajoutaient une ligne a une carte existante.
 * `GroupeOption` ne portait AUCUNE relation sortante : il etait atteint DEPUIS le pivot
 * `OptionProduit`, jamais l'inverse. Une extension Doctrine cloisonne en JOIGNANT ce que l'entite
 * designe ; elle ne peut rien pour ce qui ne designe rien.
 *
 * C'est une troisieme nature de defaut, distincte des deux autres rencontrees le meme jour :
 *
 *     oubli de liste          une ligne manque a une enumeration        OperationScellee
 *     chemin trop long        la carte ne decrit qu'un saut             LettrageEcriture
 *     rattachement INVERSE    l'entite est atteinte, elle n'atteint     GroupeOption
 *                             rien elle-meme
 *
 * L'entite revendiquait pourtant le patron de `TypeTarif`/`Saison` dans son propre en-tete — et ces
 * deux-la sont cloisonnes. Elle suivait le modele en mots, pas en structure.
 *
 * ── ⚠ LE RATTACHEMENT DES LIGNES EXISTANTES REFUSE L'AMBIGUITE ──────────────────────────────────
 *
 * Chaque groupe recoit l'etablissement de ses produits — mais SEULEMENT si ces produits n'en
 * designent qu'un. `off_produit_etablissement` est un ManyToMany : un produit peut etre commercialise
 * sur plusieurs sites, donc un groupe pourrait en toucher plusieurs.
 *
 * Dans ce cas la ligne reste NULLE, et une ligne nulle n'est visible de personne. C'est
 * volontairement le sens le plus strict : choisir un proprietaire au hasard entre deux clients
 * donnerait les options de l'un a l'autre, et personne ne le verrait. Une disparition se remarque ;
 * une attribution silencieuse au mauvais client, non.
 *
 * Mesure faite en preprod AVANT d'ecrire : 2 groupes, 5 valeurs, 4 rattachements produit, et chaque
 * groupe utilise par les produits d'UN SEUL etablissement. Aucune ligne ambigue aujourd'hui. C'est
 * ce comptage qui rend cette migration sure, et il n'etait pas acquis avant d'etre fait.
 *
 * ⚠ `down()` NE RETIRE PAS LA COLONNE. La reprendre ferait disparaitre le rattachement de lignes
 * creees depuis, et il ne se reconstruirait pas : `TenantReferenceProcessor` le pose a la creation,
 * pas apres coup. Une migration qui perd de la donnee en revenant en arriere est pire que
 * l'absence de retour.
 */
final class Version20260831220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cloisonne les groupes d\'options produit : colonne etablissement_id et rattachement des lignes existantes.';
    }

    public function up(Schema $schema): void
    {
        // DDL releve du mapping (`doctrine:schema:update --dump-sql`), pas ecrit a la main : les
        // noms de contrainte et d'index sont ceux que Doctrine attend, sinon le controle de
        // coherence du schema signale un ecart a chaque execution.
        $this->addSql('ALTER TABLE opt_groupe ADD etablissement_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE opt_groupe ADD CONSTRAINT FK_7B39DFE8FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('CREATE INDEX IDX_7B39DFE8FF631228 ON opt_groupe (etablissement_id)');

        // Rattachement des lignes existantes, et UNIQUEMENT celles dont le proprietaire est
        // certain : `HAVING COUNT(DISTINCT ...) = 1`. Voir l'en-tete — une ligne ambigue reste
        // nulle, donc invisible, plutot qu'attribuee au hasard.
        $this->addSql(<<<'SQL'
            UPDATE opt_groupe g
            JOIN (
                SELECT op.groupe_option_id AS gid,
                       MIN(pe.etablissement_id) AS etab
                FROM opt_option_produit op
                JOIN off_produit_etablissement pe ON pe.produit_id = op.produit_id
                GROUP BY op.groupe_option_id
                HAVING COUNT(DISTINCT pe.etablissement_id) = 1
            ) x ON x.gid = g.id
            SET g.etablissement_id = x.etab
            WHERE g.etablissement_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Deliberement inerte : voir l'en-tete. Reprendre la colonne perdrait le rattachement des
        // lignes creees depuis, sans moyen de le reconstruire.
    }
}
