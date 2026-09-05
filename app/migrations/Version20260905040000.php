<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PLUSIEURS PRESTATIONS DANS UN SEUL RENDEZ-VOUS — « coupe avec Sophie, puis couleur avec Marie ».
 *
 * ── ⚠ CE LOT EST PLUS ÉTROIT QUE CE QUE J'AVAIS ANNONCÉ, ET JE LE CORRIGE ───────────────────────
 *
 * Mon analyse du 05/09 disait que « coupe + couleur » était inexprimable. **C'était faux** : rien
 * n'empêche de créer une prestation « Coupe + couleur » de 120 minutes avec son prix — la durée est
 * libre. Ce qui manque réellement est plus étroit, et c'est ce que ce lot ajoute :
 *
 *   - **détailler les deux actes** au lieu d'un bloc opaque de 120 minutes ;
 *   - **les confier à deux praticiens différents**, chacun avec sa compétence, son battement et son
 *     supplément tarifaire.
 *
 * ── LA FORME : DES RÉSERVATIONS LIÉES, PAS UNE RÉSERVATION MULTIPLE ─────────────────────────────
 *
 * Chaque acte reste **une réservation entière** : son créneau, son praticien, sa durée, son prix,
 * sa règle d'annulation. Ce qui est neuf est le LIEN. L'alternative — une réservation portant
 * plusieurs créneaux — aurait obligé à réinterpréter `RG-M5-01` (« le créneau visé est unique »),
 * la jauge, la projection d'accès et la facturation de non-présentation, qui raisonnent tous sur un
 * créneau. Un identifiant de groupe ne touche à aucun d'eux.
 *
 * ⚠ **CONSÉQUENCE ASSUMÉE : ANNULER UN ACTE N'ANNULE PAS LES AUTRES.** Le client qui renonce à sa
 * couleur garde sa coupe. C'est le comportement souhaitable dans un salon, mais il faut le dire :
 * personne ne doit croire qu'annuler « le rendez-vous » libère les deux créneaux.
 *
 * ⚠ **ET LE GROUPE N'EST PAS UN PANIER** : il n'y a pas de prix global, pas de remise de groupe,
 * pas de paiement unique. Chaque acte se vend pour lui-même. Inventer un prix de groupe demanderait
 * de décider quoi faire quand un seul acte est annulé — une décision commerciale que personne n'a
 * prise.
 *
 * Nullable et indexé : additif, sans effet sur l'existant, et le lot n'a de sens que si on peut
 * retrouver les frères d'une réservation sans balayer la table.
 */
final class Version20260905040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chaînage de rendez-vous : plusieurs prestations liées, chacune avec son praticien.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE reservation_reservation
             ADD groupe_rendez_vous BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'"
        );
        $this->addSql(
            'CREATE INDEX idx_reservation_groupe_rendez_vous ON reservation_reservation (groupe_rendez_vous)'
        );
    }

    public function down(Schema $schema): void
    {
        // ⚠ LA DESCENTE DÉLIE LES RENDEZ-VOUS SANS LES DÉTRUIRE. Les réservations restent, chacune
        // valable ; c'est seulement le fait qu'elles formaient un même rendez-vous qui disparaît, et
        // il ne se reconstitue pas.
        $this->addSql('DROP INDEX idx_reservation_groupe_rendez_vous ON reservation_reservation');
        $this->addSql('ALTER TABLE reservation_reservation DROP groupe_rendez_vous');
    }
}
