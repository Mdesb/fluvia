<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un établissement peut nommer les choses dans les mots de son métier.
 *
 * **Pourquoi ce n'est pas une traduction globale.** « Ressource » veut dire *praticien* dans un salon
 * de coiffure, *ligne d'eau* dans une piscine, *court* au padel. Traduire une fois pour tout le monde
 * rendrait le logiciel faux partout sauf à un endroit.
 *
 * Maxime demandait « les verticales salon de massage, salon de coiffure ». En regardant, le métier
 * était déjà écrit — `reservation_activite` porte une durée, `reservation_ressource` une capacité, il
 * existe des règles d'annulation et une facturation des non-présentations. **Ce qui manquait n'était
 * pas le modèle : c'étaient les mots.**
 *
 * **Table de remplacements posée PAR-DESSUS le vocabulaire par défaut, jamais à la place.** Un code
 * absent d'ici garde sa traduction habituelle : un établissement qui ne renseigne rien continue de
 * voir exactement ce qu'il voyait. C'est la seule façon d'ajouter cette souplesse sans risquer de
 * vider un terme quelque part.
 *
 * `NULL` et non `'{}'` : l'absence de vocabulaire propre se distingue ainsi d'un vocabulaire vidé,
 * et le premier cas — de loin le plus fréquent — ne coûte rien à lire.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un établissement nomme les choses dans les mots de son métier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement ADD vocabulaire JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement DROP vocabulaire');
    }
}
