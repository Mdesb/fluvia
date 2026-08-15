<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde-fou « dernier administrateur » (RG-M8-07, CA-11) : est considéré « administrateur d'un
 * périmètre » un utilisateur portant, sur un établissement, une `Affectation` à un `Role`
 * incluant `securite.gerer`. Toute opération qui ferait disparaître le dernier administrateur
 * d'un établissement est refusée (422).
 *
 * Verrou pessimiste sur l'établissement (cas limite spec §7 : deux suppressions concurrentes) —
 * sérialise les vérifications concurrentes pour le même périmètre.
 */
final class GardeDernierAdministrateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Vrai si le `Role` porte `securite.gerer`. */
    public function roleEstAdministrateur(Role $role): bool
    {
        foreach ($role->getPermissions() as $permission) {
            if ($permission->getCode() === 'securite.gerer') {
                return true;
            }
        }

        return false;
    }

    /** Nombre d'affectations « administrateur » actives sur cet établissement. */
    public function nombreAdministrateurs(Etablissement $etablissement): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT a.id)')
            ->from(Affectation::class, 'a')
            ->join('a.role', 'r')
            ->join('r.permissions', 'p')
            ->where('a.etablissement = :etablissement')
            ->andWhere("p.module = 'securite' AND p.action = 'gerer'")
            ->setParameter('etablissement', $etablissement->getId(), 'uuid');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Refuse (422) la suppression d'une `Affectation` administrateur si elle est la dernière de
     * son établissement.
     */
    public function verifierSuppressionAffectation(Affectation $affectation): void
    {
        $role = $affectation->getRole();
        $etablissement = $affectation->getEtablissement();
        if ($role === null || $etablissement === null || !$this->roleEstAdministrateur($role)) {
            return;
        }

        $this->verrouillerEtVerifier($etablissement, sprintf(
            "Impossible : dernier administrateur de l'établissement %s. Désignez un remplaçant avant de retirer ce droit.",
            $etablissement->getNom()
        ));
    }

    /**
     * Refuse (422) la suspension d'un utilisateur s'il est le seul administrateur d'au moins un
     * établissement.
     */
    public function verifierSuspension(Utilisateur $utilisateur): void
    {
        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $utilisateur]);
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            $etablissement = $affectation->getEtablissement();
            if ($role === null || $etablissement === null || !$this->roleEstAdministrateur($role)) {
                continue;
            }

            $this->verrouillerEtVerifier($etablissement, sprintf(
                "Impossible : %s est le dernier administrateur de l'établissement %s. Désignez un remplaçant avant de suspendre ce compte.",
                $utilisateur->getEmail(),
                $etablissement->getNom()
            ));
        }
    }

    /**
     * Refuse (422) le retrait de `securite.gerer` d'un `Role` (Patch) ou sa suppression (Delete)
     * si un établissement se retrouverait sans administrateur. `$avaitSecuriteGererAvant` doit
     * refléter l'état AVANT la modification en cours (ex. `PersistentCollection::getSnapshot()`),
     * `$conserveraSecuriteGererApres` l'état proposé.
     */
    public function verifierModificationRole(Role $role, bool $avaitSecuriteGererAvant, bool $conserveraSecuriteGererApres): void
    {
        if (!$avaitSecuriteGererAvant || $conserveraSecuriteGererApres) {
            return;
        }

        $affectations = $this->em->getRepository(Affectation::class)->findBy(['role' => $role]);
        foreach ($affectations as $affectation) {
            $etablissement = $affectation->getEtablissement();
            if ($etablissement === null) {
                continue;
            }

            $this->verrouillerEtVerifier($etablissement, sprintf(
                "Impossible : ce rôle est la seule source du droit d'administration de l'établissement %s. Désignez un remplaçant avant de retirer ce droit.",
                $etablissement->getNom()
            ));
        }
    }

    /**
     * Verrou pessimiste sur l'établissement, dans sa propre transaction courte (sérialise les
     * vérifications concurrentes, cas limite spec §7), puis vérifie qu'il reste au moins un
     * administrateur en dehors de l'affectation/l'utilisateur/le rôle en cours de retrait.
     */
    private function verrouillerEtVerifier(Etablissement $etablissement, string $message): void
    {
        $nombre = $this->em->getConnection()->transactional(function () use ($etablissement): int {
            $this->em->lock($etablissement, LockMode::PESSIMISTIC_WRITE);

            return $this->nombreAdministrateurs($etablissement);
        });

        if ($nombre <= 1) {
            throw new UnprocessableEntityHttpException($message);
        }
    }
}
