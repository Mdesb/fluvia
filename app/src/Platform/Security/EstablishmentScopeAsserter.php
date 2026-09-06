<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\EstablishmentReachability;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LE GESTE QUE `EstablishmentScopeWriteGuard` NE PEUT PAS FAIRE — pour les processeurs écrits à la main.
 *
 * Le garde global décore le `persist_processor` d'API Platform : il voit ce que le pipeline standard
 * écrit, et rien d'autre. Un processeur qui résout lui-même une entité depuis un identifiant du corps —
 * `$this->em->getRepository(X::class)->find($corps['x'])` — puis la persiste par `$em->persist()`, passe
 * à côté de lui sans le savoir. Audit du 06/09, constat 5 : onze processeurs sur quarante et un lus
 * faisaient exactement cela. Les plus graves : un mandat SEPA créé sur l'établissement d'un autre
 * client, une session de caisse — donc une chaîne NF525 — ouverte sur le guichet d'un voisin.
 *
 * ⚠ L'ASSERTION VIT ICI ET NON DANS CHAQUE PROCESSEUR, pour la raison qui a fait naître le garde
 * global : la même décision recopiée douze fois est une décision qu'on oubliera la treizième. Un
 * processeur qui résout un établissement du corps écrit une ligne : `$this->scope->assertReachable($e)`.
 *
 * **404 et non 403** (D3) : un 403 confirmerait à l'appelant que l'établissement qu'il a désigné existe.
 *
 * **Sans utilisateur, 404 aussi.** Contrairement au garde global, qui laisse passer la boutique publique
 * (limite assumée de D41), les processeurs qui résolvent un établissement du CORPS sont tous
 * authentifiés et à droit fin. Un appel anonyme qui arriverait jusqu'ici serait déjà une anomalie ;
 * on ferme.
 */
final class EstablishmentScopeAsserter
{
    public function __construct(
        private readonly Security $security,
        private readonly EstablishmentReachability $reachability,
    ) {
    }

    public function isReachable(?Etablissement $establishment): bool
    {
        if (!$establishment instanceof Etablissement) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return false;
        }

        return $this->reachability->canReachEstablishment($user, $establishment, new \DateTimeImmutable());
    }

    /**
     * @throws NotFoundHttpException si l'établissement est absent ou hors de portée de l'appelant
     */
    public function assertReachable(?Etablissement $establishment): Etablissement
    {
        if (!$this->isReachable($establishment)) {
            // Le message ne nomme pas l'établissement : le donner reviendrait à confirmer son existence.
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        \assert($establishment instanceof Etablissement);

        return $establishment;
    }
}
