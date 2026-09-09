<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Enum\BlockType;

/**
 * La liste des blocs de contenu que les gabarits attendent (ED-10, ED-11).
 *
 * Deux familles, et le groupe les sépare dans l'écran : les blocs de la PAGE D'ACCUEIL, déclarés
 * un par un ci-dessous, et un corps rédigeable par MODULE, dérivé du catalogue technique. Les
 * seconds ne sont pas écrits à la main : ajouter une capacité au produit fait apparaître son bloc,
 * en retirer une le fait disparaître. Une liste recopiée aurait divergé au premier module ajouté.
 *
 * ⚠ **C'EST LE CODE QUI DÉCLARE LES BLOCS, ET LA BASE QUI PORTE LEUR VALEUR.** Le gabarit sait de
 * quoi la page est faite ; la base ne le sait pas. Inverser les deux — « les blocs sont ce que
 * contient la table » — produit le défaut le plus discret d'un petit site administrable : une clé
 * disparaît, la page se rend sans elle, et personne ne voit qu'il manque un titre. Avec cette liste,
 * l'écran d'administration montre un bloc **non rempli** au lieu de ne pas le montrer.
 *
 * ⚠ **`initialValue` NE SERT QU'UNE FOIS, ET LE RENDU NE S'EN SERT JAMAIS.** C'est la valeur que
 * `website:blocks:seed` écrit en base quand la clé n'y est pas encore. Le gabarit, lui, ne connaît
 * que la base : un repli du gabarit sur ces valeurs ferait une page qui affiche autre chose que ce
 * que l'écran d'administration montre — l'écran dirait « vide », la page dirait un texte, et
 * personne ne saurait lequel des deux ment. Un bloc absent en base ne rend rien.
 */
final class SiteBlocks
{
    /**
     * Ce que la page « Nous écrire » publie.
     *
     * ⚠ **UNE ADRESSE, PAS UN FORMULAIRE, ET C'EST UNE DÉCISION.** Aucun courriel ne sort de la
     * plateforme aujourd'hui (E-8). Un formulaire afficherait « message envoyé » sans que rien ne
     * parte : le visiteur croirait avoir écrit, il ne réessaierait pas, et personne ne saurait qu'il
     * a essayé. Une adresse marche sans serveur d'envoi.
     *
     * ⚠ **ELLE EST ÉDITABLE, ET SA VALEUR D'ORIGINE EST SEMÉE.** Changer l'adresse de contact ne doit
     * pas demander une mise en production. Le semis ne réécrit jamais une valeur existante, donc une
     * adresse corrigée à la main survit au déploiement suivant.
     *
     * @return list<array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}>
     */
    private static function blocsDeContact(): array
    {
        return [
            self::bloc('contact.lead', BlockType::Paragraph, 'Chapô — page Contact',
                'Ce qu\'on peut vous demander, et sous quel délai vous répondez.',
                ['text' => "Une question sur un module, une démonstration, un partenariat, ou un problème sur "
                    ."votre plateforme : écrivez-nous. Dites-nous ce que vous accueillez — une piscine, une salle, "
                    ."un musée — et ce que vous cherchez à régler."],
                'contact'),

            self::bloc('contact.email', BlockType::Line, 'Adresse de courriel',
                'Publiée telle quelle sur la page. Vide, la page dit qu’aucune adresse n’est publiée plutôt que d’afficher un lien mort.',
                ['text' => 'maxime@onefitness-services.com'],
                'contact'),

            self::bloc('contact.details', BlockType::Paragraph, 'Autres moyens — facultatif',
                'Téléphone, adresse postale, horaires. Laissez vide si vous n’en publiez pas.',
                ['text' => ''],
                'contact'),
        ];
    }

    /**
     * @param list<array{code: string, nom: string}> $metiers les metiers a declarer ; VIDE = ceux du repli
     *
     * @return list<array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}>
     */
    public static function all(array $metiers = []): array
    {
        return array_merge(
            self::blocsDaccueil(),
            self::blocsDeModule(),
            self::blocsDeMetier($metiers),
            self::blocsDeContact(),
        );
    }

