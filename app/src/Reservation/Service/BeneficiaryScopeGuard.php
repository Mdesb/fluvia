<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Résout un `Beneficiaire` désigné par le client **en le confrontant au périmètre** (D3/D8).
 *
 * Les points d'entrée de réservation acceptent un bénéficiaire dans le corps de la requête —
 * organisateur d'une réservation, participant, inscrit en liste d'attente — et le résolvaient par un
 * `find()` sec. Rien n'empêchait donc de désigner le bénéficiaire de quelqu'un d'autre : la fiche
 * apparaît ensuite dans la réservation, avec son identité et sa part de paiement.
 *
 * **La règle appliquée est celle de `PerimetreCrmExtension`, pas une invention** : le groupe du
 * client porteur doit être celui d'une région d'un établissement où l'utilisateur possède une
 * affectation. C'est exactement ce que la lecture CRM autorise déjà — plus strict refuserait des cas
 * légitimes, plus laxiste laisserait le trou ouvert.
 *
 * **Cela n'empêche pas le libre-service.** Un utilisateur d'espace client porte lui aussi une
 * affectation (vérifié dans les fixtures : `Affectation` sur l'établissement A pour le rôle client) ;
 * la restriction ne coupe donc pas l'accès d'un client à ses propres bénéficiaires. Le contrôle
 * `reserver_soi`, qui compare le client lié à celui du bénéficiaire, reste par-dessus : l'un borne
 * au groupe, l'autre à la fiche.
 */
final class BeneficiaryScopeGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @return Beneficiaire|null null si inconnu **ou** hors périmètre — les deux cas sont indiscernables, volontairement */
    public function find(Uuid $id): ?Beneficiaire
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return null;
        }

        $sousRequete = 'SELECT aff_sbf.id FROM ' . Affectation::class . ' aff_sbf '
            . 'INNER JOIN ' . Etablissement::class . ' etb_sbf WITH etb_sbf = aff_sbf.etablissement '
            . 'INNER JOIN ' . Region::class . ' reg_sbf WITH reg_sbf = etb_sbf.region '
            . 'WHERE IDENTITY(aff_sbf.utilisateur) = :sbf_utilisateur '
            . 'AND IDENTITY(reg_sbf.groupe) = IDENTITY(client_sbf.groupe)';

        /** @var Beneficiaire|null $beneficiaire */
        $beneficiaire = $this->em->getRepository(Beneficiaire::class)->createQueryBuilder('b')
            ->innerJoin('b.client', 'client_sbf')
            ->andWhere('b.id = :sbf_beneficiaire')
            ->andWhere('EXISTS (' . $sousRequete . ')')
            ->setParameter('sbf_beneficiaire', $id, 'uuid')
            ->setParameter('sbf_utilisateur', $utilisateur->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $beneficiaire;
    }
}
