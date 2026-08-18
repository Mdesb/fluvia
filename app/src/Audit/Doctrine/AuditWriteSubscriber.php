<?php

declare(strict_types=1);

namespace App\Audit\Doctrine;

use App\Audit\Entity\EntreeAudit;
use App\Audit\Service\InstantaneEntiteBuilder;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Journalise automatiquement les créations/modifications/suppressions des entités sensibles
 * (RG-SOCLE-07, CA-6). Les entrées sont insérées dans la même transaction (onFlush).
 * L'entité EntreeAudit est append-only : elle n'est jamais elle-même auditée.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditWriteSubscriber
{
    /** @var list<class-string> */
    private const CLASSES_SURVEILLEES = [
        \App\Organisation\Entity\Groupe::class,
        \App\Organisation\Entity\Region::class,
        Etablissement::class,
        \App\Organisation\Entity\Espace::class,
        Utilisateur::class,
        \App\Securite\Entity\Role::class,
        \App\Securite\Entity\Permission::class,
        \App\Securite\Entity\Affectation::class,
        // Module M1 Offre & Tarification (L1) : traçabilité des actions sensibles sur l'offre.
        \App\Offre\Entity\Produit::class,
        \App\Offre\Entity\GrilleTarifaire::class,
        \App\Offre\Entity\TypeTarif::class,
        \App\Offre\Entity\Saison::class,
        \App\Offre\Entity\Categorie::class,
        \App\Offre\Entity\Promotion::class,
        // Module M2 Vente & Caisse (L2) : entités sensibles (régie, encaissement, NF525).
        \App\Caisse\Entity\SessionCaisse::class,
        \App\Caisse\Entity\MouvementCaisse::class,
        \App\Caisse\Entity\ClotureZ::class,
        \App\Vente\Entity\Vente::class,
        \App\Vente\Entity\Paiement::class,
        \App\Vente\Entity\Avoir::class,
        \App\Vente\Nf525\Entity\OperationScellee::class,
        // Module L3 Contrôle d'accès : traçabilité des actions sensibles (topologie, appairage,
        // blocage support, journal des passages).
        \App\Acces\Entity\EspaceAcces::class,
        \App\Acces\Entity\Controleur::class,
        \App\Acces\Entity\Equipement::class,
        \App\Acces\Entity\Support::class,
        \App\Acces\Entity\Appairage::class,
        \App\Acces\Entity\Passage::class,
        \App\Acces\Entity\DeclarationPerteVol::class,
        \App\Acces\Entity\ListeRevocation::class,
        \App\Acces\Entity\SousReseau::class,
        // Module M6 Comptabilité & Régie (L4) : entités sensibles (profil exploitant, plan de comptes,
        // écritures scellées NF525, régie de recettes, exports, clôture).
        \App\Compta\Entity\ProfilExploitant::class,
        \App\Compta\Entity\QualificationEquipement::class,
        \App\Compta\Entity\CompteComptable::class,
        \App\Compta\Entity\MappingComptable::class,
        \App\Compta\Entity\EcritureComptable::class,
        \App\Compta\Entity\PeriodeComptable::class,
        \App\Compta\Entity\RegieRecettes::class,
        \App\Compta\Entity\BordereauVersement::class,
        \App\Compta\Entity\ExportComptable::class,
        \App\Compta\Entity\DeclarationEReporting::class,
        \App\Compta\Entity\VenteImpayeeRegie::class,
        // Module M4 CRM noyau (L5) : entités sensibles (données personnelles, PMV, RGPD, fusion).
        \App\Crm\Entity\Client::class,
        \App\Crm\Entity\Famille::class,
        \App\Crm\Entity\Beneficiaire::class,
        \App\Crm\Entity\PorteMonnaieVirtuel::class,
        \App\Crm\Entity\MouvementPmv::class,
        \App\Crm\Entity\ParametrePmvEtablissement::class,
        \App\Crm\Entity\Consentement::class,
        \App\Crm\Entity\DemandeRGPD::class,
        \App\Crm\Entity\RegleConservation::class,
        \App\Crm\Entity\JournalFusion::class,
        // Verticale Piscine (L6) : entités sensibles (POSS, bassins, créneaux, casiers/caution,
        // forçage, qualifications MNS/BNSSA) — plan-piscine.md §5.
        \App\Piscine\Entity\Poss::class,
        \App\Piscine\Entity\Bassin::class,
        \App\Piscine\Entity\CreneauBassin::class,
        \App\Piscine\Entity\Casier::class,
        \App\Piscine\Entity\CautionCasier::class,
        \App\Piscine\Entity\ForcageCasier::class,
        \App\Piscine\Entity\QualificationEncadrant::class,
        // L7 Back-office & Droits : délégations temporaires de droits (RG-M8-05, §8 plan-backoffice.md).
        \App\Securite\Entity\DelegationDroit::class,
        // Verticale Sport/Fitness : entités sensibles (abonnement, résiliation, événements SOS) —
        // plan-sport.md §5, T15. La politique/les incidents anti-impayés ont été extraits vers le
        // moteur de recouvrement partagé (§ ci-dessous, refactor extraction).
        \App\Sport\Entity\AbonnementFitness::class,
        \App\Sport\Entity\Resiliation::class,
        \App\Sport\Entity\EvenementSOS::class,
        // Module SEPA partagé (plan-sepa.md §2) : mandat, configuration créancier, remise — IBAN
        // (jetons) exclus de l'instantané (CHAMPS_SENSIBLES ci-dessous, §4 spec).
        \App\Sepa\Entity\MandatSepa::class,
        \App\Sepa\Entity\ConfigCreancierSepa::class,
        \App\Sepa\Entity\RemiseSepa::class,
        // Module de recouvrement partagé App\Recouvrement (refactor extraction depuis App\Sport,
        // réutilisable par toute activité à abonnement) : politique et dossiers d'impayés, sensibles
        // (déclenchent une coupure d'accès).
        \App\Recouvrement\Entity\PolitiqueRecouvrement::class,
        \App\Recouvrement\Entity\IncidentImpaye::class,
        // Profil de fonctionnalités par établissement (App\Fonctionnalite) : traçabilité de
        // l'activation/désactivation/paramétrage des capacités par établissement.
        \App\Fonctionnalite\Entity\FonctionnaliteEtablissement::class,
        // Verticale Padel (plan-padel.md §4) : niveau de jeu (validation club), tournois, caution
        // matériel, relais d'éclairage — traçabilité des actions sensibles (RG-SOCLE-07).
        \App\Padel\Entity\NiveauJoueur::class,
        \App\Padel\Entity\Tournoi::class,
        \App\Padel\Entity\CautionMateriel::class,
        \App\Padel\Entity\RelaisEclairageTerrain::class,
        // Verticale Musée (plan-musee.md §4, T12) : sous-quota de salle, dossiers groupes/scolaires
        // et gratuités, partenaires/allocations OTA, pass annuel — traçabilité RG-SOCLE-07.
        \App\Musee\Entity\Salle::class,
        \App\Musee\Entity\SousQuotaSalle::class,
        \App\Musee\Entity\DossierGroupeScolaire::class,
        \App\Musee\Entity\Gratuite::class,
        \App\Musee\Entity\PartenaireOTA::class,
        \App\Musee\Entity\AllocationQuotaOTA::class,
        \App\Musee\Entity\PassAnnuel::class,
        // Module M3 Boutique en ligne (L8, plan-boutique.md T14) : entités sensibles (compte client
        // final, demandes de remboursement, retraits click & collect, partenaires OTA génériques).
        \App\Boutique\Entity\CompteClient::class,
        \App\Boutique\Entity\DemandeRemboursement::class,
        \App\Boutique\Entity\RetraitClickCollect::class,
        \App\Boutique\Entity\PartenaireOTA::class,
        // Module Stock & Inventaire boutique (plan-stock.md §6, T16) : entités sensibles (fournisseurs,
        // commandes/réceptions d'achat, couches de coût, mouvements append-only, transferts,
        // inventaires) — traçabilité RG-SOCLE-07/RG-STOCK-15.
        \App\Stock\Entity\Fournisseur::class,
        \App\Stock\Entity\CommandeAchat::class,
        \App\Stock\Entity\ReceptionAchat::class,
        \App\Stock\Entity\LotStock::class,
        \App\Stock\Entity\MouvementStock::class,
        \App\Stock\Entity\TransfertStock::class,
        \App\Stock\Entity\Inventaire::class,
        // Module Base de connaissance & Support (plan-support.md §6, RG-SUP-15) : publication/
        // archivage d'article, cycle de vie/escalade/réaffectation de ticket — traçabilité des
        // actions sensibles. `CategorieAide`/`VersionArticle`/`MessageTicket` volontairement exclus
        // (volumétrie/bruit, cf. plan §6).
        \App\Support\Entity\ArticleAide::class,
        \App\Support\Entity\TicketSupport::class,
        // Module Autorisations graduées (App\Autorisation) : traçabilité de la configuration des
        // plafonds/périmètres et du catalogue des opérations sensibles (défaut majeur revue de
        // cohérence — la config n'était pas auditée alors que ce mécanisme protège des opérations
        // sensibles, RG-SOCLE-07).
        \App\Autorisation\Entity\LimiteAutorisation::class,
        \App\Autorisation\Entity\OperationSensible::class,
    ];

    /**
     * Champs sensibles JAMAIS exposés en clair dans l'audit avant/après (RG-M8-05, §2.10/§1.6 plan).
     *
     * @var array<class-string, list<string>>
     */
    private const CHAMPS_SENSIBLES = [
        Utilisateur::class => ['motDePasse', 'jetonInvitation', 'mfaSecret', 'mfaCodesRecuperation'],
        // IBAN — garde de sécurité applicative (spec §4, plan-sepa.md §2) : jamais dans l'instantané
        // d'audit, même si le champ n'a pas de #[Groups] (l'audit ne sérialise pas via le normalizer).
        \App\Sepa\Entity\MandatSepa::class => ['ibanToken'],
        \App\Sepa\Entity\ConfigCreancierSepa::class => ['creancierIbanToken'],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly InstantaneEntiteBuilder $instantane,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $auteur = $this->auteurCourant();

        $entrees = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'creation', $auteur, null);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'modification', $auteur, $uow->getEntityChangeSet($entity));
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'suppression', $auteur, null);
        }

        $entrees = array_filter($entrees);
        if ($entrees === []) {
            return;
        }

        $metadata = $em->getClassMetadata(EntreeAudit::class);
        foreach ($entrees as $entree) {
            $em->persist($entree);
            $uow->computeChangeSet($metadata, $entree);
        }
    }

    /** @param array<string, array{0: mixed, 1: mixed}>|null $changeSet */
    private function creerEntree(object $entity, string $action, ?string $auteur, ?array $changeSet): ?EntreeAudit
    {
        if ($entity instanceof EntreeAudit) {
            return null;
        }
        if (!\in_array($entity::class, self::CLASSES_SURVEILLEES, true)) {
            return null;
        }

        $champsExclus = self::CHAMPS_SENSIBLES[$entity::class] ?? [];

        $entree = new EntreeAudit();
        $entree->setAction($action);
        $entree->setCibleType($entity::class);
        $entree->setCibleId($this->cibleId($entity));
        $entree->setEtablissement($this->etablissement($entity));
        $entree->setAuteur($auteur);

        // RG-M8-05 (CA-14) : valeurs avant/après (champs scalaires uniquement, sensibles exclus).
        if ($action === 'creation') {
            $entree->setValeurAvant(null);
            $entree->setValeurApres($this->instantane->capturer($entity, $champsExclus));
        } elseif ($action === 'suppression') {
            $entree->setValeurAvant($this->instantane->capturer($entity, $champsExclus));
            $entree->setValeurApres(null);
        } elseif ($changeSet !== null) {
            [$avant, $apres] = $this->instantane->depuisChangeSet($changeSet, $champsExclus);
            $entree->setValeurAvant($avant);
            $entree->setValeurApres($apres);
        }

        return $entree;
    }

    private function cibleId(object $entity): ?string
    {
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();

            return $id === null ? null : (string) $id;
        }

        return null;
    }

    private function etablissement(object $entity): ?Uuid
    {
        if ($entity instanceof Etablissement) {
            return $entity->getId();
        }
        if (method_exists($entity, 'getEtablissement')) {
            $etab = $entity->getEtablissement();
            if ($etab instanceof Etablissement) {
                return $etab->getId();
            }
        }

        return null;
    }

    private function auteurCourant(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
