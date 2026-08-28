<?php

declare(strict_types=1);

/**
 * Garde-fou n°19 — une propriété que PHP autorise à `null` sur une colonne qui l'interdit.
 *
 * ── LE DÉFAUT QU'IL PRÉVIENT ────────────────────────────────────────────────────────────────────
 *
 * Rencontré trois fois de suite en écrivant UN SEUL test, le 28/08 :
 *
 *     #[ORM\Column(length: 16, enumType: TypeContrat::class)]   // -> NOT NULL en base
 *     #[Assert\NotNull]
 *     private ?TypeContrat $typeContrat = null;
 *
 * L'entité fraîchement construite est valide pour PHP, valide pour Doctrine, et **refusée par
 * MariaDB au `flush()`**. Le message ne nomme qu'une colonne à la fois : on découvre les manques un
 * par un, en relançant.
 *
 * ── CE QUE CE N'EST PAS ─────────────────────────────────────────────────────────────────────────
 *
 * **Ce n'est pas une dérive de schéma**, et c'est ce qui l'a fait chercher au mauvais endroit. Le
 * mapping déclare bien la colonne obligatoire — une `#[ORM\Column]` sans `nullable: true` EST
 * `NOT NULL` — et la contrainte de validation est souvent là. Un garde-fou d'écart mapping/base ne
 * verra jamais rien : il n'y a pas d'écart. L'écart est entre le **type de la propriété** et la
 * **nullabilité de la colonne**, deux choses que rien ne confronte.
 *
 * ── POURQUOI LA VALIDATION NE RATTRAPE PAS ──────────────────────────────────────────────────────
 *
 * `Assert\NotNull` ne s'exécute que sur le chemin API. Une fixture, un import, une commande, un test
 * qui persiste par l'`EntityManager` passent à côté — et ce sont précisément les chemins qui
 * construisent des entités à la main.
 *
 *   > **Une propriété nullable adossée à une colonne non nulle ne se voit qu'au `flush()`.**
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
 *
 * Une propriété dont le défaut est littéralement `= null` et dont la colonne est `NOT NULL` doit :
 *
 *   - soit devenir non nullable et être exigée au constructeur ;
 *   - soit déclarer `nullable: true` si le métier l'autorise vraiment ;
 *   - soit porter l'échappatoire, quand le champ est rempli PAR LE SERVEUR après construction.
 *
 * Ce troisième cas est légitime et fréquent : `ProductPhoto::$documentRef` est posé par
 * `UploadProductPhotoProcessor`, `Referral::$sponsorRef` par `ReferralService`. Leur ajouter une
 * contrainte de validation serait un contresens — elle s'exécuterait AVANT le serveur.
 *
 * ── L'ÉCHAPPATOIRE ──────────────────────────────────────────────────────────────────────────────
 *
 *     @rempli-au-serveur : <qui pose ce champ, et à quel moment>
 *
 * Greppable, datée, attribuable — la même porte que `@cloisonnement-verifie`, `@drop-voulu` et
 * `@liaison-verifiee`.
 *
 *     grep -rn "@rempli-au-serveur" app/src
 *
 * ── CE QU'IL NE SIGNALE PAS, À DESSEIN ──────────────────────────────────────────────────────────
 *
 * Une propriété nullable initialisée dans le constructeur (`$this->x = new X()`) ne peut pas
 * produire l'erreur : la signaler serait du bruit, et le bruit fait désactiver les garde-fous. Seul
 * le défaut littéral `= null` est retenu.
 *
 * Usage :
 *   php bin/garde-fou-nullable-non-nul.php
 *   php bin/garde-fou-nullable-non-nul.php --nettoyer
 *   php bin/garde-fou-nullable-non-nul.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/nullable-non-nul.ligne-de-base.json';

/** Les attributs qui précèdent une propriété privée nullable dont le défaut est `null`. */
const MOTIF_PROPRIETE = '/((?:^[ ]*#\[[^\n]*\]\n)+)[ ]*private[ ]+\?([\w\\\\]+)[ ]+\$(\w+)\s*=\s*null\s*;/m';

const MOTIF_COLONNE = '/#\[ORM\\\\Column\(([^\]]*)\)\]/';
const MOTIF_ANNOTATION = '/@rempli-au-serveur\s*:\s*\S/';

