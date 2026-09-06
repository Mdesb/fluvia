<?php

declare(strict_types=1);

namespace App\Crm\Security;

use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Organisation\Entity\Groupe;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « CET UTILISATEUR PEUT-IL NOMMER CE CLIENT ? » — la règle de `CustomerScope`, posée sur UN client.
 *
 * `CustomerScope` restreint une REQUÊTE : un utilisateur voit un client dès qu'il a une affectation
 * dans le groupe du client (affectation → établissement → région → groupe). Elle protège les lectures
 * de collection et les fournisseurs qui l'appellent. Elle ne protège pas un processeur qui fait
 * `find($corps['client'])` : celui-là tient l'entité en main avant que quiconque ait posé la question.
 * Audit du 06/09, constat 5 — un mandat SEPA se créait sur le client d'un autre groupe, une fusion
 * destructive absorbait les clients d'un concurrent.
 *
 * ⚠ C'EST LA MÊME RÈGLE, ET ELLE DOIT LE RESTER. `CustomerScope` avertit qu'une copie d'une règle de
 * cloisonnement est « une seconde politique de sécurité que personne ne maintient ». Celle-ci n'est pas
 * une copie : c'est la même clause (affectation dans le groupe), sur un sujet au lieu d'une requête.
 * Le jour où la règle change là-bas, elle change ici, et le test de cloisonnement de l'un doit tomber
 * avec l'autre.
 *
 * **404 et non 403** (D3), comme `EstablishmentScopeAsserter`.
 */
final class CustomerReachability
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function canReach(Utilisateur $user, Client|Famille $subject): bool
    {
        $groupe = $subject->getGroupe();
        if (!$groupe instanceof Groupe) {
            // Un client sans groupe est invisible de tous dans `CustomerScope` : même réponse ici.
            return false;
        }

        $affectations = (int) $this->em->createQueryBuilder()
            ->select('COUNT(aff.id)')
            ->from(Affectation::class, 'aff')
            ->innerJoin('aff.etablissement', 'etb')
            ->innerJoin('etb.region', 'reg')
            ->andWhere('IDENTITY(aff.utilisateur) = :utilisateur')
            ->andWhere('IDENTITY(reg.groupe) = :groupe')
            // Type `uuid` explicite (D58) : sans lui, la comparaison ne compte rien et ne lève pas.
            ->setParameter('utilisateur', $user->getId(), 'uuid')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $affectations > 0;
    }

    /**
     * @throws NotFoundHttpException si le sujet est hors de portée de l'appelant, ou sans appelant
     */
    public function assertReachable(Client|Famille $subject): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur || !$this->canReach($user, $subject)) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
    }
}
