<?php

declare(strict_types=1);

namespace App\Platform\Scheduling;

/**
 * Ce que la plateforme doit exécuter périodiquement — et qui, jusqu'au 25/08, ne s'exécutait **jamais**.
 *
 * **Le constat qui a produit ce fichier.** Le dépôt contenait vingt-deux commandes de domaine et aucun
 * ordonnanceur : ni planificateur Symfony, ni cron dans les conteneurs, ni entrée sur l'hôte. Chacune
 * portait dans son en-tête une variante de « à planifier via un cron externe au code applicatif ».
 * Personne ne l'avait fait, et rien ne le signalait. Deux de ces commandes sont des **défauts de
 * sécurité** tant qu'elles ne tournent pas : une élévation de privilèges temporaire ne se termine
 * jamais, une délégation de droits n'expire jamais.
 *
 * **Pourquoi une commande unique plutôt que vingt-deux entrées de cron.** Vingt-deux entrées, ce sont
 * vingt-deux occasions d'en oublier une, et aucun endroit où lire ce qui est censé tourner. Ici la
 * liste est dans le dépôt, elle se relit, elle se teste, et elle n'a besoin que d'**un seul** appel
 * périodique de l'extérieur. Ajouter une tâche devient une ligne, pas une intervention système.
 *
 * **Ce que ce fichier ne résout pas, et qu'il faut dire.** Il reste un déclencheur extérieur à lancer —
 * `platform:scheduler:run` toutes les minutes. Tant que personne ne le lance, RIEN ne tourne, et c'est
 * exactement le défaut qu'on répare. La différence est que l'absence devient **visible** :
 * `platform:scheduler:status` dit, tâche par tâche, quand elle a tourné pour la dernière fois, et hurle
 * si la réponse est « jamais ».
 */
