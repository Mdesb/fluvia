<?php

declare(strict_types=1);

/**
 * Garde-fou n°40 — un champ cité par `UniqueEntity` doit être écrivable, sinon la règle disparaît.
 *
 * ── LE DÉFAUT, ET POURQUOI IL EST PIRE QU'UNE ERREUR ────────────────────────────────────────────
 *
 * `#[UniqueEntity(fields: ['etablissement', 'codeEAN'])]` s'évalue AVANT que les processeurs
 * n'estampillent l'entité. Si `etablissement` n'est pas exposé en écriture — parce qu'on vient
 * justement de le fermer pour boucher une faille de cloisonnement — la contrainte s'évalue sur
 * `null`. Elle cherche alors un doublon parmi les entités sans établissement, n'en trouve aucun,
 * et **laisse passer**.
 *
 * La règle métier ne tombe pas bruyamment : elle **disparaît**. Aucun test ne rougit, aucune trace
 * n'est écrite, et le doublon qu'elle interdisait entre en base. C'est exactement la classe de
 * défaut qu'un garde-fou doit attraper, parce que rien d'autre ne le fera.
 *
 * Trouvé le 01/09 par claude-F sur `ArticleStock` (RG-STOCK-02), en fermant la faille D41 sur
 * `Stock`. Le correctif tenu : un validateur dédié qui lit l'établissement là où il se trouve
 * réellement, plutôt qu'un attribut qui suppose qu'il est déjà là.
 *
 * ── POURQUOI IL NAÎT À ZÉRO, ET POURQUOI C'EST LE BON MOMENT ────────────────────────────────────
 *
 * Mesuré sur les fichiers du dépôt qui citent `UniqueEntity` : 5 champs cités par 4 entités,
 * **tous écrivables**. Il n'y a donc rien à geler, et rien à corriger — il interdit seulement le
 * retour. Un cliquet posé à zéro pendant que le compteur y est ne coûte rien à personne ; posé
 * après coup, il faut d'abord résorber.
 *
 * ── CE QU'IL NE FAIT PAS, DÉLIBÉRÉMENT ──────────────────────────────────────────────────────────
 *
 * La règle générale serait « tout champ hors groupe d'écriture cité par une contrainte qui en
 * dépend ». Elle couvrirait aussi les `Assert` — mais `Assert\Callback`, `Assert\Expression` et les
 * validateurs de classe lisent des champs sans les nommer dans un attribut, et la moitié des
 * contraintes ne dépendent pas de l'état d'un autre champ. Je ne sais pas la rendre précise
 * aujourd'hui, donc je ne l'écris pas : un contrôle qui crie au loup finit désactivé, et on perd
 * alors aussi ses vraies alertes. `UniqueEntity` se décide, lui, sans ambiguïté.
 *
 * ── LES DEUX FORMES DE GUILLEMETS ───────────────────────────────────────────────
 *
 * PHP accepte `fields: ['x']` comme `fields: ["x"]`. Ma première version ne lisait que la simple :
 * sur une entité écrite en guillemets doubles, elle ne lisait RIEN et annonçait vert. Un contrôle
 * qui ne voit pas sa cible est pire que pas de contrôle — il donne l'illusion d'être couvert. Le
 * banc l'a montré parce que sa fixture était écrite dans l'autre forme, par hasard.
 *
 * ── LEVER LE REFUS ──────────────────────────────────────────────────────────────────────────────
 *
 * Si un champ est légitimement non exposé — parce qu'il est renseigné au constructeur, donc présent
 * avant la validation — la sortie est de le déclarer dans la ligne de base, pas de contourner le
 * contrôle. Le fichier est `bin/unique-entity.ligne-de-base.json`, une entrée par champ, avec la
 * raison. Le plafond ne monte pas tout seul.
 */

$racine = dirname(__DIR__);
$source = $racine . '/app/src';
$baseChemin = $racine . '/bin/unique-entity.ligne-de-base.json';

if (!is_dir($source)) {
    echo "UniqueEntity : app/src introuvable, rien à lire.\n";
    exit(0);
}

/** Les attributs d'une propriété : les lignes `#[…]` contiguës juste au-dessus de sa déclaration. */
function attributsDe(array $lignes, int $indexDeclaration): string
{
    $bloc = [];
    for ($i = $indexDeclaration - 1; $i >= 0; --$i) {
        $ligne = trim($lignes[$i]);
        if ($ligne === '') {
            continue;
        }
        // On accepte les attributs et la suite d'un attribut multi-ligne ; tout le reste arrête.
        if (str_starts_with($ligne, '#[') || str_starts_with($ligne, ']')
            || (str_ends_with($ligne, ',') && $bloc !== []) || str_starts_with($ligne, "'")) {
            $bloc[] = $lignes[$i];
            continue;
        }
        break;
    }

    return implode("\n", array_reverse($bloc));
}

