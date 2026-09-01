<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Enum\NotificationSeverity;

/**
 * QUELS ÉVÉNEMENTS DEVIENNENT DES NOTIFICATIONS — ET SURTOUT, LESQUELS NON.
 *
 * ── LE CRITÈRE D'ADMISSION, ET IL EST RESTRICTIF EXPRÈS ─────────────────────────────────────────
 *
 * Un événement devient notification s'il **appelle le geste d'une personne** et s'il est **rare**.
 * Formulé par allaccess-8e en écrivant la cloche, et c'est le bon critère :
 *
 * - `payment.failed` appelle quelqu'un à relancer un client. Il est rare. Il entre.
 * - `access.denied` se produit plusieurs fois par minute à l'ouverture des portes, et personne
 *   n'agit sur un refus isolé. Il n'entre pas.
 *
 * ⚠ CE N'EST PAS UNE LISTE À FAIRE GROSSIR. Vingt-sept événements sont réellement émis par ce dépôt ;
 * cinq entrent ici. Une cloche qui sonne pour tout ne se lit plus, et le jour où elle porte un vrai
 * problème personne ne le voit — c'est exactement ce qui arrive à un bandeau permanent qui répète
 * « tout va bien ».
 *
 * ── CHAQUE RÈGLE PORTE LE DROIT QUI DÉCIDE DU DESTINATAIRE ──────────────────────────────────────
 *
 * On ne notifie pas « les administrateurs » : on notifie **ceux qui peuvent agir**. L'impayé du jour
 * intéresse qui tient le recouvrement, pas le caissier. Le droit requis est donc dans la règle, et
 * {@see NotificationRecipientResolver} s'adosse à `CalculateurDroits` plutôt que de rejouer la règle
 * des permissions.
 *
 * ── ET CHAQUE RÈGLE MÈNE QUELQUE PART ───────────────────────────────────────────────────────────
 *
 * ⚠ Une notification sans destination est du décor. `screen` + les paramètres tirés du sujet sont ce
 * qui la sépare d'une ligne de journal : elle doit ouvrir l'écran où le geste se fait.
 *
 * ── LA LISTE EST UNE HYPOTHÈSE POSÉE, PAS UNE DÉCISION ACQUISE ──────────────────────────────────
 *
 * Le critère a été soumis à Maxime ; en attendant sa réponse, cinq règles sont posées pour que la
 * cloche soit **éprouvable** — une cloche vide ne se vérifie pas. Ajouter ou retirer un événement
 * est une ligne de ce tableau, et rien d'autre.
 */
final class NotificationRule
{
    private function __construct(
        public readonly string $eventName,
        public readonly NotificationSeverity $severity,
        /** Le droit qu'il faut posséder pour être destinataire — « module.action ». */
        public readonly string $module,
        public readonly string $action,
        public readonly string $screen,
        /** La clé sous laquelle l'identifiant du sujet est passé à l'écran. */
        public readonly string $paramName,
        public readonly string $title,
        public readonly string $text,
        /**
         * La cle de charge utile qui NOMME le cas, ou `null` si le titre suffit.
         *
         * ⚠ TROIS LIGNES IDENTIQUES NE SE HIERARCHISENT PAS. Trois rejets le meme matin donnaient
         * trois fois « Un paiement a echoue » : la phrase disait le geste, pas lequel des trois.
         * Le clic desambiguise, la liste non — et c'est la liste qu'on lit pour decider par ou
         * commencer. Constate a l'ecran par allaccess-8e, qui a reduit sa demande a ceci apres
         * l'avoir vu : une reference courte, pas une phrase de plus.
         *
         * Les evenements rares au point d'etre uniques n'en ont pas besoin : un ecart de tresorerie
         * ou une facture contestee ne se presentent pas par trois.
         */
        public readonly ?string $anchorKey = null,
    ) {
    }

