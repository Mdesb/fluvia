<?php

declare(strict_types=1);

namespace App\Offre\Doctrine;

use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\PrixHistorique;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Historisation append-only des prix (US-L1-03 / CA-5) : crée une PrixHistorique à la création
 * d'une grille avec prix et à chaque modification de GrilleTarifaire.prix. La valeur passée est
 * conservée pour l'audit ; la modification n'est pas rétroactive sur les commandes déjà passées
 * (M2). Insérée dans la même transaction (onFlush).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class HistorisationPrixHandler
{
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
            if ($entity instanceof GrilleTarifaire && $entity->getPrix() !== null) {
                $entrees[] = $this->creerEntree($entity, $entity->getPrix(), $auteur);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof GrilleTarifaire) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['prix'])) {
                continue;
            }
            $entrees[] = $this->creerEntree($entity, $entity->getPrix(), $auteur);
        }

        if ($entrees === []) {
            return;
        }

        $metadata = $em->getClassMetadata(PrixHistorique::class);
        foreach ($entrees as $entree) {
            $em->persist($entree);
            $uow->computeChangeSet($metadata, $entree);
        }
    }

    private function creerEntree(GrilleTarifaire $grille, ?string $valeur, ?string $auteur): PrixHistorique
    {
        $entree = new PrixHistorique();
        $entree->setGrille($grille);
        $entree->setValeur($valeur);
        $entree->setAuteur($auteur);

        return $entree;
    }

    private function auteurCourant(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
