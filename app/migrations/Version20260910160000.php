<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supprime la table `membership` posee au lot 0. Elle n'aura jamais servi, et c'est voulu.
 *
 * ── POURQUOI UNE TABLE NEUVE EST ABANDONNEE TROIS HEURES APRES AVOIR ETE CREEE ─────────────────
 *
 * Le lot 0 suivait la spec : creer une entite neuve, migrer les donnees, puis basculer. L'Issue #1
 * portait une autre analyse, mesuree, que personne n'avait confrontee a la spec : les dix entites de
 * Sport declarent leur nom de table explicitement, donc les classes peuvent CHANGER DE NAMESPACE
 * sans qu'aucune donnee bouge.
 *
 * ⚠ CE QUI A TRANCHE N'EST PAS UNE PREFERENCE, C'EST UN COMPTAGE : **sept cles etrangeres** pointent
 * sur `sport_abonnement_fitness` (echeances, mouvements comptables, pauses, resiliations, les deux
 * du reengagement, statuts d'acces). Copier les donnees vers une table neuve obligeait a repointer
 * ces sept contraintes -- dont celle d'un echeancier de prelevement SEPA en fonctionnement.
 * Deplacer les classes n'en touche aucune.
 *
 * Arbitrage de Maxime le 10/09 : « deplace sans renommer ».
 *
 * ── LE `DROP` EST SANS RISQUE, ET ON PEUT LE DIRE PRECISEMENT ──────────────────────────────────
 *
 * @drop-voulu : la table `membership` a ete creee par `Version20260910120000` le meme jour, n'a
 * jamais recu de donnees, et n'a jamais ete deployee -- verifie en preproduction, aucune table
 * `mem%` n'y existe. Elle est remplacee par `sport_abonnement_fitness`, que l'entite
 * `App\Membership\Entity\Membership` designe desormais. Aucune cle etrangere ne la reference.
 *
 * ⚠ `IF EXISTS` PARCE QUE LES DEUX ETATS SONT LEGITIMES. Une base deja migree la porte ; une base
 * qui n'a pas vu le lot 0 ne la porte pas. Sans `IF EXISTS`, le second cas ferait echouer le
 * deploiement sur une table absente -- exactement le genre de migration qui bloque tout le monde,
 * comme le 07/09.
 *
 * ── `down()` NE LA RECREE PAS ──────────────────────────────────────────────────────────────────
 *
 * Revenir en arriere sur ce lot veut dire revenir a `AbonnementFitness` dans Sport, donc a du code
 * qui n'utilise PAS `membership`. Recreer une table vide que personne ne lirait donnerait
 * l'illusion d'une reversibilite complete. On defait ce qu'on a fait, rien de plus.
 */
final class Version20260910160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire la table membership du lot 0 : l abonnement reste sur sport_abonnement_fitness.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS membership');
    }

    public function down(Schema $schema): void
    {
        // Voir le docblock : rien a defaire. La migration du lot 0 reste dans l'historique et
        // recreerait la table si on remontait jusqu'a elle.
        $this->addSql('SELECT 1');
    }
}
