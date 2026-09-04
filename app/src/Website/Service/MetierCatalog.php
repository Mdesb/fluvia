<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\CatalogueCapacites;

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
    public function __construct(private CatalogueCapacites $capacites)
    {
    }

    /**
     * @return list<array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }>
     */
    public function tous(): array
    {
        $metiers = [];

        foreach (Metier::cases() as $metier) {
            $metiers[] = $this->versMetier($metier);
        }

        return $metiers;
    }

    /**
     * @return array{
     *     slug: string, code: string, nom: string, titre: string, chapo: string,
     *     specificites: list<array{titre: string, texte: string}>,
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

    /** La clé du bloc de texte long d'un métier — celle que l'écran d'administration remplit. */
    public static function cleDeBloc(string $code): string
    {
        return 'metier.'.$code.'.body';
    }

    /** @return list<array{code: string, nom: string}> */
    public static function codes(): array
    {
        return array_map(
            static fn (Metier $m): array => ['code' => $m->value, 'nom' => self::NOMS[$m->value]['nom']],
            Metier::cases(),
        );
    }

    /**
     * Ce que chaque métier appelle son quotidien.
     *
     * Le `nom` sert au menu, le `titre` à l'onglet et aux moteurs — les deux diffèrent parce qu'un
     * titre de recherche contient les mots qu'on tape, et un libellé de menu doit tenir sur une ligne.
     *
     * @var array<string, array{nom: string, titre: string, chapo: string}>
     */
    private const NOMS = [
        'piscine' => [
            'nom' => 'Piscines et centres aquatiques',
            'titre' => 'Logiciel de gestion pour piscine et centre aquatique',
            'chapo' => "Entrées, créneaux de bassin, casiers, encadrants : la journée d'une piscine tient "
                ."sur des contraintes que peu de logiciels connaissent. Fluvia les porte, du POSS à la caution "
                ."d'un casier forcé.",
        ],
        'sport' => [
            'nom' => 'Salles de sport et fitness',
            'titre' => 'Logiciel de gestion pour salle de sport et club de fitness',
            'chapo' => "Abonnements prélevés, accès contrôlé, ouverture sans personnel : une salle vit de la "
                ."régularité de ses encaissements et de la fiabilité de sa porte. Fluvia lie les deux.",
        ],
        'padel' => [
            'nom' => 'Padel et sports de raquette',
            'titre' => 'Logiciel de réservation pour club de padel',
            'chapo' => "Terrains réservés à la demi-heure, joueurs qui ne viennent pas, tournois à organiser, "
                ."éclairage à ne pas laisser allumé. Un club de padel se pilote au créneau.",
        ],
        'patinoire' => [
            'nom' => 'Patinoires',
            'titre' => 'Logiciel de gestion pour patinoire',
            'chapo' => "Un parc de patins à louer, à affûter et à rendre, des séances publiques et scolaires, "
                ."une saison qui dure quelques mois. Une patinoire ne se gère pas comme une salle ouverte à l'année.",
        ],
        'musee' => [
            'nom' => 'Musées et sites de visite',
            'titre' => 'Logiciel de billetterie pour musée et site de visite',
            'chapo' => "Billetterie horodatée, jauges par salle, visites guidées, gratuités à justifier, "
                ."revendeurs en ligne à rapprocher. Un musée compte ses entrées autrement qu'un équipement sportif.",
        ],
    ];

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
     *     modules: list<array{slug: string, libelle: string, description: string}>
     * }
     */
    private function versMetier(Metier $metier): array
    {
        $code = $metier->value;
        $modules = [];

        foreach (PresetVerticale::capacites($metier) as $capacite) {
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

        return [
            'slug' => ModuleCatalog::slugDe($code),
            'code' => $code,
            'nom' => self::NOMS[$code]['nom'],
            'titre' => self::NOMS[$code]['titre'],
            'chapo' => self::NOMS[$code]['chapo'],
            'specificites' => self::SPECIFICITES[$code] ?? [],
            'modules' => $modules,
        ];
    }
}