final class ScheduleCatalog
{
    /** @return list<ScheduledTask> */
    public function all(): array
    {
        return [
            // --- Sécurité : ne pas tourner ici n'est pas un retard, c'est une faille ---------------
            new ScheduledTask(
                'securite:delegations:expirer',
                5,
                "Une délégation de droits dont la date de fin est passée reste ACTIVE. Le bénéficiaire "
                . "garde indéfiniment des droits qu'on croyait temporaires.",
                critical: true,
            ),
            new ScheduledTask(
                'autorisation:escalades:expirer',
                5,
                "Une élévation de privilèges accordée pour une opération reste ouverte. C'est le "
                . "contraire exact de ce que le module des autorisations graduées promet.",
                critical: true,
            ),

            // --- Argent et engagement client : un retard se voit par le client ---------------------
            new ScheduledTask(
                'reservation:no-show:basculer',
                15,
                "Le no-show ne bascule jamais. D27 promet au client une séance restituée avec report, "
                . "et rien ne l'exécute : la promesse est faite à l'écran et jamais tenue.",
                critical: true,
            ),
            new ScheduledTask(
                'subscription:facturer-le-mois',
                1440,
                "Les abonnements du mois ne sont pas facturés : le client utilise le logiciel sans "
                . "payer, et rien ne le signale. C'est le défaut des options de mi-mois — corrigé "
                . "depuis — mais à l'échelle du mois entier et de tous les clients.",
                critical: true,
            ),
            new ScheduledTask(
                'boutique:liberer-paniers-expires',
                5,
                "Un panier abandonné retient sa place indéfiniment. Les billets qu'il bloque ne sont "
                . "vendus à personne.",
            ),
            new ScheduledTask(
                'smart-flow:waitlist:expirer',
                5,
                "Une place proposée à un client sur liste d'attente ne se libère jamais pour le suivant.",
            ),
            new ScheduledTask(
                'crm:rgpd:expirer-pmv',
                1440,
                "Le porte-monnaie virtuel n'expire jamais : un solde périmé reste dépensable.",
            ),

            // --- Obligations légales ---------------------------------------------------------------
            new ScheduledTask(
                'crm:rgpd:appliquer-conservation',
                1440,
                "La durée de conservation des données n'est jamais appliquée. C'est une obligation, "
                . "pas une option.",
                critical: true,
            ),
            new ScheduledTask(
                'crm:consentement:verifier-majorite',
                1440,
                "Un mineur devenu majeur garde le régime de consentement de ses parents.",
            ),
            new ScheduledTask(
                'dms:purge-expired-documents',
                1440,
                "Les documents dont la date de rétention est dépassée sont conservés indéfiniment.",
            ),

            // --- Exploitation ----------------------------------------------------------------------
            // `personnel:traiter-echeances-sortie` était absente de ce catalogue du 25/08 au matin
            // jusqu'à cet après-midi. Elle exigeait un argument obligatoire — l'identité de l'agent qui
            // révoque — donc elle ne pouvait pas tourner sans surveillance, donc je l'avais retirée
            // plutôt que de laisser un échec rouge quotidien que personne ne pourrait corriger.
            //
            // Pendant tout ce temps, **un salarié dont le contrat était fini gardait ses accès**.
            // L'en-tête de la commande réclamait pourtant « un compte technique dédié pour l'exécution
            // planifiée » depuis son écriture : il n'avait jamais été créé. Encore le motif de la
            // semaine — le mécanisme existe, l'appel manque.
            //
            // `AccessRevocationServiceAccount` le crée désormais, sur le patron posé par `claude-D`
            // pour la facturation : nommé pour se lire dans un journal d'audit, créé `Suspendu` donc
            // structurellement non connectable, mot de passe aléatoire que personne ne conserve.
            new ScheduledTask(
                'personnel:traiter-echeances-sortie',
                1440,
                "Un salarié dont le contrat est fini garde ses accès : son statut ne bascule pas et son "
                . "badge n'est jamais révoqué. Personne ne le signale — ni erreur, ni alerte.",
                critical: true,
            ),

            new ScheduledTask(
                'personnel:recalculer-fenetres-badges',
                60,
                "Les fenêtres de validité des badges ne suivent pas les changements de planning.",
            ),
            new ScheduledTask(
                'reporting:agreger',
                60,
                "Les mesures ne sont jamais agrégées : les tableaux de bord restent figés.",
            ),
            new ScheduledTask(
                'reporting:executer-rapports',
                60,
                "Les rapports planifiés par les exploitants ne partent jamais.",
            ),
            new ScheduledTask(
                'compta:pca:reprise-mensuelle',
                1440,
                "La reprise mensuelle des produits constatés d'avance n'a pas lieu.",
            ),

            // --- Publication sociale (SOC-2/SOC-3) --------------------------------------------------
            new ScheduledTask(
                'social:dispatch-scheduled-posts',
                15,
                "Une publication programmée n'est jamais envoyée. L'exploitant la croit partie.",
            ),
            new ScheduledTask(
                'social:collect-metrics',
                60,
                "Les statistiques des publications ne sont jamais collectées.",
            ),

            // --- Verticales -------------------------------------------------------------------------
            new ScheduledTask(
                'padel:parties:maintenir-a-3',
                15,
                "Une partie de padel incomplète n'est jamais relancée vers les joueurs.",
            ),
            new ScheduledTask(
                'padel:eclairage:commander',
                5,
                "L'éclairage des terrains n'est ni allumé ni éteint automatiquement. "
                . "⚠ PREMIER PASSAGE NON SÛR : la commande balaie toutes les réservations sans borne "
                . "de date et pilote un relais physique — signalé par claude-G. À borner dans le temps "
                . "(périmètre claude-I) avant de la déclarer sûre.",
            ),
        ];
    }

    public function find(string $command): ?ScheduledTask
    {
        foreach ($this->all() as $task) {
            if ($task->command === $command) {
                return $task;
            }
        }

        return null;
    }
}
