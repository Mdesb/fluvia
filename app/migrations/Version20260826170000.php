<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rend des droits à six profils de démonstration, pour qu'on puisse enfin essayer le produit autrement
 * qu'en administrateur.
 *
 * **Ce qui s'est passé.** Le 24/08, une régénération des données de démonstration a échoué **après avoir
 * tronqué `sec_role_permission`**. Résultat mesuré le 26/08 : **38 rôles, dont 34 à zéro droit.** Maxime
 * ne pouvait donc tester qu'avec son propre compte, celui qui a tout — c'est-à-dire du seul point de vue
 * qui n'existe chez aucun de ses futurs utilisateurs.
 *
 * **Pourquoi ce n'est pas réparé par un rechargement des fixtures**, qui serait la voie normale :
 *
 * 1. `doctrine/doctrine-fixtures-bundle` est une dépendance de **développement**, et le déploiement
 *    installe `--no-dev`. La commande `doctrine:fixtures:load` **n'existe pas en préproduction**.
 * 2. Même en test, le chargement complet ne passe pas encore : une quinzaine de fixtures créent des
 *    objets à contrainte d'unicité sans chercher d'abord. Six ont été corrigées le 26/08, le reste est
 *    réparti.
 * 3. Et la purge elle-même échoue sur `reservation_ressource`, qui se référence elle-même : le purgeur
 *    supprime dans un ordre qui ne tient pas compte des hiérarchies. **Une base qui contient déjà ces
 *    données ne peut plus être purgée.**
 *
 * Autrement dit : les données de démonstration ne sont aujourd'hui **rechargeables nulle part**. C'est
 * un chantier ouvert ; en attendant, personne ne peut essayer le produit.
 *
 * **Ce que cette migration fait, et ce qu'elle ne fait pas.** Elle ne restitue pas les 34 rôles à
 * l'identique — cette information vit dans les fixtures et ne peut pas en être extraite tant qu'elles ne
 * se chargent pas. Elle rend utilisables **six profils** couvrant les postes de travail réels, à partir
 * des permissions **déjà présentes en base** : aucun code de permission n'est inventé ici.
 *
 * Elle est écrite en SQL avec `NOT EXISTS` : la rejouer ne crée pas de doublon, et elle ne touche que
 * les rôles qu'elle nomme. `down()` retire exactement ce que `up()` a posé.
 */
final class Version20260826170000 extends AbstractMigration
{
    /**
     * Le profil de chaque poste, en modules entiers.
     *
     * Un module entier plutôt qu'une liste d'actions : c'est un jeu de démonstration destiné à essayer
     * les écrans, pas un modèle de droits de production. Le détail viendra des fixtures le jour où
     * elles se chargeront de nouveau — et le supposer ici figerait une approximation dans une migration,
     * c'est-à-dire à l'endroit le plus difficile à corriger.
     *
     * @var array<string, list<string>>
     */
    private const PROFILS = [
        'Caissier' => ['caisse', 'vente', 'offre'],
        'Responsable de site' => ['caisse', 'vente', 'offre', 'reporting', 'boutique', 'organisation'],
        'Agent d\'accueil réservation' => ['reservation', 'acces', 'offre'],
        'Comptable' => ['compta', 'facturation', 'recouvrement', 'sepa'],
        'Gestionnaire boutique' => ['boutique', 'stock', 'offre'],
        'Lecture seule' => ['*'],
    ];

    public function getDescription(): string
    {
        return 'Rend des droits à six profils de démonstration (incident du 24/08 : sec_role_permission tronquée).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PROFILS as $role => $modules) {
            $liste = implode(', ', array_map(fn (string $m): string => $this->connection->quote($m), $modules));

            $this->addSql(sprintf(
                'INSERT INTO sec_role_permission (role_id, permission_id)
                 SELECT r.id, p.id
                 FROM sec_role r
                 JOIN sec_permission p ON p.module IN (%s)
                 WHERE r.nom = %s
                   AND NOT EXISTS (
                       SELECT 1 FROM sec_role_permission rp
                       WHERE rp.role_id = r.id AND rp.permission_id = p.id
                   )',
                $liste,
                $this->connection->quote($role),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::PROFILS as $role => $modules) {
            $liste = implode(', ', array_map(fn (string $m): string => $this->connection->quote($m), $modules));

            $this->addSql(sprintf(
                'DELETE rp FROM sec_role_permission rp
                 JOIN sec_role r ON r.id = rp.role_id
                 JOIN sec_permission p ON p.id = rp.permission_id
                 WHERE r.nom = %s AND p.module IN (%s)',
                $this->connection->quote($role),
                $liste,
            ));
        }
    }
}
