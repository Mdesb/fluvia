<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE SIRET REJOINT LE PROFIL, AVANT QUE SA SEULE COPIE NE DISPARAISSE.
 *
 * `Version20260901000000` a deplace la raison sociale, la TVA intracommunautaire et l'adresse vers
 * `ProfilExploitant`. **Le SIRET a ete oublie** — le rendu s'est mis a publier le SIREN sous la cle
 * `siret` : neuf chiffres au lieu de quatorze.
 *
 * ⚠ Un SIRET, ce sont les 9 chiffres du SIREN **plus le NIC a 5 chiffres qui designe
 * l'ETABLISSEMENT**. C'est cette partie-la qui dit quel site facture, et c'est la mention exigee
 * sur une facture francaise. Les cinq chiffres perdus portaient l'information.
 *
 * ⚠ **ET LA FENETRE SE REFERMAIT.** La valeur a 14 chiffres n'existe plus que dans
 * `mentions_legales_emetteur`, colonne marquee obsolete et destinee a etre supprimee apres un
 * deploiement. Une fois supprimee, le SIRET n'existait plus nulle part et il aurait fallu le
 * redemander a l'exploitant.
 *
 * Releve par `allaccess-37`, en comparant deux appels du meme rendu a deux heures d'intervalle.
 * Sa lecon de methode vaut plus que le correctif : **un remplacement de source se prouve par
 * l'EGALITE DE LA SORTIE, pas par la presence des champs.** Un test de presence ne peut pas voir
 * qu'un champ present, non vide et numerique porte la mauvaise valeur.
 *
 * `NULL` par defaut, DEFAUT declare au mapping : une colonne neuve doit rester compatible avec le
 * code qui tourne encore sans elle.
 */
final class Version20260901020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le SIRET de l’établissement qui facture rejoint le profil, avant la suppression de son unique copie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD siret VARCHAR(14) DEFAULT NULL');

        // On ne recopie que la ou la destination est vide, et seulement une valeur qui a la forme
        // d'un SIRET : recopier n'importe quoi remplirait le champ d'une valeur fausse, ce qui est
        // pire que de le laisser vide -- `InvoiceReadiness` sait dire qu'il manque, il ne sait pas
        // dire qu'il ment.
        $this->addSql(<<<'SQL'
            UPDATE compta_profil_exploitant p
            JOIN facturation_parametre f ON f.profil_exploitant_id = p.id
            SET p.siret = JSON_UNQUOTE(JSON_EXTRACT(f.mentions_legales_emetteur, '$.siret'))
            WHERE p.siret IS NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(f.mentions_legales_emetteur, '$.siret')) REGEXP '^[0-9]{14}$'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP siret');
    }
}
