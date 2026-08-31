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
        };
    }
}
