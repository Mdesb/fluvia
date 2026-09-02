<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BT-22 — les trois mentions obligatoires du profil francais (BR-FR-05, XP Z12-012).
 *
 * Frais de recouvrement (PMT), penalites de retard (PMD), escompte ou son absence (AAB). Le
 * validateur Factur-X les reclame, note par note.
 *
 * ⚠ TROIS COLONNES DE TEXTE, NULLABLES, SANS DEFAUT — ET C'EST LE POINT.
 *
 * Le taux de penalite et l'indemnite forfaitaire existent deja en base : composer la phrase a
 * partir d'eux serait simple. Ce serait mettre des mots dans la bouche de l'exploitant sur un
 * document OPPOSABLE. Une clause de penalites engage ; elle se relit, elle se negocie, et elle
 * differe d'une regie municipale a une salle privee.
 *
 * Vide = la mention manque, et le rapport de validation le dit. C'est preferable a une phrase
 * plausible que personne n'a approuvee (D66-ter).
 */
final class Version20260902040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil francais : trois mentions obligatoires (recouvrement, penalites, escompte).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_parametre ADD mention_recouvrement LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE facturation_parametre ADD mention_penalites_retard LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE facturation_parametre ADD mention_escompte LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_parametre DROP mention_recouvrement');
        $this->addSql('ALTER TABLE facturation_parametre DROP mention_penalites_retard');
        $this->addSql('ALTER TABLE facturation_parametre DROP mention_escompte');
    }
}
