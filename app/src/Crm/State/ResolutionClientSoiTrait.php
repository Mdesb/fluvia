<?php

declare(strict_types=1);

namespace App\Crm\State;

use App\Organisation\Entity\Etablissement;

use App\Organisation\Entity\Region;

use App\Securite\Entity\Affectation;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Crm\Entity\Client;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contrôle impératif « soi-même » (permissions `crm.*_soi`) pour les Providers renvoyant une
 * `JsonResponse` (agrégation) plutôt que l'entité `Client` elle-même — l'expression `security`
 * déclarative d'API Platform ne peut pas y référencer `object.estLieA(user)` (§6/§10.8 plan-crm.md).
 * Nécessite un service `Symfony\Bundle\SecurityBundle\Security $security` sur la classe hôte.
 */
trait ResolutionClientSoiTrait
{
    private function verifierAccesSoi(Client $client, string $permissionComplete, string $permissionSoi): void
    {
        /** @var \Symfony\Bundle\SecurityBundle\Security $security */
        $security = $this->security ?? null;
        if ($security === null) {
            return;
        }
        if ($security->isGranted('PERM', $permissionComplete)) {
            // D8 — la permission dit ce que l'utilisateur a le droit de faire, jamais **sur qui**.
            // Ce retour anticipe sortait sans avoir regardé `$client` une seule fois : un porteur de
            // `crm.pmv_lire` dans le groupe X lisait le solde, les mouvements et la fiche 360 d'un
            // client du groupe Y. `PerimetreCrmExtension` cloisonne pourtant bien `Client` — mais au
            // **groupe**, et seulement sur les requêtes API Platform : le `find()` direct des trois
            // Providers la court-circuite.
            //
            // Le CRM est volontairement cloisonné au groupe et non à l'établissement — un client est
            // partagé entre les établissements d'un même groupe. On reprend donc exactement le chemin
            // de l'extension : affectation → établissement → région → groupe.
            $this->assertMemeGroupeQueClient($client);

            return;
        }
        if ($security->isGranted('PERM', $permissionSoi)) {
            $utilisateur = $security->getUser();
            if ($utilisateur instanceof Utilisateur && $client->estLieA($utilisateur)) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Accès refusé à ce client.');
    }

    /**
     * Le client résolu appartient-il à un groupe où l'appelant est affecté ?
     *
     * Échec **fermé** : un client sans groupe est refusé plutôt qu'accordé. C'est le cas où une fuite
     * serait invisible, et une donnée incomplète ne doit pas valoir autorisation.
     *
     * Refus en 404 et non 403 : un 403 confirmerait l'existence du client dans un autre groupe, ce qui
     * ferait de la route un oracle d'énumération sur le fichier clients.
     */
    private function assertMemeGroupeQueClient(Client $client): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface|null $em */
        $em = $this->em ?? null;
        $security = $this->security ?? null;
        $utilisateur = $security?->getUser();

        if ($em === null || !$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        $groupe = $client->getGroupe();
        if ($groupe === null) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        $partage = (int) $em->createQuery(
            'SELECT COUNT(aff.id) FROM ' . Affectation::class . ' aff '
            . 'INNER JOIN ' . Etablissement::class . ' etb WITH etb = aff.etablissement '
            . 'INNER JOIN ' . Region::class . ' reg WITH reg = etb.region '
            . 'WHERE IDENTITY(aff.utilisateur) = :utilisateur AND IDENTITY(reg.groupe) = :groupe'
        )
            ->setParameter('utilisateur', $utilisateur->getId(), 'uuid')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->getSingleScalarResult();

        if ($partage === 0) {
            throw new NotFoundHttpException('Client introuvable.');
        }
    }
}
