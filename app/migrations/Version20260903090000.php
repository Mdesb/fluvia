<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE DÉLAI LÉGAL D'UN MOIS N'ÉTAIT SURVEILLÉ PAR RIEN, ET RIEN NE POUVAIT SE SOUVENIR DE L'AVOIR DIT.
 *
 * ── CE QUI L'A RÉVÉLÉ ───────────────────────────────────────────────────────────────────────────
 *
 * En sortant l'écran « Données personnelles » du menu quotidien (R27, arbitrage de Maxime), j'ai
 * signalé la contrepartie : une demande d'effacement porte un délai d'UN MOIS, opposable, et aucune
 * des sept tâches planifiées ne le regarde. Déplacé, l'écran devenait moins vu et restait non
 * surveillé. Maxime a tranché : « on met une notification et un badge sur le menu ».
 *
 * ── ⚠ POURQUOI UNE COLONNE, ET PAS UNE REQUÊTE SUR LES NOTIFICATIONS DÉJÀ POSÉES ───────────────
 *
 * Une tâche qui tourne chaque nuit doit savoir ce qu'elle a DÉJÀ signalé, sinon elle re-notifie la
 * même demande toutes les nuits jusqu'à son traitement — et une cloche qui répète s'apprend à ne
 * plus se lire, ce qui est exactement le défaut qu'on essaie de corriger.
 *
 * L'alternative était d'interroger les `Notification` existantes par leur `source` et leur `params`
 * JSON. Elle a été écartée pour une raison précise : une notification est posée PAR DESTINATAIRE.
 * Chercher « ai-je déjà prévenu pour cette demande » y devient « ai-je prévenu chacun des
 * destinataires d'alors », et la réponse change quand quelqu'un gagne ou perd le droit. L'état
 * appartient à la demande, pas à la boîte aux lettres de ceux qui l'ont lue.
 *
 * ── LA COLONNE EST NULLABLE, ET C'EST CE QUI REND LE DÉPLOIEMENT SÛR ────────────────────────────
 *
 * `deploy-preprod.sh` applique les migrations AVANT de redémarrer FPM : entre les deux, le schéma
 * est neuf et le code est ancien. Une colonne nullable traverse cette fenêtre sans rien casser —
 * l'ancien code ne l'écrit pas, et elle n'a pas de défaut à fournir.
 *
 * ⚠ D66-ter : cette migration ne fabrique AUCUNE donnée métier. Toutes les demandes existantes
 * restent à `NULL`, c'est-à-dire « jamais signalée » — ce qui est vrai, puisque rien ne les
 * signalait. Poser une date d'alerte rétroactive aurait fait taire la première exécution sur des
 * demandes réellement en retard.
 */
final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute crm_demande_rgpd.deadline_alerted_at : la date à laquelle le dépassement du délai légal a été signalé.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_demande_rgpd ADD deadline_alerted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : la colonne ajoutée par ce up() et rien d'autre. Aucune donnée métier n'y
        //   vit — elle ne porte que la trace d'un signalement, qui se refera au prochain passage.
        $this->addSql('ALTER TABLE crm_demande_rgpd DROP deadline_alerted_at');
    }
}
