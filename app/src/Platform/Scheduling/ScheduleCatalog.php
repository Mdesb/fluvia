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
 * **Le critère du premier passage, et pourquoi le premier était mauvais.** J'ai d'abord cherché si une
 * commande **bornait sa requête dans le temps**. C'est le mauvais critère : `securite:delegations:expirer`
 * borne parfaitement — `dateFin <= maintenant` — et traite pourtant, au premier passage, tout l'arriéré.
 * La vraie question est : **que produit un arriéré traité d'un coup ?** Trois cas, et un seul est sûr :
 *
 * 1. **Nettoyage d'état interne** — expirer une délégation, libérer un panier, recalculer une fenêtre.
 *    L'arriéré est le rattrapage qu'on voulait. **Sûr.**
 * 2. **Destruction irréversible** — `dms:purge-expired-documents` appelle `Storage::delete()` et retire
 *    le contenu physique ; `crm:rgpd:expirer-pmv` écrit un mouvement négatif du solde entier.
 *    L'arriéré détruit des fichiers et de la valeur client, en une fois, sans retour. **Jamais sûr.**
 * 3. **Effet visible au dehors** — publier sur un réseau social, facturer, notifier un client, écrire
 *    en comptabilité. L'arriéré est correct et *quand même* inacceptable : personne ne veut découvrir
 *    trente publications parties ensemble. **Jamais sûr.**
 *
 * Ce n'est donc pas une question de justesse — les trois cas font ce qu'on leur demande. C'est une
 * question de **simultanéité**, et la simultanéité mérite quelqu'un devant l'écran une fois.
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
            // --- Conformité fiscale : ne pas tourner ici ne se voit qu'au contrôle ------------------
            new ScheduledTask(
                'vente:cloture:journee',
                1440,
                "Aucune journee n est arretee. NF525 exige une cloture quotidienne, et une obligation "
                . "legale ne peut pas dependre de ce que quelqu un pense a faire : un exploitant qui "
                . "oublie trois semaines n a pas ete negligent, il a rencontre un produit qui lui "
                . "demandait d etre un mecanisme. Le manque ne se decouvre qu au controle.",
                critical: true,
                // JAMAIS SÛR AU PREMIER PASSAGE — une clôture SCELLE. Sept points de vente actifs,
                // donc sept arrêtés irréversibles au premier passage : catégorie « destruction
                // irréversible », qui mérite quelqu'un devant l'écran une fois. `--dry-run` montre ce
                // qui partirait avant que quiconque décide.
                //
                // ⚠ CETTE PHRASE DISAIT « UN ARRIÉRÉ DE TROIS SEMAINES PRODUIRAIT VINGT ET UN ARRÊTÉS
                //   D'UN COUP ». C'est faux, et vérifié dans le code le 06/09 :
                //   `CloseBusinessDayCommand::execute()` calcule `now(-1 day)` dans le fuseau de
                //   l'établissement et appelle `close($pdv, $veille)` une fois par point de vente.
                //   Elle ne ferme QUE LA VEILLE. Il n'existe pas de balayage d'arriéré à craindre.
                //
                // ⚠ ET LE VRAI DÉFAUT EST L'INVERSE DE CELUI QU'ON CRAIGNAIT : la commande NE
                //   RATTRAPE JAMAIS. Un jour où elle ne tourne pas reste ouvert pour toujours de son
                //   point de vue. Le rattrapage existe ailleurs — `CloseDayProcessor`, branché sur
                //   `PointDeVente`, accepte `{"journee": "AAAA-MM-JJ"}` et ferme n'importe quel jour
                //   passé — mais AUCUN ÉCRAN ne l'expose : `clotures-journalieres`, `dailyClosure` et
                //   `journaliere` rendent zéro sur tout `frontend/src`. La file des journées non
                //   closes est donc calculée, servie, et invisible.
                //
                // Et ce qui échoue ne disparaît pas : la journée reste dans la file
                // `/clotures-journalieres/en-attente`, avec sa raison. Une clôture manquée EN SILENCE
                // serait le défaut de D57 reproduit un cran plus haut — on échangerait un oubli
                // visible contre un oubli invisible, et tout le monde croirait que c'est fait.
                safeOnFirstRun: false,
            ),
            new ScheduledTask(
                'crm:rgpd:alerter-delai',
                1440,
                "Une demande RGPD depasse le delai legal d'un mois sans que personne le sache. Le "
                . "delai est opposable, et l'ecran qui porte ces demandes a quitte le menu quotidien "
                . "le 03/09 : plus rien ne le met sous les yeux. Aucun geste ne fera remarquer le "
                . "depassement — c'est le temps qui passe, et le temps ne declenche rien tout seul.",
                critical: true,
                // ⚠ SÛRE AU PREMIER PASSAGE, ET C'EST MESURÉ, PAS SUPPOSÉ. Elle ne fait qu'émettre
                // un événement et poser une date ; rien d'irréversible, rien qui parte au dehors.
                // Et l'arriéré est nul : préproduction du 03/09, 2 demandes, 1 en attente, ZÉRO
                // au-delà du mois. Un premier passage ne signale rien.
                //
                // ⚠ Sur un parc où beaucoup seraient déjà en retard, il en signalerait autant —
                // voulu : une demande hors délai EST un incident. `--plafond` existe pour découvrir
                // l'ampleur avant d'ouvrir les vannes.
                safeOnFirstRun: true,
                nightlyAt: '02:00',
            ),
            // --- Sécurité : ne pas tourner ici n'est pas un retard, c'est une faille ---------------
            new ScheduledTask(
                'securite:delegations:expirer',
                5,
                "Une délégation de droits dont la date de fin est passée reste ACTIVE. Le bénéficiaire "
                . "garde indéfiniment des droits qu'on croyait temporaires.",
                critical: true,
                // SÛR AU PREMIER PASSAGE — nettoyage d'état interne : l'arriéré révoque des droits qui auraient dû l'être. C'est le rattrapage qu'on veut, et il se défait en réattribuant.
                safeOnFirstRun: true,
            ),
            new ScheduledTask(
                'autorisation:escalades:expirer',
                5,
                "Une élévation de privilèges accordée pour une opération reste ouverte. C'est le "
                . "contraire exact de ce que le module des autorisations graduées promet.",
                critical: true,
                // SÛR AU PREMIER PASSAGE — idem — ferme des élévations qui traînent, sans effet au dehors.
                safeOnFirstRun: true,
            ),

            // --- Argent et engagement client : un retard se voit par le client ---------------------
            new ScheduledTask(
                'reservation:no-show:basculer',
                15,
                "Le no-show ne bascule jamais. D27 promet au client une séance restituée avec report, "
                . "et rien ne l'exécute : la promesse est faite à l'écran et jamais tenue.",
                critical: true,
            ),
            // ⚠ CETTE TÂCHE ÉTAIT DANS LA LISTE BLANCHE DE L'ORDONNANCEUR ET ABSENTE D'ICI.
            //
            //   `infra/ordonnanceur.sh` la lançait à chaque cycle par `--only=`, le lanceur ne
            //   trouvait aucune tâche de ce nom, sortait 0 sans un mot, et l'ordonnanceur écrivait
            //   « ok ». `--status` ne pouvait pas la signaler non plus : il ne lit que ce
            //   catalogue, donc elle n'y figurait même pas comme « JAMAIS ». Invisible des deux
            //   côtés à la fois — c'est ça qui coûte, pas l'oubli lui-même.
            //
            //   Le correctif de la CLASSE est dans `RunScheduledTasksCommand` : un `--only` qui
            //   ne désigne rien échoue désormais bruyamment.
            new ScheduledTask(
                'reservation:confirmations:expirer',
                15,
                "Les réservations à confirmer n'expirent jamais. Le créneau reste bloqué pour "
                . "quelqu'un qui n'a rien confirmé, et — sur le chemin padel, où la vente est créée "
                . "à la réservation — la vente rattachée reste due par ce client. Une place "
                . "invendable et une créance fantôme, sans que rien ne le signale.",
                critical: true,
                // JAMAIS SÛR AU PREMIER PASSAGE. Un arriéré traité d'un coup libère d'un seul
                // geste tous les créneaux échus ET annule leurs ventes rattachées : c'est le cas
                // 3 (effet visible au dehors), pas le cas 1. Aujourd'hui le délai de confirmation
                // est nul partout en préproduction, donc l'arriéré est vide — mais le jour où on
                // l'allume, le premier passage balaierait tout le passé. `--dry-run` le montre.
                safeOnFirstRun: false,
            ),
            new ScheduledTask(
                'sepa:preavis:annoncer',
                1440,
                "Les prelevements SEPA ne sont annonces a personne. Un creancier doit informer le "
                . "debiteur du montant et de la date avant chaque prelevement ; sans cette commande, "
                . "aucune echeance n'est jamais couverte et la collecte s'arrete entierement — "
                . "silencieusement, puisque tout s'execute et que rien n'aboutit.",
                critical: true,
                // JAMAIS SÛR AU PREMIER PASSAGE — effet visible au dehors (cas 3) : la commande envoie
                // des courriels a des clients reels. Un arriere traite d'un coup est correct et
                // quand meme inacceptable : personne ne veut decouvrir trois cents preavis partis
                // ensemble. `--dry-run` montre ce qui partirait avant que quiconque decide.
                safeOnFirstRun: false,
                // Fenetre nocturne, arbitree par Maxime le 01/09 : les taches d'argent
                // tournent la nuit, a partir de 02h00 LOCALES. Quand ce champ est pose,
                // `everyMinutes` ne decide plus rien — voir `NightlyWindow`.
                nightlyAt: '02:00',
                // Rang 20 : le preavis annonce ce que le renouvellement vient de creer.
                order: 20,
            ),
            // Addendum FIN-4 (alertes de trésorerie proactives, §0.9 de `plan-treasury-cash-alerts.md`,
            // RG-TRE-16). ⚠ Constat repris de la spec, non corrigé ici : `finance:treasury:
            // detecter-ecarts`/`finance:treasury:suggerer-rapprochements` (déjà codées et livrées) sont
            // toujours ABSENTES de ce catalogue — hors périmètre de cet addendum, mais la commande
            // ci-dessous ne doit pas reproduire cet oubli.
            new ScheduledTask(
                'finance:treasury:verifier-seuils',
                1440,
                "Un seuil de tresorerie configure n'est jamais verifie. Un exploitant qui active l'alerte "
                . "decouvre alors son decouvert le jour ou il survient, exactement le defaut que cette "
                . "alerte proactive existe pour corriger.",
                critical: true,
                // JAMAIS SÛR AU PREMIER PASSAGE — effet visible au dehors (cas 3, comme
                // sepa:preavis:annoncer) : la commande notifie une personne reelle. Un premier passage
                // sur un parc ou le seuil serait active apres coup, sur des etablissements deja en
                // tension, enverrait une salve d'alertes simultanees a superviser, pas a lancer en
                // silence (RG-TRE-16).
                safeOnFirstRun: false,
            ),
            new ScheduledTask(
                'subscription:trials:expire',
                1440,
                "Un essai gratuit de quatorze jours ne se termine jamais. Le client garde une "
                . "plateforme complete et gratuite indefiniment, et personne ne s en apercoit : rien "
                . "n echoue, rien n alerte, la seule trace est une colonne `trial_ends_at` depassee "
                . "que personne ne regarde. C est le defaut le plus discret d un essai libre-service.",
                // Fenetre nocturne comme les autres taches d argent (arbitrage du 01/09), et AVANT
                // la facturation : un essai echu doit avoir bascule ou etre suspendu quand elle passe.
                nightlyAt: '02:00',
                // Rang 20 : avant `subscription:facturer-le-mois` (rang 30).
                order: 20,
                // SÛRE AU PREMIER PASSAGE, et le raisonnement compte plus que la conclusion.
                // Elle SUSPEND, et suspendre n efface rien (RG-ED-06) : l exposition se coupe, les
                // donnees restent, la regularisation les reexpose. Ce n est donc ni le cas 2
                // (destruction irreversible) ni le cas 3 (effet visible au dehors : aucun message ne
                // part d ici). Un arriere traite d un coup ferme des acces qui auraient du l etre —
                // c est exactement le rattrapage voulu.
                // ⚠ Ce qu elle ne fait PAS, et qui rend son retard inoffensif cote argent : elle ne
                // rend personne facturable. La facturation exclut seule tout essai sans mandat actif.
                safeOnFirstRun: true,
            ),
            new ScheduledTask(
                'subscription:facturer-le-mois',
                1440,
                "Les abonnements du mois ne sont pas facturés : le client utilise le logiciel sans "
                . "payer, et rien ne le signale. C'est le défaut des options de mi-mois — corrigé "
                . "depuis — mais à l'échelle du mois entier et de tous les clients.",
                critical: true,
                // Fenetre nocturne, arbitree par Maxime le 01/09 : les taches d'argent
                // tournent la nuit, a partir de 02h00 LOCALES. Quand ce champ est pose,
                // `everyMinutes` ne decide plus rien — voir `NightlyWindow`.
                nightlyAt: '02:00',
                // Rang 30 : la facturation encaisse ce qui a ete annonce.
                order: 30,
            ),
            new ScheduledTask(
                'boutique:liberer-paniers-expires',
                5,
                "Un panier abandonné retient sa place indéfiniment. Les billets qu'il bloque ne sont "
                . "vendus à personne.",
                // SÛR AU PREMIER PASSAGE — libère des places retenues par des paniers abandonnés. Aucun effet visible d'un client, et c'est exactement le rattrapage attendu.
                safeOnFirstRun: true,
            ),
            new ScheduledTask(
                'smart-flow:waitlist:expirer',
                5,
                "Une place proposée à un client sur liste d'attente ne se libère jamais pour le suivant.",
            ),
            new ScheduledTask(
                'revenue-recovery:attempts:send',
                // 1440 comme les deux autres tâches qui écrivent à des clients : les étapes d'une
                // politique se comptent en JOURS (J+1, J+3, J+7). Un passage aux cinq minutes
                // n'avancerait rien et multiplierait les occasions de partir en double.
                1440,
                "Les relances se programment et rien ne part : un dossier s'ouvre, ses tentatives "
                . "s'accumulent, et le client n'entend jamais parler de sa facture impayée. Le "
                . "module entier était inerte faute de cette commande.",
                // ⚠ JAMAIS SÛR AU PREMIER PASSAGE — même raison que `sepa:preavis:annoncer`, et
                // mesurée sur la commande : `sendDueAttempts()` sélectionne toutes les tentatives
                // dont l'échéance est passée, SANS BORNE BASSE ni limite. Un premier passage
                // rattraperait tout l'historique, et personne ne veut découvrir trois cents
                // relances parties ensemble. La levée est manuelle, et `--dry-run` montre ce qui
                // partirait avant que quiconque décide.
                safeOnFirstRun: false,
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
                // SÛR AU PREMIER PASSAGE — bascule un régime de consentement interne. Rien ne part, rien ne se détruit.
                safeOnFirstRun: true,
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
                // NON SÛR AU PREMIER PASSAGE, et pourtant ce qu'elle ferait est juste : elle traite
                // **toutes** les sorties passées d'un coup, donc révoque en une salve les badges de tous
                // ceux qui sont partis depuis la mise en service. C'est exactement ce qu'il faut faire —
                // mais une révocation de masse mérite quelqu'un devant l'écran la première fois, ne
                // serait-ce que pour constater l'ampleur de ce qui traînait.
            ),

            // SÛR AU PREMIER PASSAGE — vérifié en lisant `RecalculFenetreBadgeHandler` :
            // `recalculerTous()` recalcule chaque badge à partir de **maintenant**. Il ne rejoue aucun
            // historique, il recompose un état présent, et il est idempotent : deux exécutions de suite
            // produisent le même résultat.
            // SÛR AU PREMIER PASSAGE — elle LIT et rapporte : aucune écriture, aucun envoi, aucun
            // effet visible au dehors. Un arriéré traité d'un coup est exactement le constat qu'on
            // veut. Catégorie 1 du critère, la seule des trois qui n'exige pas de supervision.
            new ScheduledTask(
                'personnel:qualifications:verifier',
                1440,
                "L'affectation vérifie la qualification AU JOUR DU CRÉNEAU, mais une seule fois : au "
                . "moment où on la crée. Rien ne la revoit ensuite. Une qualification révoquée, "
                . "raccourcie ou supprimée après coup laisse l'affectation en place, et le planning "
                . "ne recalcule que si quelqu'un l'ouvre — or le cas dangereux est celui d'un planning "
                . "monté il y a trois semaines que plus personne ne rouvre. Dans une piscine, ce sont "
                . "des surveillants qui n'ont plus le droit de surveiller.",
                safeOnFirstRun: true,
            ),
            new ScheduledTask(
                'personnel:recalculer-fenetres-badges',
                60,
                "Les fenêtres de validité des badges ne suivent pas les changements de planning : "
                . "un agent dont l'horaire a bougé garde l'ancienne fenêtre.",
                safeOnFirstRun: true,
            ),
            // SÛR AU PREMIER PASSAGE — vérifié : sans option, la commande borne son travail à
            // `today`. Elle ne remonte pas l'historique, il faut le lui demander explicitement avec
            // `--depuis`.
            new ScheduledTask(
                'reporting:agreger',
                60,
                "Les mesures ne sont jamais agrégées : les tableaux de bord restent figés.",
                safeOnFirstRun: true,
            ),
            // NON SÛR AU PREMIER PASSAGE — vérifié : la requête retient les rapports dont
            // `prochainEnvoi` est **nul** ou dépassé. Tout rapport planifié et jamais envoyé partirait
            // donc **en une seule salve**, à ses destinataires réels. Chacun est légitimement dû ; c'est
            // leur simultanéité qui mérite un œil. Le premier passage reste supervisé.
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
                // SÛR AU PREMIER PASSAGE — lit des statistiques chez le réseau. Aucune écriture au dehors.
                safeOnFirstRun: true,
            ),

            // --- Verticales -------------------------------------------------------------------------
            new ScheduledTask(
                'padel:parties:maintenir-a-3',
                15,
                "Une partie de padel incomplète n'est jamais relancée vers les joueurs.",
            ),
            // ⚠ AVANT LE TERME, ET L'ORDRE EST TOUTE LA RAISON D'ÊTRE DE CETTE PLACE.
            //
            //   Une résiliation en préavis laisse l'abonnement `Actif` jusqu'à son effet. Or la
            //   tâche suivante sélectionne exactement les abonnements `Actif` arrivés au terme et
            //   RECONDUIT leur engagement — en ignorant complètement les résiliations :
            //   `Resiliation` n'apparaît ni dans `ProcessSubscriptionTermsCommand` ni dans
            //   `SubscriptionTermHandler` (0 occurrence dans les deux, mesuré le 06/09). Les deux
            //   tâches tombent la même nuit ; si le terme passait en premier, l'adhérent qui a
            //   résilié serait reconduit pour un an la nuit même de son effet.
            //
            //   Rang 5 contre 10 : `usort` est stable, mais deux rangs égaux ne diraient rien.
            new ScheduledTask(
                command: 'sport:resiliations:appliquer',
                everyMinutes: 1440,
                why: "Une résiliation ne prend jamais effet. `executerEffet()` — le seul code qui "
                    . "passe l'abonnement en résilié, révoque le mandat, annule les échéances "
                    . "restantes et coupe l'accès — n'avait AUCUN appelant : trois occurrences dans "
                    . "tout le dépôt, sa déclaration et deux commentaires. L'adhérent qui résilie "
                    . "reste prélevé et son badge ouvre encore la porte.",
                critical: true,
                // JAMAIS SÛR AU PREMIER PASSAGE — catégorie « effet visible au dehors » : sur un
                // parc réel, toutes les résiliations dont la date d'effet est déjà passée
                // prendraient effet d'un coup, donc autant d'accès coupés et de mandats révoqués
                // en une fois. `--dry-run` montre la liste avant que quiconque décide.
                safeOnFirstRun: false,
                nightlyAt: '02:00',
                order: 5,
            ),
            // ⚠ LE PREMIER VRAI CLIENT DU VERROU DE PREMIER PASSAGE (D109).
            //
            // Son premier passage traite D'UN COUP tous les abonnements deja au terme : sur un parc
            // reel, des dizaines d'engagements reconduits ou d'acces coupes, en une fois, sans que
            // personne ait vu la liste. `safeOnFirstRun: false` n'est donc pas une precaution de
            // forme -- et jusqu'a ce matin le verrou qui devait la retenir etait court-circuite.
            //
            // Le premier passage se regarde avec `--dry-run` avant d'etre lance.
            new ScheduledTask(
                command: 'sport:abonnements:traiter-terme',
                everyMinutes: 1440,
                why: "Sans elle, un abonnement au terme reste « actif » pendant que ses echeances "
                    . "s'arretent : le prelevement cesse, l'acces reste valide, et l'adherent "
                    . "continue d'entrer GRATUITEMENT jusqu'a ce qu'un humain s'en apercoive.",
                safeOnFirstRun: false,
                // Fenetre nocturne, arbitree par Maxime le 01/09 : les taches d'argent
                // tournent la nuit, a partir de 02h00 LOCALES. Quand ce champ est pose,
                // `everyMinutes` ne decide plus rien — voir `NightlyWindow`.
                nightlyAt: '02:00',
                // Rang 10 : le renouvellement CREE les echeances des deux suivantes.
                order: 10,
            ),
            new ScheduledTask(
                'padel:eclairage:commander',
                5,
                "L'éclairage des terrains n'est ni allumé ni éteint automatiquement. "
                . "⚠ PREMIER PASSAGE NON SÛR : la commande balaie toutes les réservations sans borne "
                . "de date et pilote un relais physique — signalé par claude-G. À borner dans le temps "
                . "(périmètre claude-I) avant de la déclarer sûre.",
            ),
            new ScheduledTask(
                'reservation:reminders:send',
                15,
                "Sans rappel, un client oublie son rendez-vous et la place reste vide : on facture "
                . "l'absence au lieu de l'eviter. C'etait le seul manque bloquant du module pour un "
                . "metier de rendez-vous.",
                // PAS SUR AU PREMIER PASSAGE, malgre ses deux bornes de date. La commande n'ecrit
                // pas dans un coin de la base : elle envoie de VRAIS messages a de VRAIES personnes,
                // et c'est irreversible. `--simuler` liste ce qui partirait sans rien envoyer ni
                // estampiller : c'est par la que se regarde le premier passage.
                safeOnFirstRun: false,
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
