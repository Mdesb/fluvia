<?php

declare(strict_types=1);

// QUELLES PROPRIETES FILTREES SONT IDENTIFIEES PAR UN Uuid ?
//
// On ne le devine ni au nom ni a l'expression reguliere : on le demande au mapping Doctrine, seule
// autorite sur le type reellement stocke. Un nom comme `partenaire` ne dit pas si la cible
// s'identifie par un Uuid ou par un entier ; le mapping, si.
//
// ⚠ SEPARATEUR DE NAMESPACE : on ecrit chr(92), jamais un antislash litteral. Ce fichier a d'abord
// ete pose par un heredoc de shell qui a reduit la paire d'antislashs a un seul, transformant
// `'\\'` en une apostrophe echappee -- erreur de syntaxe signalee huit lignes plus bas que sa cause.

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;

// ⚠ `autoload.php`, PAS `autoload_runtime.php`. Le runtime de Symfony veut que le fichier rende une
// application ; un script de mesure qui rend un code de sortie le fait echouer APRES avoir tout
// affiche correctement -- un rapport juste suivi d'une trace d'erreur, qu'on lit comme une panne.
require dirname(__DIR__).'/vendor/autoload.php';

(static function (): void {
    $sep = chr(92);

    $kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'prod', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();

    $racine = dirname(__DIR__).'/src';
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
    $parModule = [];
    $total = 0;

    foreach ($rii as $f) {
        if ($f->isDir() || $f->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($f->getPathname());
        if (!str_contains($src, 'SearchFilter::class')) {
            continue;
        }
        if (!preg_match('/namespace[ ]+([^;]+);/', $src, $m)) {
            continue;
        }
        if (!preg_match('/class[ ]+([A-Za-z0-9_]+)/', $src, $c)) {
            continue;
        }
        $classe = trim($m[1]).$sep.$c[1];
        if (!class_exists($classe)) {
            continue;
        }

        try {
            $meta = $em->getClassMetadata($classe);
        } catch (\Throwable) {
            continue;
        }

        $rc = new ReflectionClass($classe);
        foreach ($rc->getAttributes(ApiFilter::class) as $attr) {
            $args = $attr->getArguments();
            $filtre = $args[0] ?? ($args['filterClass'] ?? null);
            if ($filtre !== SearchFilter::class) {
                continue;
            }
            $props = $args['properties'] ?? ($args[1] ?? []);

            foreach ($props as $prop => $strategie) {
                $nom = is_int($prop) ? $strategie : $prop;
                if (!is_string($nom)) {
                    continue;
                }

                $uuid = false;
                if ($meta->hasAssociation($nom)) {
                    $cible = $meta->getAssociationTargetClass($nom);
                    try {
                        $mc = $em->getClassMetadata($cible);
                        $ids = $mc->getIdentifierFieldNames();
                        $uuid = count($ids) === 1 && ($mc->getTypeOfField($ids[0]) ?? '') === 'uuid';
                    } catch (\Throwable) {
                    }
                } elseif ($meta->hasField($nom)) {
                    $uuid = ($meta->getTypeOfField($nom) ?? '') === 'uuid';
                }

                if (!$uuid) {
                    continue;
                }

                $module = explode($sep, $classe)[1] ?? '?';
                $parModule[$module][] = $c[1].'::'.$nom;
                ++$total;
            }
        }
    }

    ksort($parModule);
    foreach ($parModule as $module => $liste) {
        printf("%-16s %2d   %s\n", $module, count($liste), implode(', ', $liste));
    }
    printf(
        "\nTOTAL : %d propriete(s) filtree(s) sur un identifiant Uuid.\n"
        ."Elles rendaient une liste vide en silence ; UuidAwareSearchFilterPass les couvre toutes.\n",
        $total,
    );
})();
