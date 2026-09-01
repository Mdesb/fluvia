<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'ABONNEMENT PORTE SON MONTANT COURANT — et l'échéance continue de faire foi.
 *
 * ── ⚠ CE CHAMP CRÉE UNE SECONDE SOURCE POUR LE PRIX, ET C'EST ASSUMÉ ──────────────────────────
 *
 * Le prix vivait déjà quelque part : `GenerateurEcheancierHandler` l'estampille sur chaque
 * `EcheanceSepa` à la souscription, et ne le relit jamais. Poser un montant sur l'abonnement
 * ajoute donc un second endroit où lire le même fait — exactement la forme de défaut qui coûte
 * cher quand personne n'a dit laquelle gagne.
 *
 * **Règle de préséance, décidée et non déduite : l'ÉCHÉANCE fait foi.** Elle est datée, émise et
 * opposable — c'est elle qui sera prélevée. L'abonnement ne porte que le montant **courant** : ce
 * qu'on facturera la prochaine fois, ce qu'un écran affiche quand on demande « combien coûte cet
 * abonnement », ce qu'on compare pour décider d'un changement de tarif.
 *
 * ⚠ CONSÉQUENCE À TENIR : écrire ce champ ne réécrit AUCUNE échéance. Le jour où un écran modifiera
 * le montant, il devra régénérer explicitement ce qui n'est pas encore remis en banque. Ce geste se
 * décide, il ne se déduit pas d'une écriture de champ.
 *
 * ── ⚠ CE QUE LA MESURE A CORRIGÉ AVANT D'ÉCRIRE CETTE MIGRATION ───────────────────────────────
 *
 * Le lot annonçait « aucun montant sur l'abonnement, le prix est relu du tarif produit à chaque
 * échéance, donc un montant libre est inexprimable ». **Faux sur les deux moitiés** :
 * `SouscrireAbonnementProcessor` EXIGE un `montantCentimes` strictement positif du corps de la
 * requête, et aucune lecture de tarif n'existe nulle part dans ce chemin. Le montant libre existait
 * déjà ; ce qui manquait était la mémoire — et le prorata d'entrée, que le générateur ne savait pas
 * poser puisqu'il appliquait le même montant à toutes les échéances.
 *
 * ── LE RATTRAPAGE, SANS QUOI LA COLONNE MENTIRAIT SUR L'EXISTANT ──────────────────────────────
 *
 * Un `DEFAULT 0` laisserait tous les abonnements déjà souscrits à « 0 € », ce qu'aucun écran ne
 * pourrait distinguer d'un abonnement réellement gratuit. On reprend donc le montant depuis les
 * échéances, seul endroit où il vit aujourd'hui.
 *
 * ⚠ `ORDER BY date_programmee DESC` ET NON `ASC`, délibérément : la PREMIÈRE échéance peut être un
 * prorata d'entrée — un demi-mois, voire un mois offert à 0. La reprendre comme montant courant
 * fixerait l'abonnement au prix d'un cas particulier. La dernière porte le tarif de croisière.
 *
 * ── DÉFAUT DÉCLARÉ AU MAPPING, PAS SEULEMENT ICI ──────────────────────────────────────────────
 *
 * `#[ORM\Column(options: ['default' => 0])]` est posé sur l'entité. Le déploiement migre AVANT de
 * redémarrer PHP : il existe une fenêtre où la colonne existe et où le code ancien insère sans la
 * renseigner. Sans défaut au niveau base, cette fenêtre rend des erreurs SQL sur chaque
 * souscription.
 */
final class Version20260901050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AbonnementFitness porte son montant courant ; l’échéance reste la source qui fait foi.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_abonnement_fitness ADD montant_centimes INT DEFAULT 0 NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE sport_abonnement_fitness a
            SET a.montant_centimes = COALESCE((
                SELECT e.montant_centimes
                FROM sport_echeance_sepa e
                WHERE e.abonnement_id = a.id
                ORDER BY e.date_programmee DESC
                LIMIT 1
            ), 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : la colonne montant_centimes est celle que cette migration vient d'ajouter ;
        //   la retirer est le seul retour possible. Aucune donnee n'est perdue qui n'existe
        //   ailleurs — les echeances portent le montant qui fait foi, et c'est d'elles que le
        //   rattrapage de `up()` l'avait repris.
        $this->addSql('ALTER TABLE sport_abonnement_fitness DROP montant_centimes');
    }
}