    /**
     * @return list<array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}>
     */
    private static function blocsDaccueil(): array
    {
        return [
            self::bloc('home.hero.title', BlockType::Line, 'Titre principal',
                'Le premier mot du visiteur, et le titre de l’onglet.',
                ['text' => 'Fluvia. Une seule plateforme, les modules que vous choisissez.']),

            self::bloc('home.hero.lead', BlockType::Paragraph, 'Chapô',
                'Trois lignes au plus : ce que fait le produit, et ce qu’il ne fait pas payer.',
                ['text' => "Billetterie, réservation, contrôle d'accès, caisse, boutique en ligne, CRM, facturation : "
                    ."activez ce dont vous avez besoin, laissez le reste éteint. Vous ne payez que ce que vous activez, "
                    ."et vous pouvez changer d'avis en cours de mois. Quatorze jours d'essai pour vous en assurer, sans carte bancaire."]),

            /*
             * ⚠ LES REPERES ENTRE CROCHETS SONT VOLONTAIRES, ET ILS DOIVENT LE RESTER JUSQU'A CE
             * QUE MAXIME LES REMPLACE.
             *
             * C'est la seule bande de la page qui parle de preuve : des clients, un nombre
             * d'etablissements. Personne d'autre que lui ne peut la remplir. Y mettre un chiffre
             * plausible en attendant serait un mensonge sur une page de vente — et le genre de
             * mensonge qu'on oublie d'enlever.
             */
            self::bloc('home.proof.items', BlockType::Items, 'Bande de preuve — sous le bandeau',
                'Vos clients et vos chiffres. Remplacez les reperes entre crochets ; une entree par ligne.',
                ['items' => [
                    '[ VOS CLIENTS ]',
                    '[ N ] etablissements',
                    '17 modules activables',
                    '14 jours d\'essai sans carte',
                ]]),

            self::bloc('home.modules.title', BlockType::Line, 'Titre — section Modules', '',
                ['text' => 'Un socle commun, des modules à la carte']),

            self::bloc('home.modules.lead', BlockType::Paragraph, 'Texte — section Modules',
                'Les paragraphes se séparent par une ligne vide.',
                ['text' => "La plupart des logiciels de ce marché vous vendent un métier entier, dont vous n'utilisez "
                    ."qu'une part. Ici, le socle — comptes, établissements, droits, journal, communication — est toujours "
                    ."là ; le reste s'active à la demande.\n\nUn module éteint n'est pas un module absent : vos données "
                    ."restent, l'accès seul se ferme. Le jour où vous le rallumez, vous retrouvez tout."]),

            self::bloc('home.trades.title', BlockType::Line, 'Titre — section Métiers', '',
                ['text' => 'Le même socle, votre vocabulaire']),

            self::bloc('home.trades.lead', BlockType::Paragraph, 'Texte — section Métiers', '',
                ['text' => "Un créneau n'est pas la même chose partout : c'est une réservation de terrain au padel, une "
                    ."séance à la piscine, une visite au musée. Le logiciel parle votre langue plutôt que de vous imposer la sienne."]),

            self::bloc('home.trades.note', BlockType::Paragraph, 'Note — section Métiers', '',
                ['text' => "Votre métier n'est pas dans la liste ? Le socle ne suppose aucune activité particulière : "
                    ."c'est la configuration qui change, pas le logiciel."]),

            self::bloc('home.pricing.title', BlockType::Line, 'Titre — section Tarifs', '',
                ['text' => 'Tarifs']),

            self::bloc('home.pricing.lead', BlockType::Paragraph, 'Texte — section Tarifs',
                '⚠ Les PRIX ne se saisissent pas ici : ils sont lus sur le catalogue réel. Ce bloc n’en porte aucun.',
                ['text' => "Une formule mensuelle, plus les options que vous ajoutez. Pas d'engagement de durée, pas de "
                    ."facturation au billet vendu ni au visiteur : vous payez des modules, pas des volumes."]),

            /*
             * ⚠ CE BLOC NE PORTE AUCUN PRIX, et il ne doit jamais en porter. Les montants viennent
             * du catalogue reel ; un tarif saisi ici divergerait de celui qui est facture, et c'est
             * le prospect qui releverait l'ecart au premier prelevement.
             *
             * Il dit ce que la FORMULE comprend — ce que `Plan::includedCapabilities` ne peut pas
             * dire, puisque CRM, facturation et statistiques ne sont pas des capacites du catalogue.
             */
            self::bloc('home.pricing.socle', BlockType::Items, 'Ce que comprend la formule de base',
                'Une ligne par element. N\'y mettez PAS de prix : ils sont lus sur le catalogue.',
                ['items' => [
                    'Gestion client (CRM)',
                    'Facturation',
                    'Statistiques et tableaux de bord',
                    'Comptes, etablissements, droits, journal',
                ]]),

            self::bloc('home.funnel.title', BlockType::Line, 'Titre — section Essai', '',
                ['text' => "Quatorze jours d'essai, sans carte bancaire"]),

            self::bloc('home.funnel.steps', BlockType::Cards, 'Les étapes de l’essai',
                'Le titre est mis en avant, le texte suit sur la même ligne.',
                ['items' => [
                    ['title' => 'Vous composez.', 'text' => "Vous choisissez une formule et les options qui vous manquent. Le total s'affiche avant que vous n'engagiez quoi que ce soit — et c'est notre serveur qui le calcule, pas cette page."],
                    ['title' => 'Vous confirmez votre adresse.', 'text' => "Nous vous envoyons un lien. Rien n'est créé tant que vous ne l'avez pas ouvert : c'est ce qui nous évite d'ouvrir des plateformes au nom de gens qui n'ont rien demandé."],
                    ['title' => 'Votre plateforme est ouverte pour quatorze jours.', 'text' => 'Votre établissement est créé, votre compte administrateur reçoit son invitation, et seuls les modules choisis sont actifs. Aucun prélèvement, aucune carte bancaire demandée.'],
                ]]),

            self::bloc('home.footer.text', BlockType::Paragraph, 'Pied de page', '',
                ['text' => "Essai de 14 jours sans carte bancaire. Vos données restent les vôtres : un module désactivé "
                    ."n'efface rien, et un essai qui s'arrête ne supprime pas ce que vous avez saisi."]),
        ];
    }

