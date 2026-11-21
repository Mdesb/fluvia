<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Entity\ProfilExploitant;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LE profil exploitant qui encaisse pour un établissement (RG-M6-01) : source unique du tunnel de paiement
 * en ligne (initiation, vérification, notification). Remplace les deux `resoudreProfil()` qui faisaient
 * `findAll()` puis `couvre()` — une lecture de TOUS les profils, dans un ordre non défini.
 *
 * Une seule requête ciblée. Si plusieurs profils couvrent l'établissement, le choix est **déterministe** :
 * le profil dont il est le principal d'abord, puis le plus petit identifiant (aucun « hasard » d'ordre de
 * lecture, ce qui désignerait selon le moment le compte Stripe de l'un ou de l'autre).
 */
final class OnlinePaymentProfileResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @throws UnprocessableEntityHttpException 422 si aucun profil ne couvre l'établissement */
    public function forEstablishment(?Etablissement $establishment): ProfilExploitant
    {
        if ($establishment !== null) {
            $profile = $this->em->createQuery(
                'SELECT p, CASE WHEN IDENTITY(p.etablissementPrincipal) = :principal THEN 0 ELSE 1 END AS HIDDEN rank'
                . ' FROM ' . ProfilExploitant::class . ' p'
                . ' WHERE IDENTITY(p.etablissementPrincipal) = :principal OR :covered MEMBER OF p.etablissementsRattaches'
                . ' ORDER BY rank ASC, p.id ASC'
            )
                ->setParameter('principal', $establishment->getId(), 'uuid')
                ->setParameter('covered', $establishment->getId(), 'uuid')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($profile instanceof ProfilExploitant) {
                return $profile;
            }
        }

        throw new UnprocessableEntityHttpException('Aucun profil exploitant (M6) configuré pour cet établissement (RG-M6-01).');
    }
}
