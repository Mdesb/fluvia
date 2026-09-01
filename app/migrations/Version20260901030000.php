<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ACCORDER `comptabilite` ET `stock` À TOUT L'EXISTANT, AVANT QUE QUOI QUE CE SOIT NE S'Y ADOSSE.
 *
 * ── ⚠ POURQUOI CETTE MIGRATION DOIT PRÉCÉDER L'ÉCRAN, ET NON L'INVERSE ────────────────────────
 *
 * `Version` précédente a créé les capacités `comptabilite`, `stock` et `agenda`. Tant que rien ne
 * les consulte, elles sont inertes : la comptabilité et le stock restent visibles de tous, comme
 * depuis toujours.
 *
 * **Le défaut naît au branchement, pas à la création.** Le jour où la fiche produit compose ses
 * onglets d'après ces codes, un établissement qui ne les porte pas perd un module qu'il utilisait
 * la veille — sans erreur, sans message : l'onglet disparaît, simplement.
 *
 * Ce n'est donc pas une régression du code qui branche, c'est une régression de l'octroi qu'on a
 * oublié. Même asymétrie qu'une colonne `NOT NULL` posée avant que le code ne sache la remplir :
 * ce qui casse n'est pas ce qu'on écrit, c'est ce qu'on a supposé déjà là.
 *
 * ── CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Elle accorde les deux capacités **communes** à chaque établissement existant. Elle n'accorde
 * **pas** `agenda` : celle-là décrit une offre datée, que la plupart des exploitants n'ont pas au
 * catalogue. Elle s'accorde établissement par établissement, à la main ou par le preset `musee`.
 *
 * `INSERT ... SELECT ... WHERE NOT EXISTS` : rejouable sans faire de doublon, et l'index unique
 * `(etablissement_id, capacite_code)` le garantit de toute façon. Un établissement qui aurait déjà
 * la capacité — désactivée, par exemple — n'est pas touché : on n'écrase pas une décision prise.
 *
 * `UNHEX(REPLACE(UUID(), '-', ''))` plutôt que `UUID_TO_BIN` : la seconde n'existe pas sur toutes
 * les versions de MariaDB visées, et un identifiant est un identifiant.
 */
final class Version20260901030000 extends AbstractMigration
{
    private const COMMUNES = ['comptabilite', 'stock'];

    public function getDescription(): string
    {
        return 'Accorde les capacités « comptabilite » et « stock » à tous les établissements existants.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COMMUNES as $code) {
            $this->addSql(sprintf(
                "INSERT INTO fonctionnalite_etablissement (id, etablissement_id, capacite_code, active, parametres, modifie_le)
                 SELECT UNHEX(REPLACE(UUID(), '-', '')), e.id, '%s', 1, NULL, NOW()
                 FROM org_etablissement e
                 WHERE NOT EXISTS (
                     SELECT 1 FROM fonctionnalite_etablissement f
                     WHERE f.etablissement_id = e.id AND f.capacite_code = '%s'
                 )",
                $code,
                $code,
            ));
        }
    }

    /**
     * ⚠ VOLONTAIREMENT VIDE, ET CE N'EST PAS UN OUBLI.
     *
     * Retirer ces capacités rendrait invisibles la comptabilité et le stock chez des exploitants
     * qui s'en servent — c'est-à-dire exactement le défaut que cette migration existe pour
     * empêcher. Un `down()` qui « annule » proprement produirait donc la panne.
     *
     * Et il ne saurait pas distinguer ce qu'il a accordé de ce qu'un exploitant a activé lui-même
     * depuis : il retirerait les deux.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Retirer « comptabilite » ou « stock » couperait ces modules chez des exploitants qui '
            . 'les utilisent. Si une capacité doit être retirée, elle se retire établissement par '
            . 'établissement, en connaissance de cause.'
        );
    }
}
