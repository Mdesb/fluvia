<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Fonctionnalite\Config\ActivityCapabilities;
use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Website\Config\TradeFallback;
use App\Website\Entity\Trade;

/**
 * Les métiers tels que le site public les présente (ED-12).
 *
 * **Pourquoi ces pages valent plus que les pages de modules.** Personne ne cherche « plateforme
 * modulaire » : on cherche « logiciel gestion piscine », « billetterie musée », « réservation
 * padel ». Une page par métier parle la langue de l'acheteur ; une page par module parle la nôtre.
 *
 * ⚠ **LES MODULES AFFICHÉS SONT LE PRÉRÉGLAGE RÉEL** (`PresetVerticale`), celui que
 * `Fonctionnalites::appliquerPreset()` applique à un établissement de ce métier. La page dit donc
 * « voilà ce que nous activons par défaut », ce qui est vrai — et non « voilà ce dont vous avez
 * besoin », que personne ici ne peut affirmer.
 *
 * ⚠ **ET LE PRÉRÉGLAGE SE DÉCLARE LUI-MÊME COMME UNE HYPOTHÈSE** pour padel, patinoire et musée
 * (docblock de `PresetVerticale` : « jeu plausible par analogie, à confirmer »). C'est une raison de
 * plus pour l'annoncer comme un point de départ ajustable, jamais comme une prescription. Le jour où
 * IT Cotation tranche, la page suit sans qu'on la touche.
 *
 * ⚠ **CHAQUE LIGNE DE `specificites` NOMME UNE ENTITÉ DU DÉPÔT.** C'est la seule règle qui rend ces
 * pages vérifiables : `Piscine\Entity\Poss`, `Patinoire\Entity\Affutage`, `Musee\Entity\PartenaireOTA`
 * existent, et quiconque en doute peut ouvrir le fichier. Une promesse commerciale sans entité
 * derrière serait invendable le jour de la démonstration — c'est le moment où le prospect la teste.
 */

