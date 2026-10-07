<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reprise : les clients nés d'un achat en ligne reviennent dans l'établissement de leur vitrine (04/10/2026).
 *
 * `ClientM4Adapter::creerRapide()` rattachait un client sans établissement actif au premier établissement
 * renvoyé par la base. En préprod, les 8 clients nés de paniers de « Piscine A » étaient chez « Musée C »
 * (un autre groupe). Le panier, lui, porte l'établissement de sa vitrine : c'est la référence.
 *
 * QUI EST REPRIS — et seulement eux (relecture adversariale du 04/10) :
 *   - un client SANS compte boutique : un client lié à un compte n'est pas né de `creerRapide` ; il peut
 *     acheter sur la vitrine d'un autre établissement, et le déplacer recréerait la fuite qu'on corrige ;
 *   - dont TOUS les paniers sont d'un seul et même établissement (pas seulement ceux qui divergent) ;
 *   - qui en diverge, et dont l'établissement cible a une région et un groupe.
 * La cible est calculée UNE fois par client (`repris`), jamais par jointure sur tous ses paniers.
 *
 * Suivent le client : sa famille (créée au paiement avec SON groupe, donc le groupe faux), et les entrées
 * d'audit `Client`, `Consentement`, `Famille`, `Beneficiaire`. Le client est déplacé en DERNIER : les
 * requêtes précédentes le désignent encore par son écart.
 *
 * Irréversible : l'ancien rattachement était faux, et le restaurer rendrait ces fiches de nouveau lisibles
 * par un autre client.
 */
final class Version20261003235655 extends AbstractMigration
{
    private const REPRIS = '(SELECT p.client_resolu AS client_id, MIN(p.etablissement_id) AS etablissement_id, r.groupe_id
        FROM bou_panier p
        JOIN crm_client c ON c.id = p.client_resolu
        JOIN org_etablissement e ON e.id = p.etablissement_id
        JOIN org_region r ON r.id = e.region_id
        WHERE NOT EXISTS (SELECT 1 FROM bou_compte_client cc WHERE cc.client_id = p.client_resolu)
          AND r.groupe_id IS NOT NULL
        GROUP BY p.client_resolu, r.groupe_id
        HAVING MIN(p.etablissement_id) = MAX(p.etablissement_id)
           AND SUM(c.etablissement_creation_id <> p.etablissement_id) > 0
           AND COUNT(DISTINCT r.groupe_id) = 1)';

    public function getDescription(): string
    {
        return 'Clients nés de la boutique : rattachés à l’établissement de leur vitrine (famille et audit compris)';
    }

    public function up(Schema $schema): void
    {
        $repris = self::REPRIS;
        $this->addSql("UPDATE audit_entree a JOIN $repris x ON x.client_id = UNHEX(REPLACE(a.cible_id, '-', ''))
            SET a.etablissement = x.etablissement_id
            WHERE a.cible_type = 'App\\\\Crm\\\\Entity\\\\Client'");
        $this->addSql("UPDATE audit_entree a
            JOIN crm_consentement k ON k.id = UNHEX(REPLACE(a.cible_id, '-', ''))
            JOIN $repris x ON x.client_id = k.client_id
            SET a.etablissement = x.etablissement_id
            WHERE a.cible_type = 'App\\\\Crm\\\\Entity\\\\Consentement'");
        $this->addSql("UPDATE audit_entree a
            JOIN crm_famille f ON f.id = UNHEX(REPLACE(a.cible_id, '-', ''))
            JOIN $repris x ON x.client_id = f.payeur_principal_id
            SET a.etablissement = x.etablissement_id
            WHERE a.cible_type = 'App\\\\Crm\\\\Entity\\\\Famille'");
        $this->addSql("UPDATE audit_entree a
            JOIN crm_beneficiaire b ON b.id = UNHEX(REPLACE(a.cible_id, '-', ''))
            LEFT JOIN crm_famille f ON f.id = b.famille_id
            JOIN $repris x ON x.client_id = COALESCE(b.client_id, f.payeur_principal_id)
            SET a.etablissement = x.etablissement_id
            WHERE a.cible_type = 'App\\\\Crm\\\\Entity\\\\Beneficiaire'");
        $this->addSql("UPDATE crm_famille f JOIN $repris x ON x.client_id = f.payeur_principal_id
            SET f.groupe_id = x.groupe_id");
        $this->addSql("UPDATE crm_client c JOIN $repris x ON x.client_id = c.id
            SET c.etablissement_creation_id = x.etablissement_id, c.groupe_id = x.groupe_id");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('L’ancien rattachement était faux : le restaurer exposerait ces clients à un autre groupe.');
    }
}
