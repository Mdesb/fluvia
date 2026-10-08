<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Service;

use App\Fonctionnalite\Dto\DescripteurCapacite;
use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Enum\Metier;

/**
 * Registre de référence des capacités connues du socle (`GET /fonctionnalites/catalogue`). Source de
 * vérité pour la validation des codes (`existe`) et l'exposition en API. Catégories utilisées : `acces`,
 * `planning`, `finance`, `confort`, `securite`, `vente`.
 *
 * ⚠ CES DESCRIPTIONS SONT LA VITRINE, PAS UNE DOCUMENTATION INTERNE. Elles s'affichent telles quelles
 * sur l'écran Modules, à quelqu'un qui décide s'il achète. Elles disaient ce que le module CONTIENT
 * — « remises pain.008 bi-régime » — plutôt que ce qu'il fait gagner. Un exploitant de piscine ne
 * sait pas ce qu'est un pain.008 ; il sait ce qu'est « ne plus faire repasser ses adhérents en
 * caisse tous les mois ». Réécrites le 03/09 du côté de celui qui achète (R7 / C6).
 *
 * ⚠ AUCUNE PHRASE NE PROMET UN EFFET AUTOMATIQUE, ET LA RAISON ÉCRITE ICI ÉTAIT FAUSSE.
 *
 * J'avais écrit : « aucun conteneur ne porte de cron, rien ne déclenche le catalogue de tâches ».
 * C'est FAUX. `billetterie-preprod-scheduler-1` tourne depuis 29 heures et exécute
 * `infra/ordonnanceur.sh` — une boucle de 60 s qui appelle `platform:scheduler:run --only=` sur
 * SEPT tâches en liste blanche. J'avais cherché des NOMS (cron, systemd) au lieu de demander ce
 * qui fait arriver les choses à heure fixe ; et `TASKS.md` le disait déjà, ligne T10.
 *
 * Ce que dit la mesure corrigée, et qui suffit à justifier la prudence des textes : les sept
 * tâches autorisées sont `securite:delegations:expirer`, `autorisation:escalades:expirer`,
 * `boutique:liberer-paniers-expires`, `personnel:recalculer-fenetres-badges`,
 * `sport:abonnements:traiter-terme`, `sepa:preavis:annoncer`, `subscription:facturer-le-mois`.
 * NI la relance de recouvrement NI la publication sociale n'y sont — donc « relance automatique »
 * et « publication programmée » restent bien des promesses creuses. C'est la portée de la liste
 * blanche qui l'établit, pas une absence d'ordonnanceur.
 *
 * ⚠ ET C'EST UNE LISTE, DONC ELLE BOUGE. Vérifier avant d'écrire une promesse d'automatisme :
 * `./infra/ordonnanceur.sh --lister` dit ce qui est autorisé, et
 * `php bin/console platform:scheduler:run --status` dit ce qui a réellement tourné.
 *
 * ⚠ TROIS MODULES SONT VENDUS SANS POUVOIR SERVIR, et leur description le dit maintenant. Mesuré
 * contre `Padel` pris comme témoin (20 ressources API, 36 entités, des écrans) : `Lodging` a ZÉRO
 * entité et ZÉRO ressource API — il ne peut pas enregistrer une chambre ; `Stay` (9 entités) et
 * `Dining` (9 entités) ont un serveur mais AUCUN écran. Les trois sont activables et facturés.
 * Retirer une option de la vitrine est un arbitrage produit : il est posé à Maxime, pas pris ici.
 */
final class CatalogueCapacites
{
    /** @return list<DescripteurCapacite> */
    public function toutes(): array
    {
        return array_map(self::descripteur(...), CapaciteCode::cases());
    }

    public function existe(string $code): bool
    {
        return CapaciteCode::tryFrom($code) !== null;
    }

    public function trouve(string $code): ?DescripteurCapacite
    {
        $enum = CapaciteCode::tryFrom($code);

        return $enum === null ? null : self::descripteur($enum);
    }

