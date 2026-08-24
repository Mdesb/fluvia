<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ED-3 — `subscription_subscription.demo_configuration` : le paramétrage de démo, exporté (RG-ED-08, D11).
 *
 * **Une colonne sur l'abonnement, et pas une référence vers l'établissement de démo.** D11 pose que
 * la démo est un bac à sable *jetable* : si le paramétrage n'était qu'un pointeur, détruire la démo
 * détruirait ce que le client a configuré, et il faudrait alors faire vivre des milliers
 * d'établissements fantômes jusqu'à leur purge RGPD. Le document est donc autonome, prélevé au moment
 * de la souscription, et il survit à sa source.
 *
 * `JSON` nullable : la grande majorité des souscriptions n'auront pas de démo derrière elles, et une
 * table de côté pour une valeur optionnelle par abonnement coûterait une jointure à chaque lecture
 * pour ne rien porter la plupart du temps.
 *
 * Conforme à D32 : écrite à la main, une seule instruction, aucun `DROP` que ce lot n'ait provoqué.
 * Horodatée en heure locale et **après** `Version20260824223000`, déjà présente sur `main`.
 */
final class Version20260824224000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ED-3 : parametrage de demo exporte sur l abonnement (RG-ED-08).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_subscription ADD demo_configuration JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_subscription DROP demo_configuration');
    }
}
