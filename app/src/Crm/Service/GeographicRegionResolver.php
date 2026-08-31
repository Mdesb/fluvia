<?php

declare(strict_types=1);

namespace App\Crm\Service;

/**
 * LA RÉGION GÉOGRAPHIQUE D'UN CLIENT, DÉDUITE DE SON CODE POSTAL.
 *
 * Demandée par Maxime : « la région par code géographique déduite automatiquement doit être faite,
 * ça aura un intérêt statistique à un certain moment ».
 *
 * ── ⚠ CE N'EST PAS LA MÊME CHOSE QUE `Organisation\Entity\Region`, ET LES CONFONDRE COÛTERAIT CHER ─
 *
 *     Organisation\Region   un REGROUPEMENT D'ÉTABLISSEMENTS pour une direction régionale.
 *                           Arbitraire, propre à chaque exploitant : l'un met tout le Grand Est,
 *                           l'autre a un directeur pour Nord + Grand Est. C'est de la configuration.
 *
 *     cette classe          une donnée GÉOGRAPHIQUE sur une PERSONNE, déduite de son adresse.
 *                           Elle ne se configure pas : elle se calcule, et elle est la même pour
 *                           tout le monde.
 *
 * L'une ne remplace pas l'autre et ne s'en déduit pas. Un client de Lille peut appartenir à la
 * direction régionale « Grand Est » d'un exploitant et à la région administrative Hauts-de-France.
 *
 * ── POURQUOI UNE TABLE ET NON UN APPEL À UN SERVICE ────────────────────────────────────────────
 *
 * Le découpage départements → régions a changé une fois en 2016 et ne bouge pas d'une année sur
 * l'autre. Un appel réseau introduirait une dépendance, une latence et un mode dégradé, pour une
 * donnée qui tient en cinquante lignes et qu'on peut relire.
 *
 * ── CE QUE CETTE CLASSE REFUSE DE DEVINER ─────────────────────────────────────────────────────
 *
 * Un code inconnu, étranger, ou absent rend `null`. On ne rattache pas « au plus proche » : une
 * statistique fausse est plus coûteuse qu'une statistique incomplète, parce qu'elle ne se voit pas.
 *
 * ⚠ `null` a donc UN SEUL SENS : « on ne sait pas rattacher ». Il couvre l'adresse absente, le code
 * étranger et le code invalide — trois causes, une seule conclusion, et c'est la même pour qui
 * compte. Ce qu'il ne veut jamais dire, c'est « aucune région » : la France entière en a une.
 */
