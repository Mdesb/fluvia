<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M2 Vente & Caisse (L2) — migration de données : moyens de paiement par défaut (RG-M2-02).
 *
 * En L2, le référentiel des moyens de paiement et l'acte de régie relèvent de M6 et sont fournis par
 * le port applicatif `App\Vente\Port\ReferentielReglementInterface` (stub `ReferentielReglementStub`,
 * jeu standard : Espèces [rendu autorisé], CB [référence TPE], Chèque, Virement, Chèques Vacances/
 * Culture/Loisirs, PMV, Avoir, Différé). Il n'existe donc PAS de table `moyen_paiement` à peupler à
 * ce lot : cette migration est un jalon documentaire (no-op) tant que le référentiel M6 n'est pas
 * câblé (intégration L4). Les moyens autorisés par point de vente sont, eux, portés par la colonne
 * `caisse_point_de_vente.moyens_autorises` et initialisés par les fixtures.
 */
final class Version20260814175520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2 (L2) : moyens de paiement par défaut (référentiel M6 fourni par le port applicatif, no-op DB).';
    }

    public function up(Schema $schema): void
    {
        // No-op : référentiel des moyens porté par le code (ReferentielReglementStub) en L2.
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
