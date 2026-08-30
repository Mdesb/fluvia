<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Entity\BlockingExemption;
use App\Recouvrement\Port\BlockingExemptionLookup;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le seul endroit qui sait si un redevable est exempté de blocage — et le seul qui en pose une.
 *
 * ── POURQUOI UN SERVICE PLUTÔT QU'UNE REQUÊTE À DEUX ENDROITS ──────────────────────────────────
 *
 * L'exemption est consultée à **deux moments distincts**, et il faut les deux :
 *
 *   · quand la porte se ferme (`PropagationAccesHandler::appliquer`), sinon un exempté serait bloqué
 *     par le prochain incident ;
 *   · quand elle se rouvre (`PropagationAccesHandler::reevaluer`), sinon exempter quelqu'un déjà
 *     bloqué ne rouvrirait rien — et l'exemption ne servirait qu'aux impayés futurs, ce qui n'est
 *     pas ce qu'on a demandé.
 *
 * Deux moments, une seule règle. Écrire la requête aux deux endroits la ferait diverger au premier
 * correctif — c'est arrivé assez souvent dans ce dépôt pour qu'on ne recommence pas.
 *
 * ── ⚠ L'UNICITÉ EST TENUE ICI PARCE QU'ELLE NE PEUT PAS L'ÊTRE EN BASE ────────────────────────
 *
 * « Une seule exemption ACTIVE par redevable et par établissement » est un index **partiel** (sur
 * `revoked_at IS NULL`), que MariaDB ne connaît pas. On ne peut donc pas déléguer la règle au
 * schéma. Elle est ici, en un seul point, et `accorder()` refuse en 409 plutôt que d'empiler des
 * exemptions actives dont on ne saurait plus laquelle porte le vrai motif.
 */
final class BlockingExemptionRegistry implements BlockingExemptionLookup
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Ce redevable est-il actuellement exempté de tout blocage ?
     *
     * ⚠ La comparaison ne porte PAS sur l'établissement, alors que l'exemption en porte un.
     *
     * `PropagationAccesHandler` travaille sur le couple `typeRedevable`/`referenceRedevable` seul :
     * l'établissement n'y est connu qu'APRÈS résolution du droit d'accès, et il peut être nul. Exiger
     * ici un établissement qu'on n'a pas rendrait la garde muette — c'est-à-dire ouvrirait la porte
     * du blocage à un exempté, en silence.
     *
     * L'établissement reste porté par la ligne pour le cloisonnement de lecture et pour savoir QUI a
     * accordé l'exemption. Il n'entre pas dans la décision de blocage, qui est une propriété de la
     * personne.
     */
    public function estExempte(string $typeRedevable, string $referenceRedevable): bool
    {
        return null !== $this->exemptionActive($typeRedevable, $referenceRedevable);
    }

    public function exemptionActive(string $typeRedevable, string $referenceRedevable): ?BlockingExemption
    {
        /** @var ?BlockingExemption $exemption */
        $exemption = $this->em->createQueryBuilder()
            ->select('e')
            ->from(BlockingExemption::class, 'e')
            ->andWhere('e.debtorType = :type')
            ->andWhere('e.debtorRef = :reference')
            ->andWhere('e.revokedAt IS NULL')
            ->setParameter('type', $typeRedevable)
            ->setParameter('reference', $referenceRedevable)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $exemption;
    }

    /**
     * Accorde une exemption durable. Le motif est obligatoire — c'est la seule garde.
     *
     * @throws ConflictHttpException si une exemption active existe déjà pour ce redevable
     */
    public function accorder(
        string $typeRedevable,
        string $referenceRedevable,
        string $motif,
        Etablissement $etablissement,
        ?Utilisateur $agent,
    ): BlockingExemption {
        if (trim($motif) === '') {
            throw new \InvalidArgumentException("Le motif d'une exemption durable est obligatoire.");
        }

        if ($this->exemptionActive($typeRedevable, $referenceRedevable) !== null) {
            throw new ConflictHttpException('Ce redevable est déjà exempté : retirez l’exemption en cours avant d’en poser une autre.');
        }

        $exemption = (new BlockingExemption())
            ->setDebtorType($typeRedevable)
            ->setDebtorRef($referenceRedevable)
            ->setReason($motif)
            ->setEtablissement($etablissement)
            ->setGrantedBy($agent);

        $this->em->persist($exemption);
        $this->em->flush();

        return $exemption;
    }

    /** Retire l'exemption sans effacer la ligne : on doit pouvoir relire qui avait exempté, et pourquoi. */
    public function retirer(BlockingExemption $exemption, ?Utilisateur $agent): BlockingExemption
    {
        $exemption->revoke($agent);
        $this->em->flush();

        return $exemption;
    }
}
