<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `entree_unitaire` ↔ `prestation` : rendre la conversion POSSIBLE (CA-13).
 *
 * `ConvertirProcessor:54` refuse toute conversion entre deux types qui ne se déclarent pas
 * compatibles. `prestation`, posé par `Version20260904232000`, ne l'était avec aucun : la décision
 * de Maxime — basculer les produits de réservation — était donc inapplicable telle quelle.
 *
 * ⚠ CE QUE DÉCLARER LA COMPATIBILITÉ AUTORISE, DANS LES DEUX SENS. Ce n'est pas un laissez-passer
 * ponctuel pour deux produits : c'est une règle permanente du référentiel. À partir d'ici,
 * n'importe quel produit d'entrée unitaire peut devenir une prestation, et l'inverse. C'est voulu —
 * les deux décrivent une chose vendue à l'unité à un consommateur — mais le sens qui compte est
 * celui-ci : passer en `prestation` fait PERDRE l'émission du billet, y compris pour une vente au
 * comptoir du même produit. Le type gouverne le produit, jamais le canal.
 *
 * ⚠ AUCUNE DONNÉE PERDUE PAR CETTE PAIRE, et c'est mesurable plutôt que présumé : les seules
 * facettes qui portent une relation sont `formule`, `carnet` et `stock` (`ResolveurFacettes`).
 * `billet`, la seule que `entree_unitaire` perd ici, n'en porte aucune — `purgerOrphelins()` n'a
 * donc rien à détacher. C'est ce qui rend cette paire sûre là où `entree_unitaire → boutique_stock`
 * ne le serait pas.
 *
 * ⚠ Insertion gardée par `NOT EXISTS` : la table n'a pas d'unicité sur (source, cible), donc un
 * second passage la DUPLIQUERAIT en silence — et `contains()` côté Doctrine ne s'en plaindrait pas.
 */
final class Version20260904234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déclare entree_unitaire ↔ prestation comme types convertibles (CA-13).';
    }

    public function up(Schema $schema): void
    {
        foreach ([['entree_unitaire', 'prestation'], ['prestation', 'entree_unitaire']] as [$source, $cible]) {
            $this->addSql(
                'INSERT INTO off_type_produit_compatible (source_id, cible_id)
                 SELECT s.id, c.id
                 FROM off_type_produit s, off_type_produit c
                 WHERE s.code = :source AND c.code = :cible
                   AND NOT EXISTS (
                       SELECT 1 FROM off_type_produit_compatible k
                       WHERE k.source_id = s.id AND k.cible_id = c.id
                   )',
                ['source' => $source, 'cible' => $cible],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // ⚠ ON NE RETIRE QUE LA PAIRE POSÉE ICI. Supprimer par `source_id IN (...)` emporterait
        // `entree_unitaire ↔ carte`, qui existe depuis les fixtures et n'a rien à voir avec ce lot.
        $this->addSql(
            'DELETE k FROM off_type_produit_compatible k
             JOIN off_type_produit s ON s.id = k.source_id
             JOIN off_type_produit c ON c.id = k.cible_id
             WHERE (s.code = :a AND c.code = :b) OR (s.code = :b AND c.code = :a)',
            ['a' => 'entree_unitaire', 'b' => 'prestation'],
        );
    }
}