    /**
     * Une capacite qui porte le nom d'un metier EST ce metier — on ne recopie pas la liste.
     *
     * `Metier` est la source de verite : cinq verticales d'activite (piscine, sport, padel,
     * patinoire, musee), chacune associee par `PresetVerticale` a un JEU de capacites. Deriver
     * plutot que recopier garantit qu'une sixieme verticale ajoutee demain sera exclue de la
     * boutique sans que personne y pense.
     */
    /**
     * Ce module peut-il rendre un service aujourd'hui ? (§8.1)
     *
     * ⚠ LISTE NOMMÉE, ET CHAQUE ENTRÉE PORTE SA MESURE. Le nombre d'entités et d'écrans n'est pas
     * dérivable à l'exécution sans parcourir tout le code source ; il faut donc le dire. Mais une
     * liste qui dit une absence **s'inverse en vieillissant** : le jour où quelqu'un construit
     * `lodging`, ces trois lignes deviennent le contraire du vrai, et le module resterait gratuit
     * pour toujours sans que personne le remarque.
     *
     * C'est pourquoi `bin/garde-fou-modules-non-servables.php` refuse la poussée dès qu'un module
     * listé ici gagne une entité ou une ressource API. **L'absence est rendue bruyante.**
     */
    private static function peutServir(CapaciteCode $code): bool
    {
        return match ($code) {
            // 0 entité, 0 ressource API, 0 écran — cinq fichiers de domaine pur, rien de persisté.
            // Il ne peut pas enregistrer une chambre.
            CapaciteCode::Lodging,
            // 2 entités, 2 ressources — et un écran depuis le 05/09/2026 (Séjours : ouvrir une note,
            // la facturer, la clôturer, la régler). ⚠ CE COMMENTAIRE DISAIT « 0 écran » : une mesure
            // d'absence ne vieillit pas imprécise, elle devient le contraire du vrai. Le module reste
            // hors vente par décision du 16/09/2026 — pas faute d'écran, mais parce qu'il est encore
            // en chantier ; la taille gelée du garde-fou a été relevée le même jour.
            CapaciteCode::Stay,
            // 2 entités, 1 ressource, 0 écran — le serveur existe, personne ne peut s'en servir.
            CapaciteCode::Dining => false,
            default => true,
        };
    }

    /**
     * Cette capacite porte-t-elle le nom d'une verticale ?
     *
     * ⚠ **PUBLIQUE DEPUIS LE 07/09**, pour que {@see \App\Fonctionnalite\Config\ActivityCapabilities}
     * la delegue au lieu de recopier les cinq noms. Le commentaire de {@see self::descripteur()}
     * dit deja pourquoi : « il se derive de l'enum `Metier`, une fois, ici ». Deux derivations, ce
     * serait deux endroits a corriger le jour d'une sixieme verticale.
     */
    public static function estVerticale(CapaciteCode $code): bool
    {
        return Metier::tryFrom($code->value) !== null;
    }

