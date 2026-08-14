<?php

declare(strict_types=1);

namespace App\Audit\Doctrine;

use App\Audit\Entity\EntreeAudit;
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
    ];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $auteur = $this->auteurCourant();

        $entrees = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'creation', $auteur);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'modification', $auteur);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $entrees[] = $this->creerEntree($entity, 'suppression', $auteur);
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

    private function creerEntree(object $entity, string $action, ?string $auteur): ?EntreeAudit
    {
        if ($entity instanceof EntreeAudit) {
            return null;
        }
        if (!\in_array($entity::class, self::CLASSES_SURVEILLEES, true)) {
            return null;
        }

        $entree = new EntreeAudit();
        $entree->setAction($action);
        $entree->setCibleType($entity::class);
        $entree->setCibleId($this->cibleId($entity));
        $entree->setEtablissement($this->etablissement($entity));
        $entree->setAuteur($auteur);

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
