<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une vitrine porte un nom d'URL : `/b/piscine-municipale`.
 *
 * **Pourquoi.** Maxime, le 27/08 : *« il faudra penser à ce que l'URL soit au nom du client et il y a
 * `vitrine?vitrine=` c'est pas propre »*. Une adresse en UUID ne se donne pas à un client, ne s'imprime
 * pas sur une affiche et ne se dicte pas au téléphone.
 *
 * **Nullable, et c'est une décision de compatibilité.** Les vitrines existantes n'ont pas de slug, et
 * leur URL par identifiant continue de fonctionner : `VitrineResolver` accepte les deux formes. Un lien
 * déjà envoyé dans un courriel de confirmation ne se casse pas parce qu'on a trouvé mieux.
 *
 * **La colonne est remplie ailleurs, pas ici.** D66-ter : une migration ne fabrique jamais de donnée
 * métier. Les vitrines neuves reçoivent leur slug de `EstablishmentStampProcessor` ; les deux vitrines
 * de démonstration sont nommées par une opération d'exploitation.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Une vitrine porte un nom d’URL lisible (slug), unique et facultatif.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_vitrine ADD slug VARCHAR(80) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_996610D2989D9B62 ON bou_vitrine (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_996610D2989D9B62 ON bou_vitrine');
        $this->addSql('ALTER TABLE bou_vitrine DROP slug');
    }
}
