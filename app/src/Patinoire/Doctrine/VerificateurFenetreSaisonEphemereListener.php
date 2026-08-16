<?php

declare(strict_types=1);

namespace App\Patinoire\Doctrine;

use App\Patinoire\Entity\SaisonEphemere;
use App\Patinoire\Enum\BasculeSaisonEphemere;
use App\Vente\Entity\LigneVente;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Fermeture automatique de la vente hors fenêtre de saison éphémère (RG-PAT-04, plan §0 point 3 —
 * gap M1 signalé spec §4.9/§7/§8 point 9). Écoute `prePersist` de `App\Vente\Entity\LigneVente` :
 * rejette la création d'une ligne pour un produit du `catalogueAssocie` d'une `SaisonEphemere` en
 * bascule `automatique` dont la date courante est hors `[fenetreVenteDebut, fenetreVenteFin]`. Ne
 * modifie **aucun** fichier `App\Offre`/`App\Vente` — extension contenue dans `App\Patinoire`, même
 * patron additif que `App\Piscine\EventListener\PossModeSeuilGuard`.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class VerificateurFenetreSaisonEphemereListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof LigneVente) {
            return;
        }

        $em = $args->getObjectManager();
        $produit = (string) $entity->getProduit();

        /** @var list<SaisonEphemere> $saisons */
        $saisons = $em->getRepository(SaisonEphemere::class)->findBy(['actif' => true, 'bascule' => BasculeSaisonEphemere::Automatique->value]);

        $maintenant = new \DateTimeImmutable('today');
        foreach ($saisons as $saison) {
            if (!\in_array($produit, $saison->getCatalogueAssocie(), true)) {
                continue;
            }
            if (!$saison->fenetreVenteOuverteA($maintenant)) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'RG-PAT-04 : vente fermée automatiquement hors fenêtre de la saison éphémère « %s » (%s → %s).',
                    $saison->getLibelle(),
                    $saison->getFenetreVenteDebut()?->format('Y-m-d'),
                    $saison->getFenetreVenteFin()?->format('Y-m-d'),
                ));
            }
        }
    }
}
