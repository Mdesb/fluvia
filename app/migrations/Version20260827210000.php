<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les sites autorisés à encadrer une boutique dans une iframe.
 *
 * **Pas de `DEFAULT` en SQL (D32).** La valeur de départ est le tableau vide de la propriété PHP.
 * Écrire le défaut aux deux endroits, c'est promettre qu'ils diront toujours la même chose — et le
 * jour où l'un change, l'autre continue de répondre l'ancienne valeur sans que rien ne le signale.
 * D'où le `UPDATE` explicite ci-dessous, qui remplit les lignes existantes une fois, et s'arrête là.
 *
 * **Les boutiques existantes deviennent non encadrables.** C'est un durcissement voulu : jusqu'ici,
 * faute d'en-tête `frame-ancestors`, n'importe quel site pouvait les afficher sous son propre nom.
 * Une intégration qui cesse de fonctionner se voit et se corrige en déclarant le domaine ; une
 * boutique encadrable par tout le monde ne se voit jamais.
 */
final class Version20260827210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique : domaines autorisés à encadrer chaque vitrine (frame-ancestors).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_vitrine ADD domaines_integration JSON NOT NULL');
        // MariaDB refuse d'ajouter une colonne NOT NULL sans défaut sur une table peuplée autrement
        // qu'en la remplissant : le tableau vide est la valeur fermée, cohérente avec la propriété.
        $this->addSql("UPDATE bou_vitrine SET domaines_integration = '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_vitrine DROP domaines_integration');
    }
}
