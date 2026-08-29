<?php

declare(strict_types=1);

/**
 * GARDE-FOU — UN FILTRE DÉCLARÉ SUR UNE PROPRIÉTÉ QUI N'EXISTE PAS REND TOUTE LA COLLECTION.
 *
 * API Platform ignore **en silence** une propriété inconnue : le filtre apparaît dans la
 * documentation, le paramètre est accepté, et la collection sort entière. Trouvé par allaccess-c2 sur
 * `OpeningSlot` :
 *
 *     #[ApiFilter(SearchFilter::class, properties: [… 'day' => 'exact'])]
 *     private int $weekday = 1;                       ← la propriété s'appelle `weekday`
 *
 * ⚠ CE DÉFAUT NE SE VOIT PAS À L'USAGE. Un filtre cassé qui rend une liste VIDE finit par être
 * signalé : une absence intrigue. Celui-ci rend TOUT — ça ressemble à des données, ça arrive, ça a la
 * bonne forme, et personne ne le remet en cause. C'était la quatrième occurrence d'un même renommage
 * `jour` → `day` dans ce dépôt : trois balayages successifs l'avaient manqué, chacun couvrant un
 * endroit où le mot pouvait vivre, aucun ne les couvrant tous.
 *
 * ── CE QU'ON MESURE, ET CE QU'ON NE MESURE PAS ──────────────────────────────────────────────────
 *
 * On demande au mapping Doctrine si la propriété existe — pas au nom, pas à une expression
 * régulière. Les chemins imbriqués (`client.nom`) sont suivis association par association, ce qui est
 * une forme légitime pour `SearchFilter`.
 *
 * Ce contrôle ne dit rien de la JUSTESSE du filtre : une propriété qui existe peut être filtrée de
 * travers (c'est le défaut des identifiants `Uuid`, traité ailleurs). Il dit seulement qu'elle
 * existe — ce qui est la condition pour que le filtre s'applique du tout.
 */

// ⚠ LE HOOK `pre-receive` EXTRAIT L'ARBRE POUSSE DANS UN REPERTOIRE TEMPORAIRE, SANS `app/vendor`.
// Un `require` nu y est fatal : le controle plantait et REFUSAIT tous les pushs, les miens compris.
//
// On se declare ignore plutot que de tomber -- et on le DIT. Un controle silencieusement saute
// redevient un controle vert qui ne mesure rien, ce qui est precisement la famille de defauts que
// celui-ci existe pour attraper. Il garde tout son mordant la ou les dependances sont presentes :
// le `pre-commit` local, et `bin/garde-fous.sh`.
if (!is_file(dirname(__DIR__).'/app/vendor/autoload.php')) {
    fwrite(STDOUT, "Filtres déclarés : · IGNORÉ — dépendances Composer absentes. Le contrôle n'a PAS tourné.\n");

    exit(0);
}

require dirname(__DIR__).'/app/vendor/autoload.php';

use ApiPlatform\Metadata\ApiFilter;
use Doctrine\ORM\Mapping\ClassMetadata;

