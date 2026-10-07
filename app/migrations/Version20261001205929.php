<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rattrapage du journal d'audit : rattache à leur établissement les entrées écrites SANS (01/10/2026).
 *
 * Jusqu'ici, l'audit ne posait d'établissement qu'aux entités exposant `getEtablissement()`. Les entrées
 * des classes ci-dessous en étaient privées, donc lisibles de tous les clients. Le correctif
 * (`AuditEstablishmentResolver`) rattache les nouvelles ; cette migration reprend les anciennes par les
 * MÊMES chemins, traduits en SQL depuis le mapping. Une entrée dont la cible a disparu reste sans
 * établissement : elle n'est plus lue que par l'éditeur (`ResidualScopeExtension`).
 *
 * down() : avant le correctif, ces classes n'avaient JAMAIS d'établissement à l'écriture — et aucune
 * écriture manuelle (`JournalAudit::enregistrer`) n'emploie ces types. Remettre leurs entrées sans
 * établissement reproduit donc exactement l'état antérieur, sans table de sauvegarde hors mapping.
 */
final class Version20261001205929 extends AbstractMigration
{
    private const TYPES = [
        'App\\Acces\\Entity\\ListeRevocation',
        'App\\Caisse\\Entity\\ClotureZ',
        'App\\Caisse\\Entity\\MouvementCaisse',
        'App\\Compta\\Entity\\BordereauVersement',
        'App\\Compta\\Entity\\QualificationEquipement',
        'App\\Compta\\Entity\\VenteImpayeeRegie',
        'App\\Crm\\Entity\\Beneficiaire',
        'App\\Crm\\Entity\\Client',
        'App\\Crm\\Entity\\Consentement',
        'App\\Crm\\Entity\\DemandeRGPD',
        'App\\Crm\\Entity\\Famille',
        'App\\Crm\\Entity\\JournalFusion',
        'App\\Crm\\Entity\\PorteMonnaieVirtuel',
        'App\\Membership\\Entity\\Resiliation',
        'App\\Offre\\Entity\\GrilleTarifaire',
        'App\\Piscine\\Entity\\ForcageCasier',
        'App\\Sport\\Entity\\EvenementSOS',
        'App\\Stock\\Entity\\TransfertStock',
        'App\\Vente\\Entity\\Paiement',
        'App\\Vente\\Nf525\\Entity\\OperationScellee',
    ];

    public function getDescription(): string
    {
        return 'Audit : rattache à leur établissement les entrées écrites sans (fuite entre clients)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE audit_entree a JOIN crm_client t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) SET a.etablissement = t0.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\Client\' AND t0.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_famille t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_client t1 ON t1.id = t0.payeur_principal_id SET a.etablissement = t1.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\Famille\' AND t1.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_beneficiaire t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_client t1 ON t1.id = t0.client_id SET a.etablissement = t1.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\Beneficiaire\' AND t1.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_beneficiaire t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_famille t1 ON t1.id = t0.famille_id JOIN crm_client t2 ON t2.id = t1.payeur_principal_id SET a.etablissement = t2.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\Beneficiaire\' AND t2.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_pmv t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_client t1 ON t1.id = t0.client_id SET a.etablissement = t1.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\PorteMonnaieVirtuel\' AND t1.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_consentement t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_client t1 ON t1.id = t0.client_id SET a.etablissement = t1.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\Consentement\' AND t1.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_demande_rgpd t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN crm_client t1 ON t1.id = t0.client_id SET a.etablissement = t1.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\DemandeRGPD\' AND t1.etablissement_creation_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN vente_paiement t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN vente_vente t1 ON t1.id = t0.vente_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Vente\\\\Entity\\\\Paiement\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN nf525_operation_scellee t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN caisse_point_de_vente t1 ON t1.id = t0.point_de_vente_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Vente\\\\Nf525\\\\Entity\\\\OperationScellee\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN caisse_cloture_z t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN caisse_session t1 ON t1.id = t0.session_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Caisse\\\\Entity\\\\ClotureZ\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN caisse_mouvement t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN caisse_session t1 ON t1.id = t0.session_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Caisse\\\\Entity\\\\MouvementCaisse\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN compta_bordereau_versement t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN compta_regie_recettes t1 ON t1.id = t0.regie_id JOIN compta_profil_exploitant t2 ON t2.id = t1.profil_exploitant_id SET a.etablissement = t2.etablissement_principal_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Compta\\\\Entity\\\\BordereauVersement\' AND t2.etablissement_principal_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN compta_qualification_equipement t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN org_espace t1 ON t1.id = t0.espace_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Compta\\\\Entity\\\\QualificationEquipement\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN sport_resiliation t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN sport_abonnement_fitness t1 ON t1.id = t0.abonnement_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Membership\\\\Entity\\\\Resiliation\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN off_grille_tarifaire t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN off_saison t1 ON t1.id = t0.saison_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Offre\\\\Entity\\\\GrilleTarifaire\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN acces_liste_revocation t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN acces_controleur t1 ON t1.id = t0.controleur_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Acces\\\\Entity\\\\ListeRevocation\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN piscine_forcage_casier t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN piscine_casier t1 ON t1.id = t0.casier_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Piscine\\\\Entity\\\\ForcageCasier\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN sport_evenement_sos t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN acces_espace_acces t1 ON t1.id = t0.espace_acces_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Sport\\\\Entity\\\\EvenementSOS\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN stk_transfert t0 ON t0.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\')) JOIN stk_article t1 ON t1.id = t0.article_stock_source_id SET a.etablissement = t1.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Stock\\\\Entity\\\\TransfertStock\' AND t1.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN vente_vente t0 ON t0.id = (SELECT s.vente_origine FROM compta_vente_impayee_regie s WHERE s.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\'))) SET a.etablissement = t0.etablissement_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Compta\\\\Entity\\\\VenteImpayeeRegie\' AND t0.etablissement_id IS NOT NULL');
        $this->addSql('UPDATE audit_entree a JOIN crm_client t0 ON t0.id = (SELECT s.fiche_survivante FROM crm_journal_fusion s WHERE s.id = UNHEX(REPLACE(a.cible_id, \'-\', \'\'))) SET a.etablissement = t0.etablissement_creation_id WHERE a.etablissement IS NULL AND a.cible_type = \'App\\\\Crm\\\\Entity\\\\JournalFusion\' AND t0.etablissement_creation_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE audit_entree SET etablissement = NULL WHERE cible_type IN (' . implode(', ', array_fill(0, \count(self::TYPES), '?')) . ')',
            self::TYPES,
        );
    }
}
