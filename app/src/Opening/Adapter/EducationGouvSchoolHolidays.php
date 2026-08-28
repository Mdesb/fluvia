<?php

declare(strict_types=1);

namespace App\Opening\Adapter;

use App\Opening\Enum\SchoolZone;
use App\Opening\Port\SchoolHolidaysInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LE CALENDRIER SCOLAIRE OFFICIEL — `data.education.gouv.fr`, jeu « fr-en-calendrier-scolaire ».
 *
 * ── SANS CLÉ, SANS CONTRAT, SANS FACTURE ────────────────────────────────────────────────────────
 *
 * Même raisonnement que pour l'Annuaire des Entreprises : une dépendance qu'on ne peut pas
 * provisionner soi-même est une dépendance qui tombe un dimanche. Ici l'open data de l'État suffit.
 *
 * ── L'APPEL PART DU SERVEUR, JAMAIS DU NAVIGATEUR ───────────────────────────────────────────────
 *
 * Le jour où cette API tombe, un seul endroit à débrancher — et le poste de l'exploitant n'a rien à
 * dire à un tiers.
 *
 * ── LE CACHE N'EST PAS UNE OPTIMISATION, C'EST LA NATURE DE LA DONNÉE ───────────────────────────
 *
 * Un calendrier scolaire change UNE FOIS PAR AN, par arrêté. L'interroger à chaque affichage
 * d'agenda serait demander mille fois la même réponse à un service public gratuit. Trente jours de
 * cache, et une clé qui porte la zone et l'intervalle.
 *
 * ── CE QUE FAIT CETTE CLASSE QUAND LE MINISTÈRE NE RÉPOND PAS ───────────────────────────────────
 *
 * Elle rend `available: false` et le DIT — jamais une exception qui remonterait en 500. Afficher un
 * agenda est le geste principal ; l'indisponibilité d'un fond de calendrier ne doit pas l'empêcher.
 * Et l'écran, lui, saura écrire « calendrier scolaire indisponible » au lieu de « pas de vacances ».
 *
 * ── LA DÉDUPLICATION N'EST PAS UN DÉTAIL ────────────────────────────────────────────────────────
 *
 * Le jeu de données publie UNE LIGNE PAR ACADÉMIE : les vacances de la Toussaint de la zone B
 * arrivent en huit exemplaires identiques (Amiens, Lille, Nancy-Metz, Nice…). Les afficher telles
 * quelles empilerait huit bandes superposées sur le calendrier. On regroupe donc par (libellé,
 * début, fin) — vérifié sur la réponse réelle, pas supposé.
 */
