<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Subscription\ApiResource\EditorSupportAccess;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GET /editor/support-accesses` — les accès d'assistance encore ouverts.
 *
 * **Seulement les ouverts, et c'est le point.** Une liste d'accès révoqués et expirés serait un
 * historique, et un historique ne se vide pas : il grossit, on cesse de le lire, et l'accès qui traîne
 * encore ouvert s'y noie. L'écran doit montrer **ce sur quoi on peut agir** (D55). L'historique complet
 * vit au journal d'audit, qui est fait pour ça et que personne ne confondra avec une liste de travail.
 *
 * **Un accès expiré n'apparaît plus, mais il n'est pas effacé.** Il a cessé d'ouvrir la porte tout
 * seul ; il n'y a rien à faire dessus. Le montrer donnerait l'impression qu'il reste quelque chose à
 * fermer.
 */
final class EditorSupportAccessProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<EditorSupportAccess>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->editorOnly->assertEditor();

        $maintenant = new \DateTimeImmutable();

        /** @var list<SupportAccess> $ouverts */
        $ouverts = $this->em->getRepository(SupportAccess::class)->createQueryBuilder('a')
            ->andWhere('a.revokedAt IS NULL')
            ->andWhere('a.expiresAt > :maintenant')
            ->setParameter('maintenant', $maintenant)
            ->orderBy('a.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (SupportAccess $access): EditorSupportAccess => self::fiche($access, $maintenant),
            $ouverts,
        );
    }

    /** Compose la fiche. Partagée avec les processeurs pour qu'ils rendent la même forme que la liste. */
    public static function fiche(SupportAccess $access, \DateTimeImmutable $maintenant): EditorSupportAccess
    {
        $fiche = new EditorSupportAccess();
        $fiche->id = $access->getId()->toRfc4122();
        $fiche->granteeName = $access->getGrantee()?->getNom() ?? '';
        $fiche->granteeEmail = $access->getGrantee()?->getEmail() ?? '';
        $fiche->establishmentName = $access->getEstablishment()?->getNom() ?? '';
        $fiche->establishmentId = (string) $access->getEstablishment()?->getId();
        $fiche->reason = $access->getReason();
        $fiche->grantedBy = $access->getGrantedBy();
        $fiche->grantedAt = $access->getGrantedAt()->format(\DATE_ATOM);
        $fiche->expiresAt = $access->getExpiresAt()->format(\DATE_ATOM);
        $fiche->remainingMinutes = (int) floor(
            ($access->getExpiresAt()->getTimestamp() - $maintenant->getTimestamp()) / 60,
        );

        return $fiche;
    }
}
