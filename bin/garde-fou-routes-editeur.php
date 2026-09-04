<?php

declare(strict_types=1);

/**
 * GARDE-FOU — LE PRÉFIXE `/editor/` NE PROTÈGE RIEN, ET IL A TOUT L'AIR DU CONTRAIRE.
 *
 * Il n'existe aucune règle `access_control` sur `^/api/editor` : la protection est **entièrement**
 * portée par chaque opération, via un provider ou un processor qui appelle `EditorOnly::assertEditor()`.
 * Le préfixe est donc un nom, pas une barrière — alors qu'il se lit comme une barrière.
 *
 * Deux routes voisines suffisent à montrer le piège :
 *
 *     /editor/plans            ← PUBLIQUE : le catalogue que lit un prospect sur la vitrine (ED-5)
 *     /editor/catalog/plans    ← ÉDITEUR   : la création et la modification des formules
 *
 * Un mot d'écart, deux publics opposés. Celui qui ajoutera demain une route sous `/editor/` en
 * héritera de l'apparence de sûreté sans en hériter de la sûreté.
 *
 * ── CE QU'ON MESURE, ET CE QU'ON NE MESURE PAS ──────────────────────────────────────────────────
 *
 * On exige que chaque route `/editor/` tombe dans l'un des deux camps, EXPLICITEMENT :
 *
 *   — soit sa chaîne (provider, processor, controller) contient `assertEditor` ;
 *   — soit elle figure dans `EXCEPTIONS` ci-dessous **et** déclare `PUBLIC_ACCESS`.
 *
 * Le second camp est la vraie raison d'être de ce contrôle. Ouvrir une route sous `/editor/` reste
 * possible — le tunnel de vente en a besoin — mais devient un geste délibéré, écrit, relu. C'est le
 * silence qu'on supprime, pas la possibilité.
 *
 * ⚠ CE CONTRÔLE NE DIT PAS QUE LA GARDE EST BIEN POSÉE. Il vérifie que la classe citée contient
 * `assertEditor` quelque part — pas qu'elle l'appelle sur LE chemin qu'emprunte cette opération. Un
 * provider qui garderait sa méthode de collection et oublierait celle d'item passerait ici. C'est
 * une mesure de présence, pas de justesse, et c'est écrit pour que personne ne lui prête davantage.
 *
 * ⚠ AUCUNE RÉFLEXION, AUCUN AUTOCHARGEUR. Le contrôle lit du texte. C'est délibéré : le crochet
 * `pre-receive` extrait l'arbre poussé dans un répertoire temporaire où aucune dépendance n'est
 * installée, et un garde-fou qui exige `vendor/autoload.php` n'y refuse pas un défaut — il refuse
 * TOUS les push, les siens compris (constaté le 29/08 sur le contrôle des filtres déclarés).
 */

$racine = dirname(__DIR__) . '/app/src';

/**
 * Les routes `/editor/` volontairement publiques, chacune avec sa raison.
 *
 * ⚠ UNE ENTRÉE QUI NE CORRESPOND PLUS À AUCUNE ROUTE FAIT ÉCHOUER LE CONTRÔLE. Une exception
 * périmée ne se voit pas : elle ne provoque rien, elle élargit. Le jour où `/editor/carts` est
 * renommée, cette liste continuerait d'excuser un chemin qui n'existe plus — et excuserait aussi,
 * par accident, celui qui reprendrait le nom.
 */
const EXCEPTIONS = [
    '/editor/plans' => 'ED-5 — le catalogue des formules, lu sans compte par le site vitrine.',
    '/editor/plan-options' => 'ED-5 — les options en vente, lues sans compte par le site vitrine.',
    '/editor/carts' => 'ED-5 — première étape du tunnel : un prospect compose son panier avant d’avoir un compte.',
    '/editor/trial-requests' => 'ED-5 — deuxième étape : le prospect demande son essai, un courriel de confirmation part. Appartenance prouvée par l’adresse saisie, pas par un périmètre — voir RequestTrialProcessor.',
    '/editor/trial-confirmations' => 'ED-5 — troisième étape : le lien du courriel ouvre l’essai. Le jeton de 32 octets est la preuve ; il n’existe pas de compte à ce stade.',
];

// ── Lecture ─────────────────────────────────────────────────────────────────────────────────────

if (!is_dir($racine)) {
    fwrite(STDERR, "✗ Routes éditeur : introuvable — $racine\n");
    exit(2);
}

$fichiers = [];
$parcours = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
foreach ($parcours as $entree) {
    if ($entree->isFile() && $entree->getExtension() === 'php') {
        $fichiers[$entree->getPathname()] = (string) file_get_contents($entree->getPathname());
    }
}

if ($fichiers === []) {
    fwrite(STDERR, "✗ Routes éditeur : aucun fichier PHP lu — mesure aveugle.\n");
    exit(2);
}

/** Les classes qui appellent la garde, par nom court. */
$gardiennes = [];
foreach ($fichiers as $chemin => $texte) {
    if (str_contains($texte, 'assertEditor')) {
        $gardiennes[basename($chemin, '.php')] = true;
    }
}

