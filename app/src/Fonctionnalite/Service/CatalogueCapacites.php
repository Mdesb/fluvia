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
 * ⚠ AUCUNE PHRASE NE PROMET UN EFFET AUTOMATIQUE, ET C'EST UNE CONTRAINTE MESURÉE, PAS UN STYLE.
 * Le 03/09 : huit minuteurs systemd tournent sur le VPS, tous du système (man-db, certbot, apt,
 * fstrim) ; aucun ne lance `RunScheduledTasksCommand`, et aucun conteneur ne porte de cron. Le
 * catalogue de tâches planifiées existe, rien ne le déclenche. « Relance automatique »,
 * « publication programmée », « vous êtes prévenu » auraient été faux le jour de leur affichage.
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
    private static function estVerticale(CapaciteCode $code): bool
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
            CapaciteCode::Dining => new DescripteurCapacite(
                $code->value,
                'Restauration',
                "Ce qui se consomme sur place : la carte, le service, et l'addition réglée à table ou reportée sur le séjour. ⚠ Module en construction — le serveur existe, il n'a pas encore d'écran.",
                'metier',
            ),
        };

        return new DescripteurCapacite(
            $base->code,
            $base->libelle,
            $base->description,
            $base->categorie,
            self::estVerticale($code),
        );
    }
}
