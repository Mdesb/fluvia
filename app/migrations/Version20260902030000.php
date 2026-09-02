<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BT-34 et BT-49 — les adresses électroniques du vendeur et de l'acheteur.
 *
 * Le profil français de la facture électronique (BR-FR-12 et BR-FR-13, XP Z12-012) les exige. Mesure
 * du 02/09 avec le validateur Factur-X : « Le BT-49 est obligatoire. Valeur actuelle : "" ».
 *
 * ⚠ CE N'ÉTAIT PAS UN DÉFAUT DU SÉRIALISEUR : la donnée n'existait **nulle part** dans le modèle.
 * Vérifié champ par champ sur les deux entités avant d'en conclure — `ProfilExploitant` porte le
 * SIREN, le SIRET, la TVA et l'adresse postale ; aucune adresse électronique.
 *
 * ── ⚠ NULLABLE, ET SANS VALEUR PAR DÉFAUT ──────────────────────────────────────────────────────
 *
 * Une adresse électronique ne se devine pas : ni depuis le courriel d'un utilisateur du logiciel, ni
 * depuis un nom de domaine. C'est l'adresse à laquelle la facture est **routée**, pas celle d'un
 * humain qui lit ses messages.
 *
 * Les laisser vides garde le manque VISIBLE dans le rapport de validation, là où un défaut plausible
 * le ferait taire (D66-ter). Une facture partirait alors vers une adresse que personne n'a choisie.
 */
final class Version20260902030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil francais : ajoute les adresses electroniques du vendeur (BT-34) et de l acheteur (BT-49).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD electronic_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE facturation_destinataire ADD electronic_address VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP electronic_address');
        $this->addSql('ALTER TABLE facturation_destinataire DROP electronic_address');
    }
}
