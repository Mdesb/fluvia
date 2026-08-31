#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rassemble, pour un fichier de la dette, tout ce qu'il faut lire pour trancher.
 *
 * **Pourquoi.** Onze défauts de cloisonnement ont été trouvés entre le 20 et le 23/08, tous par le
 * même enchaînement manuel : ouvrir le fichier, retrouver l'opération qui l'utilise, regarder si elle
 * lit la ressource, suivre la résolution jusqu'au service appelé, vérifier si un contrôle porte sur
 * l'entité résolue. Cinq gestes, quatre à six commandes, un quart d'heure — refaits onze fois.
 *
 * Le goulot n'est pas de trouver quoi regarder : les lignes de base le disent déjà. C'est le coût de
 * chaque examen. Cette commande le ramène à une seule exécution, pour que n'importe qui puisse
 * instruire une entrée sans reconstituer la méthode.
 *
 * Elle **ne conclut rien**. Elle rassemble et met en évidence ; le jugement reste à qui lit — c'est
 * volontaire, les onze cas se sont joués sur des nuances qu'aucune heuristique n'aurait tranchées
 * (une opération `read: true` qui rend l'entité déjà cloisonnée, un contrôle au groupe et non à
 * l'établissement, une garde présente mais portant sur une autre variable).
 *
 * Usage :
 *   php bin/auditer-entree.php Vente/State/CreerVenteProcessor.php
 *   php bin/auditer-entree.php Crm/State/PmvProvider.php
 */

const RACINE_SRC = 'app/src';

function titre(string $texte): void
{
    echo "\n\033[1m" . $texte . "\033[0m\n" . str_repeat('─', mb_strlen($texte)) . "\n";
}

function lignes(string $source, string $motif, int $max = 12): array
{
    $trouvees = [];
    foreach (explode("\n", $source) as $i => $ligne) {
        if (preg_match($motif, $ligne) === 1) {
            $trouvees[] = sprintf('%4d  %s', $i + 1, trim($ligne));
            if (count($trouvees) >= $max) {
                break;
            }
        }
    }

    return $trouvees;
}

$cible = $argv[1] ?? null;
if ($cible === null) {
    fwrite(STDERR, "usage: php bin/auditer-entree.php <chemin/relatif/a/app/src.php>\n");
    exit(2);
}

$cible = preg_replace('/:\d+.*$/', '', $cible);           // tolère « fichier.php:42:$var »
$chemin = RACINE_SRC . '/' . ltrim((string) $cible, '/');

if (!is_file($chemin)) {
    fwrite(STDERR, sprintf("Introuvable : %s\n", $chemin));
    exit(2);
}

$source = (string) file_get_contents($chemin);
$classe = preg_match('/(?:final\s+)?class\s+(\w+)/', $source, $m) === 1 ? $m[1] : basename($chemin, '.php');

echo "\n\033[1mAudit de " . $cible . "\033[0m\n";

// ── 1. D'où vient l'identifiant ────────────────────────────────────────────────────────────────
titre('1. Entrées client utilisées');
$entrees = lignes($source, '/\$uriVariables\s*\[|->corps\(\)|\$corps\s*\[|\$request->/');
echo $entrees === [] ? "  (aucune — l'identifiant ne vient pas directement de la requête)\n" : implode("\n", $entrees) . "\n";

// ── 2. Ce qui est résolu ───────────────────────────────────────────────────────────────────────
titre('2. Entités résolues');
$resolutions = lignes($source, '/->(?:find|findOneBy|getReference)\s*\(/');
echo $resolutions === [] ? "  (aucune)\n" : implode("\n", $resolutions) . "\n";

preg_match_all('/getRepository\(\s*(\w+)::class/', $source, $entites);
$entites = array_values(array_unique($entites[1]));

// ── 3. L'opération qui l'utilise ───────────────────────────────────────────────────────────────
titre('3. Opération(s) API Platform');
$sortie = [];
exec(sprintf('grep -rn -B6 -A2 %s %s --include=*.php 2>/dev/null | grep -E "uriTemplate|read:|input:|security:|processor:|provider:" | head -20',
    escapeshellarg($classe . '::class'), escapeshellarg(RACINE_SRC)), $sortie);
echo $sortie === [] ? "  (aucune opération ne référence " . $classe . ")\n" : '  ' . implode("\n  ", $sortie) . "\n";
echo "\n  → `read: false` signifie qu'aucune extension de périmètre ne s'applique (D8).\n";

// ── 4. Les entités résolues sont-elles cloisonnables ? ─────────────────────────────────────────
titre('4. Couverture des entités résolues');
foreach ($entites as $entite) {
    $couverture = [];
    exec(sprintf('grep -rln %s %s --include=*Extension.php 2>/dev/null', escapeshellarg($entite . '::class'), escapeshellarg(RACINE_SRC)), $couverture);
    echo sprintf("  %-28s %s\n", $entite, $couverture === []
        ? 'AUCUNE extension ne la nomme'
        : 'couverte par ' . implode(', ', array_map(static fn (string $c): string => basename($c), $couverture)));
}
echo $entites === [] ? "  (aucune entité résolue par repository)\n" : '';
echo "\n  → une extension protège les lectures d'API Platform, jamais un `find()` direct.\n";

// ── 5. Les contrôles présents, et sur quoi ils portent ─────────────────────────────────────────
titre('5. Contrôles de périmètre présents');
$controles = lignes($source, '/codesEffectifs|etablissementActif|->getEtablissement\(\)|Verificateur|Guard|verifierAcces|isGranted|@cloisonnement-verifie/');
echo $controles === [] ? "  AUCUN\n" : implode("\n", $controles) . "\n";
echo "\n  → la question n'est pas qu'il y en ait un, mais qu'il porte sur l'entité résolue ci-dessus.\n";

// ── 6. Ce qui est appelé ensuite ───────────────────────────────────────────────────────────────
titre('6. Services appelés (le contrôle peut y être — ou pas)');
$appels = lignes($source, '/\$this->(handler|strategie|strategies|service|generateur|calculateur|projection)\w*->\w+\(/', 8);
echo $appels === [] ? "  (aucun service métier appelé directement)\n" : implode("\n", $appels) . "\n";

echo "\n\033[2mCette commande ne conclut pas : elle rassemble. Les onze cas trouvés se sont joués sur\n";
echo "des nuances (read: true, contrôle au groupe, garde portant sur une autre variable).\033[0m\n\n";
