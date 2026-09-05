<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE RAPPEL DE RENDEZ-VOUS — deux colonnes, et c'est tout ce qu'il fallait.
 *
 * ── L'ARBITRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * Analyse du 05/09 : le module Réservation est déjà un moteur de rendez-vous complet — durée,
 * battement, compétence exigée, disponibilités hebdomadaires, absences, règles d'annulation,
 * facturation de non-présentation. Le SEUL manque bloquant pour un métier de rendez-vous (salon,
 * coiffeur) était le rappel au client. Le port de notification ne portait que deux événements
 * (promotion de liste d'attente, arbitrage de récurrence) et sa seule implémentation journalisait.
 *
 * Sans rappel, on vend une facturation d'absence là où le client attendait qu'on lui ÉVITE
 * l'absence.
 *
 * ── ⚠ LE DÉLAI APPARTIENT À LA PRESTATION, PAS À L'ÉTABLISSEMENT ────────────────────────────────
 *
 * Un massage de 90 minutes se rappelle la veille ; une retouche de quinze minutes, deux heures
 * avant. Un réglage unique par établissement aurait forcé le même délai aux deux. Le champ vit donc
 * à côté de `dureeMinutes` et `battementMinutes`, là où l'exploitant configure déjà sa prestation
 * — et ça évite une entité de paramétrage, son extension de cloisonnement, sa permission et son
 * écran.
 *
 * ⚠ **DÉFAUT À ZÉRO, ET C'EST LA DÉCISION QUI COMPTE.** Zéro veut dire « pas de rappel ». Toute
 * autre valeur par défaut ferait partir des courriels, dès le prochain passage de l'ordonnanceur,
 * aux clients de tous les établissements existants — qui n'ont rien demandé. Même raisonnement que
 * `battementMinutes`, dont le docblock dit qu'une valeur non nulle par défaut « retirerait
 * silencieusement des créneaux à tous les établissements existants ».
 *
 * ── ⚠ L'ESTAMPILLE EST CE QUI EMPÊCHE DE RAPPELER LE MÊME CLIENT TOUS LES QUARTS D'HEURE ─────────
 *
 * `reminder_sent_at` est nulle jusqu'au premier envoi réussi, et seule une valeur nulle rend un
 * rendez-vous candidat. Elle n'est pas posée quand aucun contact n'est connu : ce n'est pas un
 * échec, et si l'adresse du client arrive demain, le rappel partira.
 *
 * Nullable des deux côtés, donc sûre pendant le déploiement — les migrations passent avant le
 * redémarrage de FPM, et du code ancien qui ignore ces colonnes continue de fonctionner.
 */
final class Version20260905010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rappel de rendez-vous : délai par prestation (défaut 0 = aucun rappel) et estampille anti-doublon.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE reservation_activite
             ADD rappel_heures_avant SMALLINT DEFAULT 0 NOT NULL'
        );
        $this->addSql(
            'ALTER TABLE reservation_reservation
             ADD reminder_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''
        );
    }

    public function down(Schema $schema): void
    {
        // ⚠ LA DESCENTE PERD L'ESTAMPILLE, DONC L'HISTORIQUE DES RAPPELS DÉJÀ ENVOYÉS. Remonter
        // ensuite rappellerait une seconde fois tous les rendez-vous encore à venir dans la
        // fenêtre. C'est acceptable ici — la fenêtre est de sept jours au plus — mais ça se sait
        // avant de descendre, pas après.
        $this->addSql('ALTER TABLE reservation_activite DROP rappel_heures_avant');
        $this->addSql('ALTER TABLE reservation_reservation DROP reminder_sent_at');
    }
}