final readonly class EducationGouvSchoolHolidays implements SchoolHolidaysInterface
{
    private const BASE = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records';

    /** Un fond de calendrier ne doit jamais faire attendre l'agenda. */
    private const DELAI_SECONDES = 6;

    /** Un arrêté par an : trente jours de cache sont conservateurs. */
    private const CACHE_SECONDES = 2592000;

    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{available: bool, periods: list<array{label: string, start: string, end: string}>, reason?: string}
     */
    public function periods(SchoolZone $zone, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $cle = sprintf('opening.school_holidays.%s.%s.%s', $zone->value, $from->format('Ymd'), $to->format('Ymd'));
        $item = $this->cache->getItem($cle);
        if ($item->isHit()) {
            /** @var array{available: bool, periods: list<array{label: string, start: string, end: string}>} $garde */
            $garde = $item->get();

            return $garde;
        }

        try {
            $reponse = $this->http->request('GET', self::BASE, [
                'query' => [
                    // `where` en langage ODSQL : on borne sur la zone ET sur l'intervalle, pour ne
                    // pas rapatrier dix ans de calendrier à chaque affichage.
                    'where' => sprintf(
                        'zones="%s" and start_date <= date\'%s\' and end_date >= date\'%s\'',
                        $zone->officialLabel(),
                        $to->format('Y-m-d'),
                        $from->format('Y-m-d'),
                    ),
                    'limit' => 100,
                    'order_by' => 'start_date',
                ],
                'timeout' => self::DELAI_SECONDES,
            ]);
            $charge = $reponse->toArray(false);
        } catch (ExceptionInterface|\JsonException) {
            // On ne met PAS l'échec en cache : la panne d'un tiers ne doit pas durer trente jours
            // chez nous. C'est la différence entre mettre en cache une réponse et mettre en cache
            // une absence de réponse.
            return [
                'available' => false,
                'periods' => [],
                'reason' => 'Le calendrier scolaire officiel n’a pas répondu.',
            ];
        }

        $resultats = $charge['results'] ?? null;
        if (!\is_array($resultats)) {
            return [
                'available' => false,
                'periods' => [],
                'reason' => 'Réponse inattendue du calendrier scolaire officiel.',
            ];
        }

        $periodes = [];
        foreach ($resultats as $ligne) {
            if (!\is_array($ligne)) {
                continue;
            }
            $debut = $this->jour($ligne['start_date'] ?? null);
            $fin = $this->jour($ligne['end_date'] ?? null);
            $libelle = trim((string) ($ligne['description'] ?? ''));
            if ($debut === null || $fin === null || $libelle === '') {
                continue;
            }
            $periodes[] = ['label' => $libelle, 'start' => $debut, 'end' => $fin];
        }

        $resultat = ['available' => true, 'periods' => $this->fusionner($periodes)];

        $item->set($resultat)->expiresAfter(self::CACHE_SECONDES);
        $this->cache->save($item);

        return $resultat;
    }

    /**
     * FUSIONNE LES PÉRIODES DE MÊME LIBELLÉ QUI SE CHEVAUCHENT.
     *
     * La source publie UNE LIGNE PAR ACADÉMIE. Pour les petites vacances, les dates coïncident et
     * un simple regroupement suffisait. Pour l'ÉTÉ, non : les académies ne rentrent pas toutes le
     * même jour, et « Vacances d'Été » arrivait deux fois — « 04/07 → 01/09 » et « 04/07 → 31/08 ».
     * À l'écran, deux lignes pour une seule période.
     *
     * On garde donc le début le plus tôt et la fin la plus tardive : la période pendant laquelle il
     * y a des enfants en vacances quelque part dans la zone, ce qu'un exploitant regarde.
     *
     * ⚠ La fusion exige un CHEVAUCHEMENT, jamais le seul libellé : « Vacances de Noël » revient
     * chaque année, et deux périodes homonymes séparées de douze mois doivent rester deux lignes.
     *
     * @param list<array{label: string, start: string, end: string}> $periodes
     *
     * @return list<array{label: string, start: string, end: string}>
     */
    private function fusionner(array $periodes): array
    {
        usort($periodes, static fn (array $a, array $b): int => [$a['label'], $a['start']] <=> [$b['label'], $b['start']]);

        $fusionnees = [];
        foreach ($periodes as $periode) {
            $derniere = $fusionnees === [] ? null : $fusionnees[array_key_last($fusionnees)];
            // `+1 day` : deux périodes qui se touchent bout à bout sont la même période coupée par
            // une frontière d'académie, pas deux vacances consécutives.
            $contigue = $derniere !== null
                && $derniere['label'] === $periode['label']
                && $periode['start'] <= date('Y-m-d', strtotime($derniere['end'] . ' +1 day'));

            if ($contigue) {
                $fusionnees[array_key_last($fusionnees)]['end'] = max($derniere['end'], $periode['end']);

                continue;
            }
            $fusionnees[] = $periode;
        }

        usort($fusionnees, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values($fusionnees);
    }

    /**
     * La source horodate en UTC (`2026-10-16T22:00:00+00:00` pour un début le 17 au matin, heure de
     * Paris). On repasse donc en heure locale AVANT de garder la date, sinon les vacances
     * commenceraient la veille au soir — un décalage d'un jour, invisible et faux.
     */
    private function jour(mixed $brut): ?string
    {
        if (!\is_string($brut) || $brut === '') {
            return null;
        }
        try {
            $instant = new \DateTimeImmutable($brut);
        } catch (\Exception) {
            return null;
        }

        return $instant->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }
}