    /** @return array<string, self> */
    private static function table(): array
    {
        $regles = [
            new self(
                'payment.failed',
                NotificationSeverity::Warning,
                'recouvrement',
                'lire',
                'recouvrement',
                'incident',
                'Un paiement a échoué',
                'Un prélèvement a été rejeté. Tant que l’incident reste ouvert, l’accès du client peut être bloqué : ouvrez le recouvrement pour voir le motif et relancer.',
                'instalment_ref',
            ),
            new self(
                'payment.incident_reopened',
                NotificationSeverity::Warning,
                'recouvrement',
                'lire',
                'recouvrement',
                'incident',
                'Un impayé a été rouvert',
                'Un incident déjà résolu a été rouvert. Il ne se refermera pas tout seul : vérifiez ce qui a changé.',
                'instalment_ref',
            ),
            new self(
                'treasury.discrepancy_detected',
                // Le seul niveau critique du tableau : un écart de trésorerie ne se planifie pas, il
                // s'interrompt. Si tout était critique, plus rien ne le serait.
                NotificationSeverity::Critical,
                'compta',
                'lire',
                'comptabilite',
                'rapprochement',
                'Écart de trésorerie détecté',
                'Le rapprochement bancaire ne tombe pas juste. Un écart qui attend devient un écart qu’on ne sait plus expliquer.',
            ),
            new self(
                'supplier_invoice.disputed',
                NotificationSeverity::Warning,
                'finance',
                'lire',
                'finance',
                'facture',
                'Une facture fournisseur est contestée',
                'Une facture a été mise en litige. Le fournisseur attend une réponse, et le règlement est suspendu jusque-là.',
            ),
            // Addendum FIN-4 (alertes de trésorerie proactives, §0.8 de `plan-treasury-cash-alerts.md`).
            // Gravité `Warning` (« se planifie », pas `Critical` : un franchissement projeté laisse par
            // construction au moins un jour d'anticipation, sinon la situation serait déjà là).
            //
            // ⚠ Couple `module`/`action` DIFFÉRENT de la règle voisine `treasury.discrepancy_detected`
            // ci-dessus (`compta`/`lire`) : celle-ci cible `finance`/`read`, comme l'exige littéralement
            // §4.5 de `spec-treasury-cash-alerts.md` (« ceux qui peuvent consulter la trésorerie de cet
            // établissement »). Écart constaté et non corrigé — signalé pour qu'un futur agent ne
            // s'étonne pas de voir deux couples différents sur deux règles `treasury.*` voisines ; à
            // confirmer en revue si les deux règles devraient converger (§7 point 3 du plan).
            new self(
                'treasury.threshold_breached',
                NotificationSeverity::Warning,
                'finance',
                'read',
                'finance',
                'alert',
                'Seuil de trésorerie bientôt franchi',
                'Le solde projeté passerait sous le seuil configuré avant l’échéance connue. Consultez la '
                .'position et l’échéancier pour décider : relancer, décaler un paiement, ou prévenir la '
                .'collectivité.',
                'projected_breach_date',
            ),
        ];

        // ── `expense_report.submitted` A ÉTÉ RETIRÉ, ET CE N'EST PAS UN OUBLI ───────────────────
        //
        // Il y figurait, comme seul niveau « info ». Retiré le 30/08 sur l'argument d'allaccess-8e,
        // qui est net : une notification annonce un fait ET propose un geste, et celle-ci échouait
        // aux deux bouts.
        //
        //   · LE FAIT NE PEUT PAS SE PRODUIRE. Aucun écran ne permet de soumettre une note de frais
        //     — vérifié par deux chemins indépendants, le sien et celui d'allaccess-b8 : le client
        //     d'API ne porte que `expense_account_mappings`, en lecture, pour neuf opérations
        //     serveur.
        //   · ET S'IL SE PRODUISAIT, par une intégration ou un script, le clic mènerait à Finance,
        //     où il n'y a rien à valider. Le chemin de retour est vide.
        //
        // ⚠ LE GARDER EN FAISANT DIRE AU CLIC « RIEN À OUVRIR » SERAIT PIRE QUE DE NE RIEN ANNONCER :
        // on annoncerait à quelqu'un un travail qu'il ne peut pas faire. Une cloche perd sa
        // crédibilité à la première ligne qui ne mène nulle part, et elle ne la regagne pas.
        //
        // À remettre le jour où l'écran des notes de frais existe — la règle est écrite ci-dessus
        // dans l'historique, il n'y aura qu'à la reposer.

        $table = [];
        foreach ($regles as $regle) {
            $table[$regle->eventName] = $regle;
        }

        return $table;
    }

    /** La règle pour cet événement, ou `null` s'il ne devient pas une notification. */
    public static function forEvent(string $eventName): ?self
    {
        return self::table()[$eventName] ?? null;
    }

    /** @return list<string> les noms d'événements admis — sert à l'abonnement du bus */
    public static function admittedEvents(): array
    {
        return array_keys(self::table());
    }
}