$base = is_file($baseChemin)
    ? (json_decode((string) file_get_contents($baseChemin), true) ?: [])
    : ['scelle' => ['plafond' => 0], 'entrees' => []];
$gelees = [];
foreach ($base['entrees'] ?? [] as $entree) {
    $gelees[$entree['cle'] ?? ''] = true;
}
$plafond = (int) ($base['scelle']['plafond'] ?? 0);

$fautifs = [];
$nonLus = [];
$lus = 0;
$entites = 0;

$iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
foreach ($iterateur as $fichier) {
    if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
        continue;
    }
    $texte = (string) file_get_contents($fichier->getPathname());
    if (!str_contains($texte, 'UniqueEntity')) {
        continue;
    }
    // ⚠ Seules les ENTITÉS comptent. Un validateur dédié cite les mêmes noms de champs sans porter
    //    les propriétés : le lire comme une entité produit un « propriété introuvable » qui n'est
    //    pas un défaut. C'est le premier faux positif que ce contrôle a produit, avant correction.
    if (!str_contains($texte, '#[ORM\Entity')) {
        continue;
    }
    $relatif = str_replace($racine . '/', '', $fichier->getPathname());
    $lignes = explode("\n", $texte);

    preg_match_all('/#\[UniqueEntity\((.*?)\)\]/s', $texte, $blocs);
    $champs = [];
    foreach ($blocs[1] as $arguments) {
        if (preg_match('/fields:\s*\[(.*?)\]/s', $arguments, $liste) === 1) {
            preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]/', $liste[1], $trouves);
            $champs = array_merge($champs, $trouves[1]);
        } elseif (preg_match('/^\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $arguments, $court) === 1) {
            $champs[] = $court[1]; // forme courte : #[UniqueEntity('nom')]
        }
    }

    if ($champs !== []) {
        ++$entites;
    }

    foreach (array_unique($champs) as $champ) {
        $trouvee = null;
        foreach ($lignes as $index => $ligne) {
            if (preg_match('/(?:private|protected|public)[^;=]*\$' . preg_quote($champ, '/') . '\b/', $ligne) === 1) {
                $trouvee = $index;
                break;
            }
        }

        $cle = $relatif . '::' . $champ;

        if ($trouvee === null) {
            $nonLus[] = $cle;
            continue;
        }

        ++$lus;
        $attributs = attributsDe($lignes, $trouvee);
        preg_match_all("/Groups\(\[([^\]]*)\]\)/", $attributs, $groupes);
        $ecriture = false;
        foreach ($groupes[1] as $contenu) {
            if (preg_match('/[\'"][^\'"]*write[^\'"]*[\'"]/', $contenu) === 1) {
                $ecriture = true;
                break;
            }
        }

        if (!$ecriture && !isset($gelees[$cle])) {
            $fautifs[] = $cle;
        }
    }
}

sort($fautifs);

if ($fautifs === []) {
    printf(
        "UniqueEntity : OK — %d champ(s) cité(s) par %d entité(s), aucun hors ligne de base. Dette gelée : %d, plafond %d.\n",
        $lus,
        $entites,
        count($gelees),
        $plafond
    );
    if ($nonLus !== []) {
        printf("  %d champ(s) non lu(s) faute de propriété déclarée : %s\n", count($nonLus), implode(', ', $nonLus));
    }
    exit(0);
}

echo "✗ UniqueEntity : une contrainte d'unicité porte sur un champ qui n'est pas écrivable.\n";
echo "\n";
echo "  Elle ne va pas échouer bruyamment — elle va DISPARAÎTRE. Le champ vaut `null` au moment de\n";
echo "  la validation, la contrainte ne trouve aucun doublon parmi les entités sans valeur, et elle\n";
echo "  laisse passer. Aucun test ne rougira.\n";
echo "\n";

foreach ($fautifs as $cle) {
    echo "    $cle\n";
}

echo "\n";
echo "  Deux sorties selon le cas :\n";
echo "    · le champ est estampillé après la validation (un processeur le pose) — alors l'attribut\n";
echo "      ne peut pas faire le travail : il faut un validateur dédié qui lise la valeur là où elle\n";
echo "      se trouve réellement ;\n";
echo "    · le champ est renseigné au constructeur, donc présent avant la validation — alors c'est un\n";
echo "      faux positif légitime, et il se déclare dans bin/unique-entity.ligne-de-base.json.\n";

exit(1);
