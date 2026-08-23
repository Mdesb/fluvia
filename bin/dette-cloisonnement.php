#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * État consolidé de la dette de cloisonnement.
 *
 * **Pourquoi cet outil.** Trois lignes de base coexistent, chacune juste et chacune illisible seule :
 * `cloisonnement.ligne-de-base.json` (règles n°1 et n°2) et `couverture-perimetre.ligne-de-base.json`
 * (règle n°5). Ensemble elles décrivent une centaine d'endroits à revoir, rangés par mécanisme de
 * détection — c'est-à-dire dans l'ordre qui arrange les garde-fous, pas celui qui arrange qui corrige.
 *
 * Le problème que ça résout est concret : entre le 20 et le 23/08, huit défauts de cloisonnement ont
 * été trouvés, et chacun était **déjà** dans une ligne de base, gelé donc vert donc invisible. Une
 * dette qu'on ne peut pas lire par ordre d'urgence n'est pas priorisée : elle est oubliée.
 *
 * Cet outil ne juge rien et n'invente rien : il relit les trois fichiers et les range par sensibilité,
 * puis par module. Les chemins argent et accès d'abord, parce que c'est là que les huit trouvailles
 * sont sorties.
 *
 * Usage :
 *   php bin/dette-cloisonnement.php              # synthèse à l'écran
 *   php bin/dette-cloisonnement.php --detail     # avec chaque entrée
 *   php bin/dette-cloisonnement.php --markdown   # document à coller dans COORDINATION/
 */

const FICHIERS = [
    'résolution non contrôlée (règle n°1)' => ['bin/cloisonnement.ligne-de-base.json', 'entrees'],
    'contrôle non lié à l\'entité (règle n°2)' => ['bin/cloisonnement.ligne-de-base.json', 'entrees_resolution'],
    'entité exposée non cloisonnable (règle n°5)' => ['bin/couverture-perimetre.ligne-de-base.json', 'entrees'],
];

/**
 * Sensibilité déduite de **l'entité résolue**, et non du dossier.
 *
 * Deux fois le classement par module s'est révélé faux : `EmettreVenteNoShowProcessor` rangé sous
 * `Reservation` alors qu'il touche une `SessionCaisse`, et `ActionsDeMasseProcessor` rangé en
 * « autre » alors qu'il modifie un catalogue en masse. Ce que le code **fait** compte plus que
 * l'endroit où il vit.
 *
 * Cette table vient des treize cas réellement instruits entre le 20 et le 23/08, pas d'une théorie.
 */
const ENTITES_SENSIBLES = [
    'argent' => [
        'SessionCaisse', 'MouvementCaisse', 'Vente', 'VenteImpayeeRegie', 'Facture', 'FactureB2G',
        'Caution', 'CautionCasier', 'CautionMateriel', 'CautionLocationPatins', 'MouvementCaution',
        'BordereauPayFiP', 'BordereauVersement', 'EcritureComptable', 'LigneEcriture', 'Journal',
        'PorteMonnaieVirtuel', 'MouvementPmv', 'Produit', 'Categorie', 'CatalogueFournisseur',
        'CommandeAchat', 'EtalementPca', 'MouvementPca', 'ProfilExploitant', 'ExportComptable',
    ],
    'acces' => [
        'DroitAcces', 'EspaceAcces', 'BadgeStaff', 'Controleur', 'Equipement', 'BraceletEtanche',
        'Utilisateur', 'Affectation', 'DeclarationPerteVol', 'SousReseau', 'OperationSensible',
    ],
    'donnees-personnelles' => [
        'Client', 'Beneficiaire', 'Consentement', 'TicketSupport', 'MessageTicket', 'Employe',
        'CompteClient', 'DemandeRGPD',
    ],
];

/**
 * @param list<string> $entites
 */
function sensibiliteReelle(array $entites, string $moduleParDefaut): string
{
    foreach (ENTITES_SENSIBLES as $niveau => $noms) {
        if (array_intersect($entites, $noms) !== []) {
            return $niveau;
        }
    }

    return $moduleParDefaut;
}

/** Ordre d'urgence. Il vient de l'expérience, pas d'une théorie : les huit défauts trouvés du 20 au 23/08 sont tous sortis des deux premiers. */
const ORDRE = ['argent' => 0, 'acces' => 1, 'donnees-personnelles' => 2, 'autre' => 3, '(non classé)' => 4];