(static function (): void {
    $sep = chr(92);

    // ⚠ AUCUNE CONNEXION N'EST OUVERTE — SEULE LA VARIABLE EST EXIGÉE. Le lanceur des garde-fous
    // tourne avec `--network none` et sans variables d'environnement : sans cette ligne,
    // `getClassMetadata()` lève « Environment variable not found: DATABASE_URL » sur les 128 classes,
    // et un `catch` les faisait toutes passer en silence. Le contrôle rendait alors
    // « 0 propriété contrôlée, toutes existent » — un rapport vert qui ne mesurait rien.
    // Le mapping se lit sur les attributs des classes ; la base n'est jamais jointe.
    $_SERVER['DATABASE_URL'] ??= 'mysql://garde:fou@127.0.0.1:3306/aucune?serverVersion=mariadb-11.4.0';
    $_ENV['DATABASE_URL'] ??= $_SERVER['DATABASE_URL'];

    $kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'prod', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();

    /**
     * La propriété existe-t-elle ? Un chemin imbriqué est suivi association par association.
     */
    $existe = static function (ClassMetadata $metadonnees, string $chemin) use ($em): bool {
        $segments = explode('.', $chemin);
        $courant = $metadonnees;

        foreach ($segments as $index => $segment) {
            $dernier = $index === \count($segments) - 1;

            if ($courant->hasField($segment)) {
                // Un champ scalaire ne peut être qu'en bout de chemin.
                return $dernier;
            }

            if (!$courant->hasAssociation($segment)) {
                return false;
            }

            if ($dernier) {
                return true;
            }

            $cible = $courant->getAssociationTargetClass($segment);
            $manager = $em->getMetadataFactory();
            if (!$manager->hasMetadataFor($cible) && !class_exists($cible)) {
                return false;
            }
            $courant = $em->getClassMetadata($cible);
        }

        return false;
    };

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/app/src'));
    $violations = [];
    $illisibles = [];
    $controlees = 0;

    foreach ($rii as $fichier) {
        if ($fichier->isDir() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($fichier->getPathname());
        if (!str_contains($source, 'ApiFilter')) {
            continue;
        }
        if (!preg_match('/namespace[ ]+([^;]+);/', $source, $m) || !preg_match('/class[ ]+([A-Za-z0-9_]+)/', $source, $c)) {
            continue;
        }

        $classe = trim($m[1]).$sep.$c[1];
        if (!class_exists($classe)) {
            continue;
        }

        try {
            $metadonnees = $em->getClassMetadata($classe);
        } catch (\Doctrine\Persistence\Mapping\MappingException) {
            // Le fichier mentionne `ApiFilter` sans être une entité : la classe du filtre elle-même,
            // le noyau qui l'importe, un fournisseur d'état. Rien à contrôler, et ce n'est pas un
            // trou dans la mesure.
            continue;
        } catch (\Throwable $erreur) {
            // ⚠ ON NE PASSE PAS EN SILENCE. Un `catch` muet ici a rendu « 0 propriété contrôlée,
            // toutes existent » alors que les 128 classes échouaient sur une variable
            // d'environnement absente. Une classe qu'on n'a pas pu lire n'est pas une classe sans
            // défaut : c'est un trou dans la mesure, et il doit se voir.
            $illisibles[] = \sprintf('%s (%s)', $c[1], $erreur->getMessage());

            continue;
        }

        foreach ((new ReflectionClass($classe))->getAttributes(ApiFilter::class) as $attribut) {
            $arguments = $attribut->getArguments();
            $proprietes = $arguments['properties'] ?? ($arguments[1] ?? []);
            if (!\is_array($proprietes)) {
                continue;
            }

            foreach ($proprietes as $cle => $valeur) {
                $nom = \is_int($cle) ? $valeur : $cle;
                if (!\is_string($nom) || $nom === '') {
                    continue;
                }

                ++$controlees;

                if (!$existe($metadonnees, $nom)) {
                    $violations[] = \sprintf('%s::%s', $c[1], $nom);
                }
            }
        }
    }

    // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────────
    // Un contrôle qui n'a rien lu rend « aucune violation », ce qui se lit exactement comme « tout va
    // bien ». On refuse de conclure d'une absence sans avoir mesuré une présence.
    if ($controlees === 0) {
        printf("Filtres déclarés : ⚠ AUCUNE PROPRIÉTÉ N'A ÉTÉ CONTRÔLÉE — ce rapport ne prouve rien.\n");
        printf("  Ce dépôt déclare des filtres ; en lire zéro signale l'instrument, pas le code.\n");
        foreach (\array_slice($illisibles, 0, 3) as $illisible) {
            echo '  - '.$illisible."\n";
        }

        exit(2);
    }

    if ($illisibles !== []) {
        printf("Filtres déclarés : ⚠ %d classe(s) illisible(s) — la mesure est incomplète.\n", \count($illisibles));
        foreach (\array_slice($illisibles, 0, 5) as $illisible) {
            echo '  - '.$illisible."\n";
        }

        exit(2);
    }

    if ($violations === []) {
        printf(
            "Filtres déclarés : OK — %d propriété(s) contrôlée(s), toutes existent au mapping.\n",
            $controlees,
        );

        return;
    }

    printf(
        "Filtres déclarés : %d propriété(s) déclarée(s) dans un filtre N'EXISTENT PAS (%d contrôlées).\n",
        \count($violations),
        $controlees,
    );
    printf("Le filtre est ignoré en silence et la collection sort ENTIÈRE.\n\n");
    foreach ($violations as $violation) {
        echo '  - '.$violation."\n";
    }

    exit(1);
})();
