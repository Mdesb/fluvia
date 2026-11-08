<?php

declare(strict_types=1);

namespace App\Crm\Security;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LE FICHIER CLIENT SUR LEQUEL UN GESTE DE MASSE A LE DROIT D'AGIR : CELUI DE LA COLLECTIVITÉ
 * COURANTE, ET LUI SEUL.
 *
 * Le fichier client appartient au groupe (RG-SOCLE-05). Une lecture peut couvrir tous les groupes
 * où l'utilisateur est affecté (`CustomerScope`) ; un geste DESTRUCTEUR et EN MASSE — épurer,
 * exporter, régler une durée de conservation — se restreint, lui, au groupe de l'établissement
 * actif (en-tête `X-Etablissement`). Un administrateur affecté à deux collectivités n'épure jamais
 * les deux d'un clic : il choisit celle où il travaille.
 *
 * Deux conditions, toutes deux vérifiées ici, aucune supposée :
 *   1. un établissement actif existe et appartient à un groupe ;
 *   2. l'utilisateur a une affectation dans CE groupe (l'en-tête est une saisie du client : il ne
 *      prouve rien à lui seul).
 *
 * Refus d'une fiche hors groupe en 404, jamais en 403 : un 403 confirmerait qu'elle existe ailleurs.
 */
final class CustomerGroupGuard
{
    public function __construct(
        private readonly ContexteEtablissement $contexte,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function activeGroup(): Groupe
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new AccessDeniedHttpException('Aucun établissement actif : choisissez celui où vous travaillez.');
        }
        $groupe = $etablissement->getRegion()?->getGroupe();
        if (!$groupe instanceof Groupe) {
            throw new AccessDeniedHttpException("L'établissement actif n'est rattaché à aucun groupe.");
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedHttpException('Utilisateur non authentifié.');
        }

        $affectations = (int) $this->em->createQuery(
            'SELECT COUNT(aff.id) FROM ' . Affectation::class . ' aff '
            . 'INNER JOIN ' . Etablissement::class . ' etb WITH etb = aff.etablissement '
            . 'INNER JOIN ' . Region::class . ' reg WITH reg = etb.region '
            . 'WHERE IDENTITY(aff.utilisateur) = :utilisateur AND IDENTITY(reg.groupe) = :groupe'
        )
            ->setParameter('utilisateur', $utilisateur->getId(), 'uuid')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->getSingleScalarResult();
        if ($affectations === 0) {
            throw new AccessDeniedHttpException("Vous n'êtes affecté à aucun établissement de cette collectivité.");
        }

        return $groupe;
    }

    /** La fiche appartient-elle à la collectivité courante ? 404 sinon. */
    public function assertInActiveGroup(Client $client): void
    {
        $groupe = $this->activeGroup();
        $groupeDuClient = $client->getGroupe();
        if ($groupeDuClient === null || !$groupeDuClient->getId()->equals($groupe->getId())) {
            throw new NotFoundHttpException('Client introuvable.');
        }
    }
}
