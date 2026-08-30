<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un passage peut n'avoir franchi aucune porte : `acces_passage.espace_id` devient nullable.
 *
 * ── POURQUOI, ET CE QUE LES DEUX AUTRES ISSUES COÛTAIENT ────────────────────────────────────────
 *
 * Maxime a demandé un outil de scan pour les sites sans matériel : « certains n'ont pas de contrôle
 * d'accès, mais le billet pourra être quand même validé par un contrôle manuel » (D86). Un contrôle
 * manuel laisse un `Passage` — sinon l'historique, qu'il a demandé nommément, aurait un trou
 * exactement là où il n'y a pas de tourniquet, c'est-à-dire là où on a le plus besoin de savoir qui
 * est entré. Mais il n'a pas de lieu : aucune porte n'a été franchie.
 *
 * L'autre issue envisagée — inscrire une zone quelconque — écrirait dans l'historique qu'un porteur
 * a franchi une porte qu'il n'a pas franchie. Un historique qui invente est pire qu'un historique
 * incomplet : il répond à « qui est entré, où, quand » avec une réponse fausse qu'on ne peut pas
 * distinguer d'une vraie.
 *
 * ── CE QUE ÇA COÛTE AUX LECTURES : UNE LIGNE, MESURÉE AVANT D'ÊTRE AFFIRMÉE ─────────────────────
 *
 * « Tout ce qui lit un passage doit accepter l'absence » est une phrase inquiétante ; le chiffre
 * l'est moins. Sur 19 `getEspace()` dans `app/src`, une seule porte sur un PASSAGE — les autres
 * lisent un contrôleur, une jauge ou une entité d'un autre module :
 *
 *     SynchroPassageHandler.php:111    $espace = $passage->getEspace();
 *                                      if ($espace !== null) { … }      ← garde DÉJÀ le cas
 *
 * Le getter PHP rend `?EspaceAcces` depuis l'origine : le code était déjà écrit pour un espace
 * absent, seule la colonne l'interdisait. Cette migration ne fait que lever cet interdit.
 *
 * ⚠ SENS UNIQUE ASSUMÉ. `down()` ne rétablit pas `NOT NULL` : des passages de contrôle manuel sans
 * espace peuvent exister au moment du retour arrière, et la contrainte échouerait sur eux — ou pire,
 * inviterait à leur inventer une zone. Un retour arrière qui exige de falsifier des données n'est
 * pas un retour arrière.
 */
final class Version20260830180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'acces_passage.espace_id devient nullable : un contrôle manuel ne franchit aucune porte (D86).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_passage CHANGE espace_id espace_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->write(
            'Retour arrière volontairement inerte : rétablir NOT NULL échouerait sur les passages de '
            . 'contrôle manuel, qui n\'ont légitimement pas d\'espace. Les supprimer ou leur inventer '
            . 'une zone serait pire que de laisser la colonne nullable.'
        );
    }
}
