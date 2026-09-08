<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Vente;

/**
 * LA VENTILATION PAR TAUX DE TVA D'UNE VENTE — la mention que le ticket doit porter.
 *
 * Exigée deux fois : par NF525, et par le régime de la facture simplifiée qui fait qu'un ticket de
 * moins de 150 € HT sert de justificatif (CGI ann. II, art. 242 nonies A — date, identité du
 * fournisseur, désignation, montant HT, taux et montant de la TVA).
 *
 * ── ⚠ LE SENS DU CALCUL EST MESURÉ, PAS SUPPOSÉ ────────────────────────────────────────────────
 *
 * Les prix du comptoir sont TTC : la projection comptable d'une ligne de vente s'appelle
 * `LigneVenteProjectionDto::$montantTtcCentimes`. On **extrait** donc la base du TTC, on ne
 * l'ajoute pas au HT. Se tromper de sens sur 45,00 € à 10 % donne 49,50 au lieu de 45,00 — une
 * erreur de 4,50 € par ticket, du bon côté pour personne.
 *
 * ── ⚠ LA TVA SE DÉDUIT PAR SOUSTRACTION, ET C'EST CE QUI FAIT TOMBER LES COMPTES ───────────────
 *
 * `netAmount = round(gross × 10000 / (10000 + rate×100))`, puis `vatAmount = gross − netAmount`.
 * Arrondir les deux séparément ferait qu'ils ne se rejoignent pas toujours : sur un ticket, base +
 * TVA doit valoir le total au centime près, sans quoi le client lit trois nombres dont deux ne
 * s'additionnent pas.
 *
 * L'arrondi se fait **par groupe de taux, pas par ligne**. Ventiler ligne par ligne puis sommer
 * disperse un centime par ligne ; le regroupement d'abord donne le résultat que l'administration
 * attend, et que tout logiciel de caisse produit.
 *
 * ── ⚠ UNE LIGNE SANS TAUX N'EST PAS UNE LIGNE À 0 % ────────────────────────────────────────────
 *
 * `Produit::$tauxTva` est nullable et presque aucun produit ne le renseigne aujourd'hui. Ranger ces
 * lignes dans un groupe « 0 % » produirait une ventilation qui a l'air complète et qui est fausse —
 * exactement le défaut qu'on traque : un écran qui affirme une absence qu'il n'a jamais mesurée.
 *
 * Elles sont donc comptées **à part** (`withoutRate`), et `complete` dit non. Le rendu papier a
 * besoin de cette distinction pour écrire « ventilation incomplète » au lieu d'un tableau qui ment
 * par omission.
 *
 * ── SUR LE NOM DE CETTE CLASSE, ET DE SES CHAMPS ───────────────────────────────────────────────
 *
 * D5 : les identifiants techniques d'un fichier NEUF sont en anglais. Le garde-fou n'avait signalé
 * que `Vente` dans `VentilationTvaVente` — son lexique est partiel, la règle ne l'est pas, donc le
 * renommage est complet plutôt que minimal.
 *
 * Les clés de la charge utile suivent, et c'est un choix : elles sont NEUVES, aucun consommateur
 * n'en dépend encore, et le ticket existant restera français jusqu'au retrofit. Un demi-renommage
 * aurait laissé `baseHT` à côté de `rate` — le pire des deux.
 */
final class SaleVatBreakdown
{
    public function __construct(
        private readonly PanierCalculateur $calculator,
    ) {
    }

    /**
     * @return array{breakdown: list<array{rate: string, netAmount: string, vatAmount: string, grossAmount: string}>,
     *     withoutRate: array{lines: int, grossAmount: string}, complete: bool}
     */
    public function of(Vente $sale): array
    {
        /** @var array<int, int> $grossByRate taux en centièmes de point => TTC en centimes */
        $grossByRate = [];
        $linesWithoutRate = 0;
        $grossWithoutRate = 0;

        foreach ($sale->getLignes() as $line) {
            $gross = $this->calculator->centimes($line->getMontantLigne());
            $rate = $line->getTauxTva();

            if ($rate === null) {
                ++$linesWithoutRate;
                $grossWithoutRate += $gross;

                continue;
            }

            // Le taux sert de CLÉ, donc il se normalise : « 10.00 » et « 10.0 » désignent le même
            // taux et doivent tomber dans le même groupe. Grouper sur la chaîne brute en ferait deux
            // lignes de ventilation pour une seule réalité.
            $key = (int) round(((float) $rate) * 100);
            $grossByRate[$key] = ($grossByRate[$key] ?? 0) + $gross;
        }

        ksort($grossByRate);

        $breakdown = [];
        foreach ($grossByRate as $key => $gross) {
            $net = (int) round($gross * 10000 / (10000 + $key));
            $breakdown[] = [
                'rate' => number_format($key / 100, 2, '.', ''),
                'netAmount' => $this->calculator->decimal($net),
                'vatAmount' => $this->calculator->decimal($gross - $net),
                'grossAmount' => $this->calculator->decimal($gross),
            ];
        }

        return [
            'breakdown' => $breakdown,
            'withoutRate' => [
                'lines' => $linesWithoutRate,
                'grossAmount' => $this->calculator->decimal($grossWithoutRate),
            ],
            'complete' => $linesWithoutRate === 0,
        ];
    }
}
