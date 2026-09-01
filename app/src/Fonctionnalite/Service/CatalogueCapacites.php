<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Service;

use App\Fonctionnalite\Dto\DescripteurCapacite;
use App\Fonctionnalite\Enum\CapaciteCode;

/**
 * Registre de référence des capacités connues du socle (`GET /fonctionnalites/catalogue`). Source de
 * vérité pour la validation des codes (`existe`) et l'exposition en API. Catégories utilisées : `acces`,
 * `planning`, `finance`, `confort`, `securite`, `vente`.
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

    private static function descripteur(CapaciteCode $code): DescripteurCapacite
    {
        return match ($code) {
            CapaciteCode::ControleAcces => new DescripteurCapacite(
                $code->value,
                "Contrôle d'accès",
                'Vérification des droits aux bornes/tourniquets et gestion des supports (badges, QR).',
                'acces',
            ),
            CapaciteCode::Reservation => new DescripteurCapacite(
                $code->value,
                'Réservation de créneaux',
                'Planning et réservation de créneaux (bassins, terrains, salles, équipements).',
                'planning',
            ),
            CapaciteCode::NoShow => new DescripteurCapacite(
                $code->value,
                'Gestion des no-show',
                "Suivi et pénalisation des absences non annulées sur les réservations.",
                'planning',
            ),
            CapaciteCode::Recouvrement => new DescripteurCapacite(
                $code->value,
                'Recouvrement des impayés',
                "Suivi des rejets de prélèvement, relance et blocage d'accès en cas d'impayé.",
                'finance',
            ),
            CapaciteCode::Sepa => new DescripteurCapacite(
                $code->value,
                'Prélèvement SEPA',
                'Mandats SEPA et génération des remises pain.008 bi-régime.',
                'finance',
            ),
            CapaciteCode::PorteMonnaie => new DescripteurCapacite(
                $code->value,
                'Porte-monnaie virtuel',
                'Solde prépayé rechargeable, utilisable en caisse/boutique.',
                'finance',
            ),
            CapaciteCode::Casiers => new DescripteurCapacite(
                $code->value,
                'Casiers',
                'Attribution et caution des casiers vestiaires.',
                'confort',
            ),
            CapaciteCode::LocationMateriel => new DescripteurCapacite(
                $code->value,
                'Location de matériel',
                "Location d'équipements sur place (patins, raquettes, palmes…).",
                'confort',
            ),
            CapaciteCode::Poss => new DescripteurCapacite(
                $code->value,
                'POSS',
                "Plan d'Organisation de la Surveillance et des Secours (établissements aquatiques).",
                'securite',
            ),
            CapaciteCode::AccesNocturne => new DescripteurCapacite(
                $code->value,
                'Accès nocturne',
                'Accès autonome hors présence de personnel, avec supervision à distance.',
                'acces',
            ),
            CapaciteCode::Encadrants => new DescripteurCapacite(
                $code->value,
                'Encadrants qualifiés',
                'Suivi des qualifications des encadrants (MNS/BNSSA, coachs).',
                'securite',
            ),
            CapaciteCode::Comptabilite => new DescripteurCapacite(
                $code->value,
                'Comptabilité',
                'Journaux, écritures, lettrage et clôture tenus ici plutôt que dans un logiciel tiers.',
                'finance',
            ),
            CapaciteCode::Stock => new DescripteurCapacite(
                $code->value,
                'Suivi de stock',
                'Quantités disponibles, réassort et rupture sur les produits vendus à l\'unité.',
                'vente',
            ),
            CapaciteCode::Agenda => new DescripteurCapacite(
                $code->value,
                'Produits datés',
                'Séances, expositions et créneaux proposés à une date plutôt qu\'en permanence.',
                'planning',
            ),
            CapaciteCode::BoutiqueEnLigne => new DescripteurCapacite(
                $code->value,
                'Boutique en ligne',
                "Vente à distance de produits/abonnements via l'application ou le site client.",
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
                'Factures fournisseurs, notes de frais, trésorerie et rapprochement bancaire.',
                'metier',
            ),
            CapaciteCode::Dining => new DescripteurCapacite(
                $code->value,
                'Restauration',
                'Service a table : commande, envoi en cuisine et addition par couvert.',
                'metier',
            ),
            CapaciteCode::Lodging => new DescripteurCapacite(
                $code->value,
                'Hébergement',
                'Chambres et couchages : attribution, occupation et facturation du séjour.',
                'metier',
            ),
            CapaciteCode::Musee => new DescripteurCapacite(
                $code->value,
                'Musée',
                'Expositions, visites guidées et billetterie datée par créneau de visite.',
                'metier',
            ),
            CapaciteCode::Padel => new DescripteurCapacite(
                $code->value,
                'Padel',
                'Terrains, parties ouvertes à compléter, niveaux de joueurs et éclairage.',
                'metier',
            ),
            CapaciteCode::Patinoire => new DescripteurCapacite(
                $code->value,
                'Patinoire',
                'Séances de glace, location de patins et cautions sur le matériel prêté.',
                'metier',
            ),
            CapaciteCode::Piscine => new DescripteurCapacite(
                $code->value,
                'Piscine',
                'Bassins, fréquentation instantanée et plan d\'organisation de la surveillance.',
                'metier',
            ),
            CapaciteCode::Social => new DescripteurCapacite(
                $code->value,
                'Réseaux sociaux',
                'Publication programmée des actualités de l\'établissement sur ses comptes.',
                'metier',
            ),
            CapaciteCode::Sport => new DescripteurCapacite(
                $code->value,
                'Salle de sport',
                'Abonnements, accès en autonomie et encadrement des séances collectives.',
                'metier',
            ),
            CapaciteCode::Stay => new DescripteurCapacite(
                $code->value,
                'Séjours',
                'Formules à la semaine mêlant hébergement, activités et restauration.',
                'metier',
            ),
        };
    }
}