final class GeographicRegionResolver
{
    /**
     * Département → région administrative (découpage de 2016).
     *
     * ⚠ LA CORSE EST TRAITÉE PAR SON DÉPARTEMENT « 20 » ET NON PAR 2A/2B. Le code postal ne permet
     * pas de trancher entre Corse-du-Sud et Haute-Corse de façon fiable — 20000 est à Ajaccio,
     * 20200 à Bastia, mais le découpage postal ne suit pas exactement la limite départementale. Les
     * deux appartiennent de toute façon à la même région : la distinction ne changerait rien ici,
     * et la deviner introduirait une erreur pour rien.
     *
     * @var array<string, string>
     */
    private const REGIONS = [
        // Auvergne-Rhône-Alpes
        '01' => 'Auvergne-Rhône-Alpes', '03' => 'Auvergne-Rhône-Alpes', '07' => 'Auvergne-Rhône-Alpes',
        '15' => 'Auvergne-Rhône-Alpes', '26' => 'Auvergne-Rhône-Alpes', '38' => 'Auvergne-Rhône-Alpes',
        '42' => 'Auvergne-Rhône-Alpes', '43' => 'Auvergne-Rhône-Alpes', '63' => 'Auvergne-Rhône-Alpes',
        '69' => 'Auvergne-Rhône-Alpes', '73' => 'Auvergne-Rhône-Alpes', '74' => 'Auvergne-Rhône-Alpes',
        // Bourgogne-Franche-Comté
        '21' => 'Bourgogne-Franche-Comté', '25' => 'Bourgogne-Franche-Comté', '39' => 'Bourgogne-Franche-Comté',
        '58' => 'Bourgogne-Franche-Comté', '70' => 'Bourgogne-Franche-Comté', '71' => 'Bourgogne-Franche-Comté',
        '89' => 'Bourgogne-Franche-Comté', '90' => 'Bourgogne-Franche-Comté',
        // Bretagne
        '22' => 'Bretagne', '29' => 'Bretagne', '35' => 'Bretagne', '56' => 'Bretagne',
        // Centre-Val de Loire
        '18' => 'Centre-Val de Loire', '28' => 'Centre-Val de Loire', '36' => 'Centre-Val de Loire',
        '37' => 'Centre-Val de Loire', '41' => 'Centre-Val de Loire', '45' => 'Centre-Val de Loire',
        // Corse
        '20' => 'Corse',
        // Grand Est
        '08' => 'Grand Est', '10' => 'Grand Est', '51' => 'Grand Est', '52' => 'Grand Est',
        '54' => 'Grand Est', '55' => 'Grand Est', '57' => 'Grand Est', '67' => 'Grand Est',
        '68' => 'Grand Est', '88' => 'Grand Est',
        // Hauts-de-France
        '02' => 'Hauts-de-France', '59' => 'Hauts-de-France', '60' => 'Hauts-de-France',
        '62' => 'Hauts-de-France', '80' => 'Hauts-de-France',
        // Île-de-France
        '75' => 'Île-de-France', '77' => 'Île-de-France', '78' => 'Île-de-France',
        '91' => 'Île-de-France', '92' => 'Île-de-France', '93' => 'Île-de-France',
        '94' => 'Île-de-France', '95' => 'Île-de-France',
        // Normandie
        '14' => 'Normandie', '27' => 'Normandie', '50' => 'Normandie', '61' => 'Normandie',
        '76' => 'Normandie',
        // Nouvelle-Aquitaine
        '16' => 'Nouvelle-Aquitaine', '17' => 'Nouvelle-Aquitaine', '19' => 'Nouvelle-Aquitaine',
        '23' => 'Nouvelle-Aquitaine', '24' => 'Nouvelle-Aquitaine', '33' => 'Nouvelle-Aquitaine',
        '40' => 'Nouvelle-Aquitaine', '47' => 'Nouvelle-Aquitaine', '64' => 'Nouvelle-Aquitaine',
        '79' => 'Nouvelle-Aquitaine', '86' => 'Nouvelle-Aquitaine', '87' => 'Nouvelle-Aquitaine',
        // Occitanie
        '09' => 'Occitanie', '11' => 'Occitanie', '12' => 'Occitanie', '30' => 'Occitanie',
        '31' => 'Occitanie', '32' => 'Occitanie', '34' => 'Occitanie', '46' => 'Occitanie',
        '48' => 'Occitanie', '65' => 'Occitanie', '66' => 'Occitanie', '81' => 'Occitanie',
        '82' => 'Occitanie',
        // Pays de la Loire
        '44' => 'Pays de la Loire', '49' => 'Pays de la Loire', '53' => 'Pays de la Loire',
        '72' => 'Pays de la Loire', '85' => 'Pays de la Loire',
        // Provence-Alpes-Côte d'Azur
        '04' => "Provence-Alpes-Côte d'Azur", '05' => "Provence-Alpes-Côte d'Azur",
        '06' => "Provence-Alpes-Côte d'Azur", '13' => "Provence-Alpes-Côte d'Azur",
        '83' => "Provence-Alpes-Côte d'Azur", '84' => "Provence-Alpes-Côte d'Azur",
    ];

