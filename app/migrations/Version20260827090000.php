<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une prestation déclare son temps de remise en état entre deux clients.
 *
 * **Pourquoi ce n'est pas une durée de plus.** Nettoyer une cabine de massage prend un quart d'heure ;
 * remettre un fauteuil en état, deux minutes. Ce temps décide de ce qu'on peut proposer ensuite **sans
 * faire partie de ce que le client a acheté**.
 *
 * Il n'allonge donc pas le créneau : un créneau allongé ferait voir au client un rendez-vous d'1 h 15
 * pour un soin d'une heure, porterait la mauvaise durée sur son ticket, et le jour où l'exploitant
 * réduit son battement, **tous les rendez-vous passés mentiraient rétroactivement**. C'est une règle
 * de placement, lue par `FreeSlotFinder`.
 *
 * **Défaut à 0, et c'est le seul défaut acceptable.** Une piscine ou un cours collectif n'a pas de
 * battement. Une valeur non nulle par défaut retirerait silencieusement des créneaux à tous les
 * établissements existants — un agenda qui rétrécit sans que personne n'ait rien demandé.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Une prestation déclare son battement entre deux clients (placement libre).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_activite ADD battement_minutes SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_activite DROP battement_minutes');
    }
}