/** @return list<array{fichier: string, ligne: int, propriete: string, type: string}> */
function proprietesRisquees(string $racine): array
{
    $trouvees = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        if (!str_contains($source, '#[ORM\\Entity')) {
            continue;
        }

        if (preg_match_all(MOTIF_PROPRIETE, $source, $proprietes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        $relatif = substr($fichier->getPathname(), strlen($racine) + 1);

        foreach ($proprietes as $propriete) {
            $attributs = $propriete[1][0];

            // Pas une colonne : une association gère sa nullabilité par `JoinColumn`, et un champ
            // calculé n'est pas persisté du tout.
            if (preg_match(MOTIF_COLONNE, $attributs, $colonne) !== 1) {
                continue;
            }
            // Cohérent : les deux acceptent `null`.
            if (str_contains($colonne[1], 'nullable: true')) {
                continue;
            }
            // L'identifiant est posé au constructeur.
            if (str_contains($attributs, 'ORM\\Id')) {
                continue;
            }
            // Le champ est rempli par le serveur, et c'est déclaré.
            //
            // ⚠ On regarde la FENETRE qui précède la propriété, pas seulement ses attributs : une
            // annotation vit naturellement dans un docbloc, et un docbloc n'est pas un .
            // Première version : l'échappatoire ne se déclenchait jamais — vu en l'éprouvant, pas
            // en le relisant.
            $avant = substr($source, max(0, (int) $propriete[0][1] - 600), min(600, (int) $propriete[0][1]));
            if (preg_match(MOTIF_ANNOTATION, $avant . $attributs) === 1) {
                continue;
            }

            $morceaux = explode('\\', $propriete[2][0]);

            $trouvees[] = [
                'fichier' => $relatif,
                'ligne' => substr_count(substr($source, 0, (int) $propriete[0][1]), "\n") + 1,
                'propriete' => $propriete[3][0],
                'type' => end($morceaux),
            ];
        }
    }

    usort($trouvees, static fn (array $a, array $b): int => [$a['fichier'], $a['propriete']] <=> [$b['fichier'], $b['propriete']]);

    return $trouvees;
}

/** @param list<array{fichier: string, propriete: string}> $entrees */
function cles(array $entrees): array
{
    return array_map(static fn (array $e): string => $e['fichier'] . '::$' . $e['propriete'], $entrees);
}

$options = array_slice($argv, 1);
$risquees = proprietesRisquees(RACINE_SRC);

if (in_array('--nettoyer', $options, true)) {
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode(
            ['scelle' => ['plafond' => count($risquees)], 'entrees' => cles($risquees)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n"
    );
    printf("Ligne de base réécrite : %d propriété(s), plafond %d.\n", count($risquees), count($risquees));
    exit(0);
}

if (!is_file(LIGNE_DE_BASE)) {
    fwrite(STDERR, sprintf("Ligne de base absente : %s\nCrée-la : php %s --nettoyer\n", LIGNE_DE_BASE, $argv[0]));
    exit(2);
}

$base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($base) || !isset($base['scelle']['plafond'], $base['entrees'])) {
    fwrite(STDERR, "Ligne de base illisible : clés « scelle.plafond » et « entrees » attendues.\n");
    exit(2);
}

$plafond = (int) $base['scelle']['plafond'];
$connues = (array) $base['entrees'];
$nouvelles = array_values(array_diff(cles($risquees), $connues));

if ($nouvelles !== []) {
    fwrite(STDERR, sprintf(
        "Propriétés nullables sur colonne NON NULLE : %d nouvelle(s), plafond %d.\n\n",
        count($nouvelles),
        $plafond
    ));
    foreach ($nouvelles as $nouvelle) {
        fwrite(STDERR, '    ' . $nouvelle . "\n");
    }
    fwrite(STDERR, "\nUne entité neuve laisse ce champ à `null` : PHP l'accepte, Doctrine aussi,\n");
    fwrite(STDERR, "MariaDB refuse au flush(). Ni la validation ni un contrôle de dérive ne le voient.\n");
    fwrite(STDERR, "\nTrois issues : rendre la propriété non nullable et l'exiger au constructeur,\n");
    fwrite(STDERR, "déclarer `nullable: true` si le métier l'autorise, ou — si le champ est posé par\n");
    fwrite(STDERR, "le SERVEUR après construction — l'annoter :\n\n");
    fwrite(STDERR, "    @rempli-au-serveur : <qui pose ce champ, et à quel moment>\n");
    exit(1);
}

printf(
    "Nullable sur colonne non nulle : OK — aucune nouvelle. Dette gelée : %d, plafond %d.\n",
    count($risquees),
    $plafond
);
exit(0);