    /**
     * Outre-mer : trois chiffres, parce que « 97 » seul ne distingue pas la Guadeloupe de Mayotte.
     *
     * ⚠ 975, 977, 978 et les collectivités du Pacifique n'y sont PAS : ce sont des collectivités,
     * pas des régions. Elles rendent `null` — « on ne sait pas rattacher » est exact pour elles,
     * et les ranger de force dans une région voisine serait une invention.
     *
     * @var array<string, string>
     */
    private const OUTRE_MER = [
        '971' => 'Guadeloupe',
        '972' => 'Martinique',
        '973' => 'Guyane',
        '974' => 'La Réunion',
        '976' => 'Mayotte',
    ];

    /**
     * ⚠ DEUX COLLECTIVITÉS PARTAGENT LE PRÉFIXE « 971 » SANS APPARTENIR À LA GUADELOUPE.
     *
     * Saint-Barthélemy (97133) et Saint-Martin (97150) sont des collectivités d'outre-mer distinctes
     * depuis 2007. Les trois premiers chiffres les rangeraient sous Guadeloupe — une statistique qui
     * aurait l'air juste, et que personne ne viendrait vérifier.
     *
     * Trouvé par le test, pas à la relecture : j'avais écrit la règle des trois chiffres en croyant
     * qu'elle suffisait, et elle suffit pour tout le reste de l'outre-mer.
     *
     * @var list<string>
     */
    private const COLLECTIVITES_HORS_REGION = ['97133', '97150'];

    /**
     * La région d'une adresse, ou `null` si on ne sait pas la rattacher.
     *
     * @param array<string, mixed>|null $adresse la structure {rue, complement, cp, ville, pays}
     */
    public function pourAdresse(?array $adresse): ?string
    {
        if ($adresse === null) {
            return null;
        }

        // ⚠ UN PAYS ÉTRANGER REND `null` MÊME SI LE CODE RESSEMBLE À UN CODE FRANÇAIS. « 1000 » est
        // Bruxelles ; le lire comme le département 10 rattacherait un Belge au Grand Est. Le champ
        // `pays` est facultatif : absent, on suppose la France, ce qui est le cas de la quasi-totalité
        // des adresses saisies ici — mais renseigné et différent, il fait foi.
        $pays = \is_string($adresse['pays'] ?? null) ? trim($adresse['pays']) : '';
        if ($pays !== '' && !$this->estLaFrance($pays)) {
            return null;
        }

        return $this->pourCodePostal(\is_string($adresse['cp'] ?? null) ? $adresse['cp'] : null);
    }

    /** La région d'un code postal français, ou `null`. */
    public function pourCodePostal(?string $codePostal): ?string
    {
        if ($codePostal === null) {
            return null;
        }

        // Les espaces sont fréquents à la saisie ; le reste doit être cinq chiffres, sans quoi on
        // ne devine pas. Un code à quatre chiffres est belge ou luxembourgeois, pas un code français
        // amputé de son zéro — et le compléter serait exactement l'invention qu'on refuse.
        $brut = preg_replace('/\s+/', '', $codePostal) ?? '';
        if (!preg_match('/^\d{5}$/', $brut)) {
            return null;
        }

        if (str_starts_with($brut, '97') || str_starts_with($brut, '98')) {
            if (\in_array($brut, self::COLLECTIVITES_HORS_REGION, true)) {
                return null;
            }

            return self::OUTRE_MER[substr($brut, 0, 3)] ?? null;
        }

        return self::REGIONS[substr($brut, 0, 2)] ?? null;
    }

    /**
     * ⚠ COMPARAISON LARGE, ET DÉLIBÉRÉMENT. Le champ est libre : on y trouve « France »,
     * « FRANCE », « FR », « france ». Refuser tout ce qui n'est pas exactement « France »
     * rejetterait des adresses françaises et ferait perdre la région de vrais clients — une perte
     * silencieuse, puisque `null` ne se distingue pas d'un code inconnu.
     */
    private function estLaFrance(string $pays): bool
    {
        $normalise = mb_strtolower(trim($pays));

        return \in_array($normalise, ['france', 'fr', 'fra'], true);
    }
}