if ($gardiennes === []) {
    fwrite(STDERR, "✗ Routes éditeur : aucune classe n'appelle assertEditor — mesure aveugle.\n");
    exit(2);
}

/**
 * Délimite l'opération autour de la position donnée : on remonte à la parenthèse ouvrante du
 * `new Get…(` et on redescend à sa fermeture, en comptant les niveaux. Découper sur la virgule
 * mêlerait les opérations voisines d'une même ressource, et l'on attribuerait à l'une la garde de
 * l'autre — l'erreur exacte que ce contrôle est censé empêcher.
 */
$operation = static function (string $texte, int $position): string {
    $debut = strrpos(substr($texte, 0, $position), '(');
    if ($debut === false) {
        return '';
    }
    $profondeur = 0;
    $longueur = strlen($texte);
    for ($i = $debut; $i < $longueur; $i++) {
        if ($texte[$i] === '(') {
            $profondeur++;
        } elseif ($texte[$i] === ')') {
            $profondeur--;
            if ($profondeur === 0) {
                return substr($texte, $debut, $i - $debut + 1);
            }
        }
    }

    return substr($texte, $debut);
};

$routes = [];
foreach ($fichiers as $chemin => $texte) {
    if (!str_contains($texte, '/editor/')) {
        continue;
    }
    if (!preg_match_all("/uriTemplate:\s*'(\/editor\/[^']*)'/", $texte, $trouvees, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($trouvees[1] as $trouvee) {
        $bloc = $operation($texte, (int) $trouvee[1]);
        $chaine = [];
        foreach (['provider', 'processor', 'controller'] as $role) {
            if (preg_match('/' . $role . ':\s*([A-Za-z0-9_\\\\]+)::class/', $bloc, $m) === 1) {
                $parts = explode('\\', $m[1]);
                $chaine[] = end($parts);
            }
        }
        // Les deux styles de guillemets : `security: "is_granted('PUBLIC_ACCESS')"` s'écrit en
        // guillemets doubles parce que l'expression en contient des simples. Un motif qui n'en lit
        // qu'un des deux conclut « aucune sécurité » là où elle est déclarée — c'est l'erreur qui a
        // fait naître ce contrôle.
        $securite = preg_match('/security:\s*([\'"])(.*?)\1/s', $bloc, $m) === 1 ? $m[2] : '';

        $routes[] = [
            'route' => $trouvee[0],
            'fichier' => str_replace($racine . '/', '', $chemin),
            'chaine' => $chaine,
            'publique' => str_contains($securite, 'PUBLIC_ACCESS'),
        ];
    }
}

if ($routes === []) {
    fwrite(STDERR, "✗ Routes éditeur : aucune route /editor/ trouvée — le motif ne lit plus rien.\n");
    exit(2);
}

// ── Jugement ────────────────────────────────────────────────────────────────────────────────────

$fautes = [];
$excusees = [];

foreach ($routes as $r) {
    $gardee = false;
    foreach ($r['chaine'] as $classe) {
        if (isset($gardiennes[$classe])) {
            $gardee = true;
            break;
        }
    }
    if ($gardee) {
        continue;
    }

    if (!array_key_exists($r['route'], EXCEPTIONS)) {
        $fautes[] = sprintf(
            "  %s (%s)\n    chaîne : %s\n    → aucune classe de cette chaîne n'appelle assertEditor, et la route n'est pas\n      déclarée publique dans EXCEPTIONS. Le préfixe /editor/ ne garde rien par lui-même.",
            $r['route'],
            $r['fichier'],
            $r['chaine'] === [] ? '(aucun provider/processor/controller)' : implode(', ', $r['chaine']),
        );
        continue;
    }

    // Excusée — mais elle doit assumer sa publicité, sinon l'exception couvrirait un oubli.
    if (!$r['publique']) {
        $fautes[] = sprintf(
            "  %s (%s)\n    → listée dans EXCEPTIONS comme publique, mais ne déclare pas PUBLIC_ACCESS.\n      Une exception doit décrire ce que le code fait, pas ce qu'on croyait qu'il faisait.",
            $r['route'],
            $r['fichier'],
        );
        continue;
    }
    $excusees[$r['route']] = true;
}

// Une exception périmée élargit en silence : elle n'échoue jamais, elle excuse un chemin absent.
foreach (EXCEPTIONS as $route => $raison) {
    if (!isset($excusees[$route])) {
        $fautes[] = sprintf(
            "  %s\n    → inscrite dans EXCEPTIONS mais aucune route publique de ce nom n'existe.\n      Exception périmée : retirez-la, sinon elle excusera le prochain à reprendre ce chemin.",
            $route,
        );
    }
}

if ($fautes !== []) {
    fwrite(STDERR, "✗ Routes éditeur — une route sous /editor/ doit être gardée ou publique, jamais implicite :\n\n");
    fwrite(STDERR, implode("\n\n", $fautes) . "\n");
    exit(1);
}

printf(
    "%d route(s) /editor/ : %d gardée(s) par assertEditor, %d publique(s) assumée(s).\n",
    count($routes),
    count($routes) - count($excusees),
    count($excusees),
);
exit(0);