/** @return array<string, array<string, list<array{entree: string, module: string}>>> [sensibilité][règle] */
function collecter(): array
{
    $tout = [];

    foreach (FICHIERS as $regle => [$chemin, $cle]) {
        if (!is_file($chemin)) {
            fwrite(STDERR, sprintf("Ligne de base absente : %s — lance depuis la racine du dépôt.\n", $chemin));
            exit(2);
        }

        $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        foreach ($donnees[$cle] ?? [] as $entree => $meta) {
            // On recalcule au moment du rapport plutôt que de croire le champ gelé : les lignes
            // de base ont été écrites avant qu'on sache que le module est un mauvais indicateur.
            // Les entités résolues ne sont enregistrées que pour la règle n°1. Pour la règle n°5,
            // l'entrée EST une entité : son nom se lit dans le chemin, `…/Entity/EcritureComptable.php`.
            // Sans ce rattrapage, la moitié de la dette resterait classée par module — c'est-à-dire
            // par l'indicateur dont on vient d'établir qu'il trompe.
            $entites = $meta['entites_resolues'] ?? [];
            if ($entites === [] && preg_match('#/Entity/(\w+)\.php$#', $entree, $nomEntite) === 1) {
                $entites = [$nomEntite[1]];
            }

            $sensibilite = sensibiliteReelle($entites, $meta['sensibilite'] ?? '(non classé)');
            $module = $meta['module'] ?? explode('/', $entree)[0];
            $tout[$sensibilite][$regle][] = ['entree' => $entree, 'module' => $module];
        }
    }

    uksort($tout, static fn (string $a, string $b): int => (ORDRE[$a] ?? 9) <=> (ORDRE[$b] ?? 9));

    return $tout;
}

$options = array_slice($argv, 1);
$detail = in_array('--detail', $options, true);
$markdown = in_array('--markdown', $options, true);

$tout = collecter();
$total = 0;
foreach ($tout as $regles) {
    foreach ($regles as $entrees) {
        $total += count($entrees);
    }
}

if ($markdown) {
    echo "# Dette de cloisonnement — état consolidé\n\n";
    echo sprintf("> Généré par `bin/dette-cloisonnement.php`. **%d endroits** à revoir, rangés par urgence.\n", $total);
    echo "> Chaque entrée est gelée dans une ligne de base : le garde-fou est vert dessus, ce qui veut\n";
    echo "> dire « connu », pas « sans danger ». Huit défauts réels sont sortis de ces listes.\n\n";
} else {
    echo sprintf("\nDette de cloisonnement : %d endroits, par ordre d'urgence\n", $total);
    echo str_repeat('─', 60) . "\n";
}

foreach ($tout as $sensibilite => $regles) {
    $sousTotal = 0;
    foreach ($regles as $entrees) {
        $sousTotal += count($entrees);
    }

    echo $markdown
        ? sprintf("\n## %s — %d\n\n", strtoupper($sensibilite), $sousTotal)
        : sprintf("\n%s — %d\n", strtoupper($sensibilite), $sousTotal);

    foreach ($regles as $regle => $entrees) {
        $parModule = [];
        foreach ($entrees as $e) {
            $parModule[$e['module']][] = $e['entree'];
        }
        ksort($parModule);

        echo $markdown
            ? sprintf("**%s** (%d)\n\n", $regle, count($entrees))
            : sprintf("  %s (%d)\n", $regle, count($entrees));

        foreach ($parModule as $module => $liste) {
            if ($detail || $markdown) {
                echo $markdown ? sprintf("- `%s` (%d)\n", $module, count($liste)) : sprintf("    %-16s %d\n", $module, count($liste));
                foreach ($liste as $entree) {
                    echo $markdown ? sprintf("  - `%s`\n", $entree) : sprintf("      %s\n", $entree);
                }
            } else {
                echo sprintf("    %-16s %d\n", $module, count($liste));
            }
        }
        echo "\n";
    }
}

if (!$markdown) {
    echo str_repeat('─', 60) . "\n";
    echo "  --detail pour chaque entrée · --markdown pour un document à partager\n\n";
}