    private static function descripteur(CapaciteCode $code): DescripteurCapacite
    {
        // ⚠ LE `match` NE PORTE PAS `estVerticale`, ET C'EST DELIBERE. Le passer aux vingt-cinq
        // appels ferait vingt-cinq occasions de se tromper, et vingt-cinq endroits a corriger le
        // jour ou une sixieme verticale arrive. Il se derive de l'enum `Metier`, une fois, ici.
        $base = match ($code) {
            CapaciteCode::ControleAcces => new DescripteurCapacite(
                $code->value,
                "Contrôle d'accès",
                "Vos entrées s'ouvrent seules : le client présente son badge ou son QR, le tourniquet vérifie qu'il a le droit d'entrer, et l'entrée est enregistrée. Personne à poster devant la porte.",
                'acces',
            ),
            CapaciteCode::Reservation => new DescripteurCapacite(
                $code->value,
                'Réservation de créneaux',
                "Vos clients prennent leur ligne d'eau, leur terrain ou leur salle à l'avance et voient ce qui reste libre. Fini le planning papier et les créneaux vendus deux fois.",
                'planning',
            ),
            CapaciteCode::NoShow => new DescripteurCapacite(
                $code->value,
                'Gestion des no-show',
                "Un créneau réservé et jamais occupé est un créneau perdu. Les absences non annulées sont comptées par client, et vous décidez de la suite : rien, avertissement, ou pénalité.",
                'planning',
            ),
            CapaciteCode::Recouvrement => new DescripteurCapacite(
                $code->value,
                'Recouvrement des impayés',
                "Un prélèvement qui revient impayé apparaît dans une liste à traiter, avec le motif de la banque. Vous relancez, et vous pouvez fermer l'accès jusqu'à régularisation — au lieu de découvrir le trou à la clôture.",
                'finance',
            ),
            CapaciteCode::Sepa => new DescripteurCapacite(
                $code->value,
                'Prélèvement SEPA',
                "Vos adhérents paient par prélèvement au lieu de repasser en caisse tous les mois. Le mandat se signe une fois, les échéances se suivent ici, et le fichier à remettre à votre banque se prépare en un geste.",
                'finance',
            ),
            CapaciteCode::PorteMonnaie => new DescripteurCapacite(
                $code->value,
                'Porte-monnaie virtuel',
                "Le client charge une somme d'avance et la dépense au fil de ses visites, au guichet comme en ligne. Moins d'espèces à compter, et l'encaissement a lieu avant la prestation.",
                'finance',
            ),
            CapaciteCode::Casiers => new DescripteurCapacite(
                $code->value,
                'Casiers',
                "Attribuez un casier à l'arrivée, prenez la caution, rendez-la au retour de la clé. Vous savez à tout moment quel casier est occupé, par qui, et depuis quand.",
                'confort',
            ),
            CapaciteCode::LocationMateriel => new DescripteurCapacite(
                $code->value,
                'Location de matériel',
                "Patins, raquettes, palmes, combinaisons : ce que vous prêtez est facturé, suivi et rendu. Vous voyez ce qui est sorti, ce qui n'est pas revenu, et ce qu'il vous reste à louer.",
                'confort',
            ),
            CapaciteCode::Poss => new DescripteurCapacite(
                $code->value,
                'POSS',
                "Le POSS qu'exige la réglementation des piscines, tenu ici plutôt que dans un classeur : postes de surveillance, effectifs requis selon la fréquentation, et une version à jour à présenter en cas de contrôle.",
                'securite',
            ),
            CapaciteCode::AccesNocturne => new DescripteurCapacite(
                $code->value,
                'Accès nocturne',
                "Ouvrez en dehors des heures de présence du personnel : le client entre seul avec son badge, sous supervision à distance, et chaque entrée laisse une trace. Des heures d'ouverture en plus sans heures de travail en plus.",
                'acces',
            ),
            CapaciteCode::Encadrants => new DescripteurCapacite(
                $code->value,
                'Encadrants qualifiés',
                "Un maître-nageur dont le recyclage a expiré ne doit pas être seul au bord du bassin. Les diplômes et leurs dates de validité sont tenus ici, et se lisent avant de faire le planning.",
                'securite',
            ),
            CapaciteCode::Comptabilite => new DescripteurCapacite(
                $code->value,
                'Comptabilité',
                "Tenez vos journaux, votre lettrage et votre clôture ici, au lieu de ressaisir vos ventes dans un autre logiciel. Ce qui est encaissé au guichet est déjà comptabilisé.",
                'finance',
            ),
            CapaciteCode::Stock => new DescripteurCapacite(
                $code->value,
                'Suivi de stock',
                "Pour ce qui se vend à l'unité — boissons, bonnets, snacks : ce qu'il reste, ce qui approche du seuil de réassort, et ce qui est en rupture. Vous commandez sur des chiffres, pas au flair.",
                'vente',
            ),
            CapaciteCode::Agenda => new DescripteurCapacite(
                $code->value,
                'Produits datés',
                "Pour ce qui se vend à une date et non en permanence : une séance, une visite, une exposition. Chaque date porte ses places, son horaire et son tarif.",
                'planning',
            ),
            CapaciteCode::BoutiqueEnLigne => new DescripteurCapacite(
                $code->value,
                'Boutique en ligne',
                "Vos clients achètent leurs entrées et leurs abonnements depuis chez eux, à toute heure. Ce qui part en ligne sort du même catalogue et du même stock qu'au guichet.",
                'vente',
            ),

            // ── Les neuf capacites de MODULE ────────────────────────────────────────────────
            //
            // Categorie « metier » : ce sont des verticales entieres, pas des fonctionnalites
            // transverses. Un exploitant en prend une ou deux, jamais les neuf — c'est ce qui les
            // distingue des douze ci-dessus, dont la plupart valent pour tout le monde.
            CapaciteCode::Finance => new DescripteurCapacite(
                $code->value,
                'Finance',
                "L'argent qui sort, en face de celui qui rentre : factures fournisseurs, notes de frais, trésorerie, et rapprochement avec les lignes de votre relevé bancaire.",
                'metier',
            ),
            CapaciteCode::Lodging => new DescripteurCapacite(
                $code->value,
                'Hébergement',
                "Chambres et couchages : qui dort où, quelles nuits, et ce que le séjour coûte au final. ⚠ Module en construction — il n'a encore ni écran ni stockage : l'activer aujourd'hui n'ajoute rien à votre logiciel.",
                'metier',
            ),
            CapaciteCode::Musee => new DescripteurCapacite(
                $code->value,
                'Musée',
                "Billetterie de site culturel : expositions et visites guidées vendues par créneau horaire, chacun avec son nombre de places.",
                'metier',
            ),
            CapaciteCode::Padel => new DescripteurCapacite(
                $code->value,
                'Padel',
                "Terrains réservables à l'heure, parties ouvertes qu'un joueur seul peut venir compléter, et niveaux pour que les quatre joueurs passent un bon moment. L'éclairage des terrains se commande depuis le logiciel, et chaque allumage laisse une trace.",
                'metier',
            ),
            CapaciteCode::Patinoire => new DescripteurCapacite(
                $code->value,
                'Patinoire',
                "Séances de glace publiques, patins prêtés à la pointure, et caution sur ce que vous confiez. Une pointure manquante met le client en liste d'attente au lieu de le renvoyer.",
                'metier',
            ),
            CapaciteCode::Piscine => new DescripteurCapacite(
                $code->value,
                'Piscine',
                "Le métier complet d'un établissement aquatique : bassins et lignes d'eau, nombre de baigneurs présents à l'instant, et le plan de surveillance réglementaire.",
                'metier',
            ),
            CapaciteCode::Social => new DescripteurCapacite(
                $code->value,
                'Réseaux sociaux',
                "Préparez les actualités de l'établissement à l'avance et gardez-les au même endroit, plutôt que dans le téléphone de la personne qui s'en occupe.",
                'metier',
            ),
            CapaciteCode::Sport => new DescripteurCapacite(
                $code->value,
                'Salle de sport',
                "Abonnements au mois avec leur reconduction, entrée en autonomie par badge, et planning des cours collectifs avec l'encadrant de chacun.",
                'metier',
            ),
            CapaciteCode::Stay => new DescripteurCapacite(
                $code->value,
                'Séjours',
                "Vendez la semaine entière plutôt que la prestation : hébergement, activités et repas dans une seule formule, à un seul prix. ⚠ Module en construction — le serveur existe, il n'a pas encore d'écran.",
                'metier',
            ),
            // ⚠ AJOUTE LE 06/09 APRES UNE PANNE. La capacite existait dans l'enum depuis 204e73fe,
            //   sans descripteur ici : `descripteur()` etant un `match` sans branche par defaut,
            //   tout appel a `toutes()` levait « Unhandled match case ». La page d'accueil du site
            //   public et l'ecran « Site vitrine » rendaient 500, pendant que /metiers et /blog
            //   repondaient normalement — ils ne passent pas par le catalogue complet.
            CapaciteCode::Connecteurs => new DescripteurCapacite(
                $code->value,
                'Connecteurs',
                "Les evenements de l'etablissement arrivent la ou l'equipe travaille deja : Slack, Teams ou Discord. Un seul module pour les trois, parce que c'est le meme envoi.",
                'metier',
            ),
            CapaciteCode::Dining => new DescripteurCapacite(
                $code->value,
                'Restauration',
                "Ce qui se consomme sur place : la carte, le service, et l'addition réglée à table ou reportée sur le séjour. ⚠ Module en construction — le serveur existe, il n'a pas encore d'écran.",
                'metier',
            ),
            CapaciteCode::Affaires => new DescripteurCapacite(
                $code->value,
                'Affaires',
                "Suivez vos ventes en cours, du premier appel au devis accepté : chaque affaire a son étape, son montant prévu et ses relances à faire.",
                'metier',
            ),
            CapaciteCode::Projets => new DescripteurCapacite(
                $code->value,
                'Projets',
                "Le travail interne qui a une fin : refaire les vestiaires, préparer la saison. Chaque projet a son responsable, ses tâches et son échéance.",
                'metier',
            ),
        };

        return new DescripteurCapacite(
            $base->code,
            $base->libelle,
            $base->description,
            $base->categorie,
            self::estVerticale($code),
            self::peutServir($code),
        );
    }
}
