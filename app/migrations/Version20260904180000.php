<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE LIGNE PEUT N'AVOIR AUCUN TYPE DE TARIF, ET LE DIRE — §8.11.
 *
 * ── L'ARBITRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * `vente_ligne.type_tarif` était obligatoire, et les réservations génériques n'ont aucun type de
 * tarif à fournir : `Activite` n'en porte pas, et son propre docblock dit que la chaîne de
 * tarification (`ResolveurPrix` + `TypeTarif` + `Saison`) est « hors périmètre de ce lot socle ».
 * `VenteReservationHandler` en tirait donc un AU HASARD — `Uuid::v4()` — qui ne désigne rien.
 *
 * Maxime a tranché : rendre le champ nullable. `null` dit la vérité — « personne n'a pu en fournir
 * un » — là où un identifiant inventé PRÉTEND désigner quelque chose.
 *
 * ── ⚠ CE QUE ÇA CHANGE À L'ÉCRAN : RIEN ─────────────────────────────────────────────────────────
 *
 * Mesuré le 04/09 : la comptabilité ne lit PAS ce champ (absent de `ProjectionVenteDoctrineAdapter`).
 * Son seul lecteur sur une ligne est `LineLabelStamper`, qui résout le type pour figer son LIBELLÉ.
 * Une référence inventée ne résolvait rien, donc le libellé restait nul et le ticket sortait sans
 * « Plein tarif » ni « Membre ». Avec `null`, il sort exactement pareil.
 *
 * C'est ce qui rend ce changement acceptable sur une entité de socle : aucun écran ne bouge, et le
 * modèle cesse de prétendre.
 *
 * ⚠ D66-ter : les 23 lignes existantes gardent leur valeur, y compris les identifiants inventés
 * d'avant. Les mettre à `null` serait réécrire l'histoire d'une vente ; on ne touche pas au passé.
 *
 * ⚠ Nullable, donc sûre pendant le déploiement — les migrations passent avant le redémarrage de FPM.
 * Et l'ordre importe : élargir une contrainte (NOT NULL → NULL) ne casse aucun code ancien, alors
 * que l'inverse le ferait.
 */
final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vente_ligne.type_tarif devient nullable : une ligne peut n\'en avoir aucun (§8.11).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne MODIFY type_tarif BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // ⚠ CE `down()` PEUT ÉCHOUER, ET C'EST NORMAL. Remettre `NOT NULL` exige qu'aucune ligne ne
        //   porte `null` — or c'est précisément ce que ce `up()` a rendu possible. Un retour arrière
        //   demande donc de décider quoi mettre dans ces lignes-là, et cette décision n'appartient
        //   pas à une migration.
        $this->addSql('ALTER TABLE vente_ligne MODIFY type_tarif BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }
}
