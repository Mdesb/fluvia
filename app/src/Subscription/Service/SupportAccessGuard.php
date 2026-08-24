<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Audit\Service\JournalAudit;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Exception\SupportAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le seul chemin par lequel un agent de l'éditeur lit l'établissement d'un client (ED-4, RG-ED-07, CA-6).
 *
 * **Échec fermé, sans exception.** La méthode {@see assertCanRead()} refuse par défaut et n'autorise
 * que sur présentation d'un accès nominatif, non révoqué et non expiré. Il n'y a pas de branche
 * « sauf si l'utilisateur est administrateur », et c'est délibéré : le jour où on en ajoute une, la
 * phrase de RG-ED-07 — *il n'existe aucun rôle qui voit tous les établissements par défaut* — devient
 * fausse sans que rien ne le signale.
 *
 * **Le refus est tracé, et il l'est avant d'être levé.** `JournalAudit` persiste sans valider ; une
 * exception levée après l'enregistrement emporterait l'entrée avec elle si personne ne validait. On
 * valide donc explicitement, puis on refuse. Une tentative refusée qui ne laisse pas de trace est
 * exactement celle qu'on voudrait retrouver.
 *
 * **L'accès réussi est tracé aussi.** CA-6 n'exige que la trace du refus, mais un accès d'assistance
 * dont on ne saurait pas qu'il a servi n'est pas auditable — on saurait qui *pouvait* regarder, jamais
 * qui a regardé. Le volume reste borné : un accès d'assistance est exceptionnel par construction, et
 * s'il produit beaucoup d'entrées, c'est une information, pas du bruit.
 */
final class SupportAccessGuard
{
    private const ACTION_GRANTED = 'support_access.granted';
    private const ACTION_REVOKED = 'support_access.revoked';
    private const ACTION_USED = 'support_access.used';
    private const ACTION_DENIED = 'support_access.denied';

    private const TARGET_TYPE = 'SupportAccess';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $audit,
        private readonly EditorTenantResolver $editorTenant,
    ) {
    }

    /**
     * Ouvre un accès nominatif et daté sur l'établissement d'un client.
     *
     * **L'éditeur ne s'ouvre pas d'accès à lui-même.** Ses propres agents lisent son établissement par
     * le cloisonnement ordinaire ; un accès d'assistance sur le tenant éditeur n'aurait aucun sens et
     * masquerait un vrai problème de droits derrière un mécanisme d'exception.
     *
     * @throws SupportAccessDeniedException si la cible est l'éditeur, ou si la fenêtre est vide
     */
    public function grant(
        Utilisateur $grantee,
        Etablissement $target,
        string $reason,
        \DateTimeImmutable $from,
        \DateTimeImmutable $until,
        ?string $grantedBy = null,
    ): SupportAccess {
        if ($this->editorTenant->isEditor($target)) {
            throw new SupportAccessDeniedException(
                'Un accès d\'assistance ne s\'ouvre pas sur l\'établissement de l\'éditeur : ses agents '
                .'y accèdent par le cloisonnement ordinaire.'
            );
        }

        if ($until <= $from) {
            throw new SupportAccessDeniedException(
                'La fenêtre d\'un accès d\'assistance doit être non vide : un accès qui expire avant de '
                .'commencer se lit comme un accès accordé, et ne l\'est pas.'
            );
        }

        if ('' === trim($reason)) {
            throw new SupportAccessDeniedException(
                'Un accès d\'assistance sans motif ne se justifie pas devant le client (RG-ED-07).'
            );
        }

        $access = (new SupportAccess())
            ->setGrantee($grantee)
            ->setEstablishment($target)
            ->setGrantedAt($from)
            ->setExpiresAt($until)
            ->setReason($reason)
            ->setGrantedBy($grantedBy);

        $this->em->persist($access);

        $this->audit->enregistrer(
            self::ACTION_GRANTED,
            self::TARGET_TYPE,
            $access->getId()->toRfc4122(),
            $target->getId(),
            $grantedBy,
        );

        $this->em->flush();

        return $access;
    }

    /** Ferme l'accès avant son terme. L'entrée reste : c'est l'historique de qui a pu voir quoi. */
    public function revoke(SupportAccess $access, \DateTimeImmutable $at, ?string $revokedBy = null): void
    {
        $access->revoke($at);

        $this->audit->enregistrer(
            self::ACTION_REVOKED,
            self::TARGET_TYPE,
            $access->getId()->toRfc4122(),
            $access->getEstablishment()?->getId(),
            $revokedBy,
        );

        $this->em->flush();
    }

    /**
     * Autorise la lecture, ou refuse en échec fermé — et trace dans les deux cas (CA-6).
     *
     * @throws SupportAccessDeniedException si aucun accès utilisable ne couvre ce couple à cet instant
     */
    public function assertCanRead(Utilisateur $agent, Etablissement $target, \DateTimeImmutable $at): SupportAccess
    {
        foreach ($this->accessesFor($agent, $target) as $access) {
            if (!$access->isUsableAt($at)) {
                continue;
            }

            $this->audit->enregistrer(
                self::ACTION_USED,
                self::TARGET_TYPE,
                $access->getId()->toRfc4122(),
                $target->getId(),
                $agent->getEmail(),
            );
            $this->em->flush();

            return $access;
        }

        $this->audit->enregistrer(
            self::ACTION_DENIED,
            self::TARGET_TYPE,
            null,
            $target->getId(),
            $agent->getEmail(),
        );
        // Validé avant de lever : `JournalAudit` persiste sans valider, et l'exception emporterait
        // l'entrée. Une tentative refusée sans trace est celle qu'on voudrait justement retrouver.
        $this->em->flush();

        throw new SupportAccessDeniedException(sprintf(
            'Aucun accès d\'assistance utilisable pour « %s » sur cet établissement à cette date. '
            .'La tentative est tracée (RG-ED-07).',
            $agent->getEmail(),
        ));
    }

    /** Vrai si l'agent peut lire, sans lever ni tracer — pour un affichage, jamais pour décider. */
    public function canRead(Utilisateur $agent, Etablissement $target, \DateTimeImmutable $at): bool
    {
        foreach ($this->accessesFor($agent, $target) as $access) {
            if ($access->isUsableAt($at)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<SupportAccess> */
    private function accessesFor(Utilisateur $agent, Etablissement $target): array
    {
        /** @var list<SupportAccess> $accesses */
        $accesses = $this->em->getRepository(SupportAccess::class)->findBy([
            'grantee' => $agent,
            'establishment' => $target,
        ]);

        return $accesses;
    }
}
