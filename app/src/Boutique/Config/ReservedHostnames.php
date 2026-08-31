<?php

declare(strict_types=1);

namespace App\Boutique\Config;

/**
 * LES NOMS D'HÔTES QUE PERSONNE NE PEUT PRENDRE (D106).
 *
 * Le back-office, l'API et les boutiques clientes partagent le **même espace de noms**. Un client
 * nommé « pro », « api » ou « www » entrerait donc en collision avec un hôte technique, et le
 * symptôme serait une boutique qui sert le back-office — ou l'inverse.
 *
 * ⚠ **Ce genre de collision ne se découvre pas en revue de code : elle se découvre le jour où un
 * commercial saisit le nom d'un nouveau client.** La liste vit donc là où le nom est validé, pas
 * dans une consigne — une consigne ne protège personne, elle annonce le contrôle qui manque.
 *
 * ── DEUX APPELANTS, UN SEUL CALCUL ──────────────────────────────────────────────────────────────
 *
 * Il faut refuser le nom aux DEUX endroits où il peut naître, et ce ne sont pas les mêmes chemins :
 *
 *   1. `Vitrine::validerNomDUrl()` — l'exploitant écrit son propre nom (`vitrine:write`).
 *   2. `VitrineResolver::fabriquerSlug()` — le nom est FABRIQUÉ depuis le nom de l'établissement.
 *
 * ⚠ Le second chemin n'est pas couvert par le premier, et c'est contre-intuitif : l'estampilleur
 * (`EstablishmentStampProcessor`) pose le nom dans un *processor*, donc APRÈS la validation. Un
 * établissement nommé « Pro » aurait donc traversé un `Assert` sans jamais être vu par lui. Ne
 * poser le contrôle que sur l'écriture aurait laissé la moitié du trou ouvert, et c'est la moitié
 * que personne ne teste.
 */
final class ReservedHostnames
{
    /**
     * @var list<string> la liste de D106, verbatim
     */
    public const NAMES = [
        'pro',
        'api',
        'www',
        'app',
        'admin',
        'mail',
        'static',
        'assets',
        'cdn',
        'status',
        'dev',
        'test',
    ];

    /**
     * ⚠ ÉGALITÉ EXACTE, JAMAIS INCLUSION.
     *
     * `str_contains()` aurait refusé `api-boutique`, `mail-du-parc` ou `assets-nautiques` — des noms
     * parfaitement légitimes, dont le refus serait incompréhensible pour l'exploitant qui les
     * saisit. Un contrôle trop large ne se voit pas comme un défaut : il se voit comme un produit
     * capricieux.
     */
    public static function isReserved(?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return \in_array(mb_strtolower(trim($candidate)), self::NAMES, true);
    }
}