final readonly class MetierCatalog
{
    public function __construct(
        private CatalogueCapacites $capacites,
        private TradeReference $trades,
    ) {
    }

    /**
     * @return list<array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
     *     ecran: array{etablissement: string, entrees: list<string>, note: string, colonnes: list<string>, occupations: list<string>}|null,
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }>
     */
    public function tous(): array
    {
        $lignes = $this->trades->published();

        /*
         * ⚠ LE REPLI EST TOUT-OU-RIEN, ET C'EST LE POINT LE PLUS IMPORTANT DE CETTE METHODE.
         *
         * Zero ligne -> les constantes. Au moins une ligne -> les lignes SEULES. Pas de fusion,
         * pas de `?? NOMS[$code]` champ par champ.
         *
         * Un repli PAR CHAMP rendrait une base a moitie semee indiscernable d'une base saine : un
         * metier dont la ligne existe mais dont le nom est vide sortirait avec le nom de la
         * constante, et personne ne saurait jamais que la ligne est cassee. Le tout-ou-rien n'a
         * qu'un seul etat ambigu — « quatre lignes sur cinq » — et il est ferme par le temoin
         * d'integrite.
         */
        if ([] === $lignes) {
            $metiers = [];

            foreach (Metier::cases() as $metier) {
                $metiers[] = $this->versMetier($metier);
            }

            return $metiers;
        }

        return array_map($this->depuisLaLigne(...), $lignes);
    }

    /**
     * Un metier tel qu'une LIGNE le decrit.
     *
     * La forme rendue est exactement celle de {@see self::versMetier()} : c'est le contrat avec les
     * gabarits, et aucun d'eux n'est touche par ce lot.
     *
     * @return array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
     *     ecran: array{etablissement: string, entrees: list<string>, note: string, colonnes: list<string>, occupations: list<string>}|null,
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }
     */
    private function depuisLaLigne(Trade $ligne): array
    {
        $code = $ligne->getCode();

        /*
         * ⚠ `Metier::tryFrom()` EST ICI EMPLOYE POUR CE QU'IL FAIT BIEN, et pas comme dans le
         *   defaut n°1 des faits etablis : il ne decide PAS si le metier existe — la ligne le
         *   decide — il decide seulement de quelle SOURCE viennent les modules. Un `null` n'ouvre
         *   pas une plateforme vide, il ouvre l'autre branche.
         *
         *   Un code que l'application connait garde son prereglage, inchange : c'est ce qui rend le
         *   rendu identique pour les cinq metiers d'aujourd'hui. Un metier cree en base, lui, n'a
         *   aucun prereglage, et ses modules se deduisent de ses activites.
         */
        $metier = Metier::tryFrom($code);

        if (null !== $metier) {
            $capacites = PresetVerticale::capacites($metier);
        } else {
            $activites = [];

            foreach ($ligne->getActivities() as $activite) {
                $activites[] = $activite->getActivity();
            }

            $capacites = ActivityCapabilities::modulesFor($activites);
        }

        return [
            'slug' => $ligne->getSlug(),
            'code' => $code,
            'nom' => $ligne->getName(),
            'titre' => $ligne->getSearchTitle(),
            'chapo' => $ligne->getLead(),
            'specificites' => self::SPECIFICITES[$code] ?? [],
            'ecran' => self::ECRANS[$code] ?? null,
            'modules' => $this->modulesVendables($capacites),
        ];
    }

    /**
     * Les modules affichables pour un jeu de capacites.
     *
     * ⚠ **EXTRAIT DE `versMetier()` SANS UNE LIGNE DE CHANGEMENT**, pour que les deux chemins
     * partagent exactement le meme filtre et le meme tri. Deux copies divergeraient au premier
     * ajustement, et la page ne dirait plus la meme chose selon que la base porte des lignes ou non.
     *
     * @param list<string> $capacites
     *
     * @return list<array{slug: string, libelle: string, description: string}>
     */
    private function modulesVendables(array $capacites): array
    {
        $modules = [];

        foreach ($capacites as $capacite) {
            $descripteur = $this->capacites->trouve($capacite);

            // Une capacité du préréglage absente du catalogue serait une incohérence interne ; on la
            // saute plutôt que d'afficher un code technique sur une page de vente.
            if (null === $descripteur || $descripteur->estVerticale) {
                continue;
            }

            $modules[] = [
                'slug' => ModuleCatalog::slugDe($descripteur->code),
                'libelle' => $descripteur->libelle,
                'description' => $descripteur->description,
            ];
        }

        usort($modules, static fn (array $a, array $b): int => strcmp($a['libelle'], $b['libelle']));

        return $modules;
    }

    /**
     * @return array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
     *     ecran: array{etablissement: string, entrees: list<string>, note: string, colonnes: list<string>, occupations: list<string>}|null,
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }|null
     */
    public function parSlug(string $slug): ?array
    {
        foreach ($this->tous() as $metier) {
            if ($metier['slug'] === $slug) {
                return $metier;
            }
        }

        return null;
    }

    /**
     * Ce que chaque métier voit à l'écran, dans SES mots.
     *
     * ⚠ **C'EST LA DÉMONSTRATION DE LA PROMESSE, PAS UNE ILLUSTRATION.** Le site affirme qu'un
     * créneau n'est pas la même chose partout — une réservation de terrain au padel, une séance à la
     * piscine, une visite au musée, une partie au bowling. Un seul gabarit rendu huit fois avec huit
     * tables de mots le montre, au lieu de l'écrire.
     *
     * ⚠ **ELLE EST FACULTATIVE, ET C'EST CE QUI LA REND COMPATIBLE AVEC LE RÉFÉRENTIEL.** La lecture
     * est `self::ECRANS[$code] ?? null` : un métier créé en base sans entrée ici a sa page, ses
     * modules et son texte, simplement sans écran de démonstration. Ajouter cet écran reste, lui, un
     * déploiement — c'est le seul morceau d'une page métier qui n'ait pas basculé en base, et c'est
     * assumé : ce sont des mots de vente, comme les spécificités juste en dessous.
     *
     * ⚠ **TROIS ENTRÉES N'ONT PAS DE LIGNE AUJOURD'HUI** — `bowling`, `escalade`, `parcs-de-loisirs`.
     * Elles ne rendent donc rien, et c'est sans danger : ce sont les lignes qui décident quelles
     * pages existent, jamais cette table. Elles attendent que les métiers soient créés dans
     * l'administration, avec exactement ces codes.
     *
     * ⚠ **AUCUN CHIFFRE D'AFFAIRE, AUCUN NOM DE CLIENT RÉEL.** Ce sont des établissements
     * d'illustration. Un écran de démonstration qui exhibe des données ressemblant à celles d'un
     * vrai client est une promesse qu'on ne peut pas tenir le jour où on la teste.
     *
     * `occupations` alimente les cases prises, dans l'ordre et en boucle : la géométrie du planning
     * vit dans le gabarit, les mots vivent ici.
     *
     * @var array<string, array{etablissement: string, entrees: list<string>, note: string, colonnes: list<string>, occupations: list<string>}>
     */
    private const ECRANS = [
        'piscine' => [
            'etablissement' => 'Piscine Aqualude',
            'entrees' => ['Créneaux', 'Bassins', 'Nageurs', 'Facturation', 'Paramètres'],
            'note' => '4 lignes d\'eau',
            'colonnes' => ['L1', 'L2', 'L3', 'L4'],
            'occupations' => ['Aquagym', 'Scolaires', 'Club', 'Bébés nageurs', 'Aquabike', 'Public'],
        ],
        'sport' => [
            'etablissement' => 'Studio Forme',
            'entrees' => ['Planning', 'Salles', 'Adhérents', 'Abonnements', 'Paramètres'],
            'note' => '4 salles',
            'colonnes' => ['S1', 'S2', 'S3', 'S4'],
            'occupations' => ['Cycling', 'Yoga', 'Renforcement', 'Cross training', 'Pilates', 'Boxe'],
        ],
        'padel' => [
            'etablissement' => 'Padel du Parc',
            'entrees' => ['Réservations', 'Terrains', 'Clients', 'Facturation', 'Paramètres'],
            'note' => '4 terrains',
            'colonnes' => ['T1', 'T2', 'T3', 'T4'],
            'occupations' => ['Martin', 'Cours', 'Duval', 'Ligue', 'Bernard', 'Petit'],
        ],
        'patinoire' => [
            'etablissement' => 'Patinoire Givrée',
            'entrees' => ['Séances', 'Zones', 'Patineurs', 'Location de patins', 'Paramètres'],
            'note' => '4 zones',
            'colonnes' => ['Z1', 'Z2', 'Z3', 'Z4'],
            'occupations' => ['Public', 'Scolaires', 'Hockey', 'Artistique', 'Soirée', 'Curling'],
        ],
        'musee' => [
            'etablissement' => 'Musée des Arts',
            'entrees' => ['Visites', 'Salles', 'Visiteurs', 'Billetterie', 'Paramètres'],
            'note' => '4 salles',
            'colonnes' => ['S1', 'S2', 'S3', 'S4'],
            'occupations' => ['Visite guidée', 'Scolaires', 'Groupe', 'Atelier', 'Conférence', 'Libre'],
        ],

        // ── Les trois qui n'ont pas encore de ligne ─────────────────────────────────────────────
        //
        // ⚠ Le libellé de la quatrième entrée du menu change d'un métier à l'autre, et c'est tout le
        //   propos : « Location de chaussures » au bowling, « Location de matériel » à l'escalade,
        //   « Billetterie » au parc. Un menu identique partout démentirait la phrase que la page
        //   vient d'écrire.

        'bowling' => [
            'etablissement' => 'Bowling du Stade',
            'entrees' => ['Parties', 'Pistes', 'Joueurs', 'Location de chaussures', 'Paramètres'],
            'note' => '4 pistes',
            'colonnes' => ['P1', 'P2', 'P3', 'P4'],
            'occupations' => ['Anniversaire', 'Ligue', 'Public', 'Entreprise', 'Scolaires', 'Tournoi'],
        ],
        'escalade' => [
            'etablissement' => 'Bloc & Cie',
            'entrees' => ['Séances', 'Secteurs', 'Grimpeurs', 'Location de matériel', 'Paramètres'],
            'note' => '4 secteurs',
            'colonnes' => ['S1', 'S2', 'S3', 'S4'],
            'occupations' => ['Grimpe libre', 'Cours enfants', 'Scolaires', 'Perfectionnement', 'Groupe', 'Compétition'],
        ],
        'parcs-de-loisirs' => [
            'etablissement' => 'Parc des Cimes',
            'entrees' => ['Créneaux', 'Attractions', 'Visiteurs', 'Billetterie', 'Paramètres'],
            'note' => '4 attractions',
            'colonnes' => ['A1', 'A2', 'A3', 'A4'],
            'occupations' => ['Public', 'Scolaires', 'Groupe', 'Anniversaire', 'Privatisation', 'Nocturne'],
        ],
    ];

    /** La clé du bloc de texte long d'un métier — celle que l'écran d'administration remplit. */
    public static function cleDeBloc(string $code): string
    {
        return 'metier.'.$code.'.body';
    }

    /** @return list<array{code: string, nom: string}> */
    public static function codes(): array
    {
        return array_map(
            static fn (Metier $m): array => ['code' => $m->value, 'nom' => TradeFallback::NOMS[$m->value]['nom']],
            Metier::cases(),
        );
    }


    /**
     * Ce que le produit sait faire pour ce métier, et que les autres n'ont pas.
     *
     * ⚠ CHAQUE ENTRÉE NOMME UNE ENTITÉ EXISTANTE — c'est ce qui la rend vérifiable, et ce qui
     * empêche d'écrire une promesse que la démonstration démentirait.
     *
     * @var array<string, list<array{titre: string, texte: string}>>
     */
    private const SPECIFICITES = [
        // Piscine\Entity : Poss, Bassin, LigneEau, CreneauBassin, CreneauPublic, JaugeGrandPublicCalculee,
        //                  Casier, CautionCasier, ForcageCasier, RelanceCasier, QualificationEncadrant,
        //                  AffectationEncadrant, BraceletEtanche.
        'piscine' => [
            ['titre' => 'Le POSS, pas un tableau à côté',
             'texte' => "Le plan d'organisation de la surveillance et des secours vit dans l'outil : la jauge grand public "
                 ."se calcule à partir des bassins et des lignes d'eau réservées, au lieu d'être recopiée à la main sur un "
                 ."tableau que personne ne met à jour."],
            ['titre' => 'Des créneaux par bassin et par ligne d’eau',
             'texte' => "Scolaires, clubs, public : un créneau ne bloque pas le bassin entier mais les lignes qu'il occupe. "
                 ."Le reste continue de se vendre."],
            ['titre' => 'Les casiers, cautions comprises',
             'texte' => "Attribution, caution, forçage d'un casier resté fermé, relance du client : le cycle complet, avec "
                 ."la trace de qui a ouvert quoi."],
            ['titre' => 'Les encadrants et leurs qualifications',
             'texte' => "MNS, BNSSA : la qualification est portée par la personne, et l'affectation à un créneau la vérifie. "
                 ."Un planning ne propose pas quelqu'un qui n'a pas le titre."],
        ],
        // Sport\Entity : AbonnementFitness, PauseAbonnement, Resiliation, Reengagement, EcheanceSepa,
        //                MouvementComptableSepa, ConfigAccesNocturne, StatutAccesFitness,
        //                AlertePresenceIsolee, EvenementSOS.
        'sport' => [
            ['titre' => 'L’abonnement et la porte parlent ensemble',
             'texte' => "Un impayé ferme l'accès, une régularisation le rouvre. Le statut d'accès se déduit de l'abonnement : "
                 ."personne n'a de liste à tenir à l'entrée."],
            ['titre' => 'Le cycle complet d’un abonnement',
             'texte' => "Souscription, pause, résiliation avec préavis, réengagement : chaque étape existe comme un geste "
                 ."distinct, avec son échéancier de prélèvement."],
            ['titre' => 'L’ouverture sans personnel, encadrée',
             'texte' => "Accès nocturne configuré par établissement, et la sécurité du travailleur isolé qui va avec : "
                 ."détection de présence isolée et bouton d'alerte, tracés."],
        ],
        // Padel\Entity : TerrainPadel, PlageHoraire, GrilleTarifaireTerrain, ReservationPadel, NiveauJoueur,
        //                HistoriqueNiveauJoueur, Tournoi, Poule, MatchTournoi, InscriptionTournoi,
        //                LocationMateriel, CautionMateriel, RelaisEclairageTerrain, EvenementEclairage.
        'padel' => [
            ['titre' => 'Un tarif par terrain et par plage horaire',
             'texte' => "Le créneau de 19 h ne vaut pas celui de 14 h, et le terrain couvert ne vaut pas le découvert. "
                 ."La grille le dit, la réservation l'applique."],
            ['titre' => 'Les tournois, jusqu’aux poules',
             'texte' => "Inscriptions, poules, matchs : le tournoi est dans l'outil, pas dans un tableur envoyé par courriel "
                 ."la veille."],
            ['titre' => 'Le niveau des joueurs, avec son historique',
             'texte' => "Un niveau qui évolue et qu'on peut relire — de quoi composer des parties équilibrées sans se fier "
                 ."à la mémoire de l'accueil."],
            ['titre' => 'L’éclairage piloté par la réservation',
             'texte' => "Le terrain s'allume pour le créneau réservé et s'éteint après. Chaque commande laisse une trace : "
                 ."on sait pourquoi un projecteur était allumé à 23 h."],
        ],
        // Patinoire\Entity : ParcPatins, LocationPatins, CautionLocationPatins, RetenueCaution, Affutage,
        //                    ListeAttentePointure, ZonePatinoire, SaisonEphemere.
        'patinoire' => [
            ['titre' => 'Le parc de patins, pointure par pointure',
             'texte' => "Ce qui est sorti, ce qui est rentré, ce qui manque en 38. Et une liste d'attente par pointure, "
                 ."plutôt qu'un « repassez tout à l'heure »."],
            ['titre' => 'L’affûtage suivi comme un cycle',
             'texte' => "Une paire part à l'affûtage, revient, et redevient louable. Elle n'est pas proposée entre-temps."],
            ['titre' => 'Cautions et retenues',
             'texte' => "La caution d'une location, et la retenue quand le matériel revient abîmé : le geste est prévu, "
                 ."avec son motif."],
            ['titre' => 'Une saison qui commence et qui finit',
             'texte' => "Une patinoire éphémère n'ouvre pas toute l'année : la saison est une donnée, pas une convention "
                 ."que chacun applique de mémoire."],
        ],
        // Musee\Entity : Exposition, Salle, SousQuotaSalle, PolitiqueDelestage, VisiteGuidee, Guide,
        //                QualificationLangueGuide, Audioguide, BasculeAudioguide, Gratuite,
        //                ContingentGratuite, DossierGroupeScolaire, PassAnnuel, PartenaireOTA,
        //                AllocationQuotaOTA, ReservationOTA, Reversement.
        'musee' => [
            ['titre' => 'Des jauges par salle, et un délestage prévu',
             'texte' => "La capacité ne se gère pas au niveau du musée mais de la salle, avec des sous-quotas. Quand une "
                 ."salle sature, la politique de délestage dit ce qu'on propose au visiteur."],
            ['titre' => 'Les visites guidées et la langue du guide',
             'texte' => "Un guide porte ses qualifications de langue ; une visite en anglais ne se programme pas avec "
                 ."quelqu'un qui ne la parle pas."],
            ['titre' => 'Les gratuités, comptées',
             'texte' => "Moins de 26 ans, scolaires, professionnels : chaque gratuité a son motif et son contingent. "
                 ."On sait combien on en a donné, et à quel titre."],
            ['titre' => 'Les revendeurs en ligne, jusqu’au reversement',
             'texte' => "Quotas alloués à un partenaire, réservations qui en viennent, et le reversement qui lui est dû. "
                 ."Le rapprochement ne se fait pas à la main en fin de mois."],
            ['titre' => 'Les audioguides et le pass annuel',
             'texte' => "Sortie et retour d'un audioguide, bascule d'un appareil à un autre ; et un pass annuel qui ouvre "
                 ."l'entrée sans repasser en caisse."],
        ],
    ];

    /**
     * @return array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
     *     ecran: array{etablissement: string, entrees: list<string>, note: string, colonnes: list<string>, occupations: list<string>}|null,
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }
     */
    private function versMetier(Metier $metier): array
    {
        $code = $metier->value;
        $modules = $this->modulesVendables(PresetVerticale::capacites($metier));

        return [
            'slug' => ModuleCatalog::slugDe($code),
            'code' => $code,
            'nom' => TradeFallback::NOMS[$code]['nom'],
            'titre' => TradeFallback::NOMS[$code]['titre'],
            'chapo' => TradeFallback::NOMS[$code]['chapo'],
            'specificites' => self::SPECIFICITES[$code] ?? [],
            // Absent pour un métier sans table d'écran : le gabarit n'affiche alors rien, plutôt
            // que de montrer le vocabulaire d'un autre métier.
            'ecran' => self::ECRANS[$code] ?? null,
            'modules' => $modules,
        ];
    }
}
