<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN GUICHET ENCAISSE POUR UNE RÉGIE DE RECETTES.
 *
 * Une colonne sur `caisse_point_de_vente`, et rien d'autre. Écrite à la main : `migrations:diff`
 * ratisserait la dérive des autres sessions ouvertes sur ce dépôt.
 *
 * ── CE QUE CETTE COLONNE DÉBLOQUE, ET QUI ÉTAIT MORT ───────────────────────────────────────────
 *
 * `RegieRecettes::soldeEncaisseCentimes` ne montait JAMAIS : `RegieHandler::enregistrerEncaissement()`
 * n'avait aucun appelant en production, et le champ n'est pas dans le groupe d'écriture de l'API.
 * Le plafond ne pouvait donc pas être dépassé, `ClotureGuard` ne bloquait jamais une clôture pour ce
 * motif, et l'écran de versement n'avait jamais rien à verser. Toute la moitié « régie » du module
 * comptable était inerte, tests verts compris — ils appellent le handler eux-mêmes.
 *
 * La clôture Z alimente désormais l'encaisse. Cette colonne lui dit LAQUELLE.
 *
 * ── ⚠ POURQUOI SUR LE GUICHET, ET PAS RÉSOLUE DEPUIS LE PROFIL COMPTABLE ───────────────────────
 *
 * Une régie pend au `ProfilExploitant`, donc à l'établissement. Résoudre par ce chemin marche tant
 * qu'il n'y a qu'une régie et devient une devinette dès qu'il y en a deux — une collectivité qui
 * tient piscine et patinoire sur le même profil. L'argent de l'une entrerait dans l'encaisse de
 * l'autre, et le symptôme serait un plafond qui déborde là où personne n'a encaissé. Arbitrage de
 * Maxime le 08/09 : le rattachement va sur le point de vente.
 *
 * ── LA COLONNE EST NULLABLE, ET C'EST LE CAS NORMAL ────────────────────────────────────────────
 *
 * Un exploitant privé ou un délégataire n'a pas de régie ; la très grande majorité des guichets
 * n'en aura jamais. `NOT NULL` aurait exigé une valeur de reprise pour chaque point de vente
 * existant — il n'en existe aucune qui soit vraie.
 *
 * `ON DELETE SET NULL` plutôt que `RESTRICT` : supprimer une régie ne doit pas rendre un guichet
 * inouvrable ni bloquer sa suppression. Le guichet cesse simplement d'alimenter une encaisse.
 */
final class Version20260908000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattache un point de vente à la régie de recettes pour laquelle il encaisse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE caisse_point_de_vente
                ADD regie_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\''
        );
        $this->addSql(
            'ALTER TABLE caisse_point_de_vente
                ADD CONSTRAINT fk_caisse_pdv_regie
                FOREIGN KEY (regie_id) REFERENCES compta_regie_recettes (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_caisse_pdv_regie ON caisse_point_de_vente (regie_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caisse_point_de_vente DROP FOREIGN KEY fk_caisse_pdv_regie');
        $this->addSql('DROP INDEX idx_caisse_pdv_regie ON caisse_point_de_vente');
        $this->addSql('ALTER TABLE caisse_point_de_vente DROP regie_id');
    }
}