    /**
     * Le type attendu pour une clé, ou `null` si la clé n'est pas déclarée.
     *
     * @param list<array{code: string, nom: string}> $metiers les métiers à déclarer ; VIDE = ceux du repli
     */
    public static function typeOf(string $key, array $metiers = []): ?BlockType
    {
        foreach (self::all($metiers) as $bloc) {
            if ($bloc['key'] === $key) {
                return $bloc['type'];
            }
        }

        return null;
    }

    /**
     * Un corps rédigeable par métier, un par métier publié.
     *
     * Même règle que pour les modules : la liste vient du produit, pas d'une saisie. Un métier de
     * plus fait apparaître sa page ET son bloc, sans que personne ait à s'en souvenir — et depuis ce
     * lot, ce métier de plus peut être une simple ligne en base, sans déploiement.
     *
     * ⚠ **LA LISTE ARRIVE, ELLE NE SE CHERCHE PAS.** Cette classe s'annonce, en tête, comme « une
     * DÉCLARATION : elle doit rester lisible sans conteneur » — on peut lire le catalogue des blocs
     * sans démarrer l'application. Lui donner accès à la base pour aller chercher les métiers
     * casserait cet invariant ; lui passer la liste ne le casse pas.
     *
     * ⚠ **VIDE VEUT DIRE « CEUX DU REPLI », PAS « AUCUN ».** C'est exactement le comportement
     * d'avant ce lot : les cinq métiers de `TradeFallback`, via `MetierCatalog::codes()`. Sans ce
     * défaut, un appelant qui oublierait l'argument déclarerait ZÉRO bloc de métier — et les cinq
     * textes existants deviendraient inenregistrables, sans erreur et sans page cassée, jusqu'au
     * jour où quelqu'un essaierait d'en modifier un.
     *
     * @param list<array{code: string, nom: string}> $metiers les métiers à déclarer ; VIDE = ceux du repli
     *
     * @return list<array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}>
     */
    private static function blocsDeMetier(array $metiers = []): array
    {
        $blocs = [];

        if ([] === $metiers) {
            $metiers = \App\Website\Service\MetierCatalog::codes();
        }

        foreach ($metiers as $metier) {
            $blocs[] = [
                'key' => \App\Website\Service\MetierCatalog::cleDeBloc($metier['code']),
                'type' => BlockType::Rich,
                'label' => $metier['nom'],
                'help' => 'Le texte long de la page /metiers/'.str_replace('_', '-', $metier['code'])
                    .'. Le chapô, les spécificités et la liste des modules viennent du produit : ils ne se saisissent pas ici.',
                'groupe' => 'metiers',
                'initialValue' => ['html' => ''],
            ];
        }

        return $blocs;
    }

    /**
     * Un corps rédigeable par module, dérivé du catalogue (ED-11).
     *
     * ⚠ **VIDE À L'ORIGINE, ET C'EST VOULU.** `initialValue` est un corps vide : la page de module se
     * rend déjà avec le libellé et la description du catalogue. Y semer un texte de remplissage
     * ferait vingt pages qui se ressemblent — exactement ce qu'un moteur appelle du contenu mince, et
     * ce qui fait descendre les vingt d'un coup.
     *
     * @return list<array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}>
     */
    private static function blocsDeModule(): array
    {
        // ⚠ INSTANCIÉ ICI PLUTÔT QU'INJECTÉ, parce que cette classe est une DÉCLARATION : elle doit
        // rester lisible sans conteneur, en ligne de commande comme au démarrage. `CatalogueCapacites`
        // se construit sans argument — c'est ce qui le permet, et c'est vérifié par ses propres tests.
        $catalogue = new ModuleCatalog(new \App\Fonctionnalite\Service\CatalogueCapacites());

        $blocs = [];

        foreach ($catalogue->modules() as $module) {
            $blocs[] = [
                'key' => ModuleCatalog::cleDeBloc($module['code']),
                'type' => BlockType::Rich,
                'label' => $module['libelle'],
                'help' => 'Le texte long de la page /modules/'.$module['slug'].'. La description courte vient du '
                    .'catalogue et ne se saisit pas ici : elle doit rester celle du produit.',
                'groupe' => 'modules',
                'initialValue' => ['html' => ''],
            ];
        }

        return $blocs;
    }

    /**
     * @return array{key: string, type: BlockType, label: string, help: string, groupe: string, initialValue: array<int|string, mixed>}
     */
    private static function bloc(
        string $key,
        BlockType $type,
        string $label,
        string $help,
        array $initialValue,
        string $groupe = 'accueil',
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'help' => $help,
            'groupe' => $groupe,
            'initialValue' => $initialValue,
        ];
    }
}
