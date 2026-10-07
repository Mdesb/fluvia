<?php

declare(strict_types=1);

namespace App\PublicApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\PublicApi\ApiResource\PartnerAccess;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Enum\GrantStatus;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /partner-accesses` — les applications actives, et l'accord de L'ÉTABLISSEMENT ACTIF.
 *
 * ⚠ **LE PÉRIMÈTRE EST POSÉ ICI, À LA MAIN.** `ApiGrant` n'est couvert par aucune extension de
 * cloisonnement : chaque lecture d'accord est bornée explicitement à `ContexteEtablissement`, dont
 * l'en-tête est validé pour l'utilisateur par `EstablishmentHeaderListener` (404 sinon). Sans en-tête,
 * on refuse plutôt que de deviner : l'accord d'un établissement ne se lit pas « par défaut ».
 *
 * Une application désactivée n'apparaît que si l'établissement lui a encore un accord en cours — pour
 * qu'il puisse le retirer.
 */
final class PartnerAccessProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $context,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<PartnerAccess>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $establishment = $this->activeEstablishment();

        $grants = [];
        foreach ($this->activeGrants($establishment) as $grant) {
            $grants[(string) $grant->getApplication()?->getId()] = $grant;
        }

        $views = [];
        /** @var list<PartnerApplication> $applications */
        $applications = $this->em->getRepository(PartnerApplication::class)->findBy([], ['name' => 'ASC']);
        foreach ($applications as $application) {
            $grant = $grants[(string) $application->getId()] ?? null;
            if ($application->isActive() || null !== $grant) {
                $views[] = self::view($application, $grant);
            }
        }

        return $views;
    }

    public function activeEstablishment(): Etablissement
    {
        $establishment = $this->context->etablissementActif();
        if (!$establishment instanceof Etablissement) {
            throw new NotFoundHttpException('Choisissez un établissement : un accord se donne pour un établissement précis.');
        }

        return $establishment;
    }

    /** @return list<ApiGrant> */
    private function activeGrants(Etablissement $establishment): array
    {
        /** @var list<ApiGrant> $grants */
        $grants = $this->em->getRepository(ApiGrant::class)->findBy([
            'etablissement' => $establishment,
            'status' => GrantStatus::Active,
        ]);

        return $grants;
    }

    public static function view(PartnerApplication $application, ?ApiGrant $grant): PartnerAccess
    {
        $view = new PartnerAccess();
        $view->id = (string) $application->getId();
        $view->applicationName = $application->getName();
        $view->contactEmail = $application->getContactEmail();
        $view->applicationActive = $application->isActive();
        $view->grant = null === $grant ? null : [
            'scopes' => $grant->getScopes(),
            'grantedAt' => $grant->getGrantedAt()->format(\DATE_ATOM),
            'grantedBy' => $grant->getGrantedBy()?->getEmail(),
        ];
        $view->availableScopes = array_map(
            static fn (ApiScope $s): array => ['value' => $s->value, 'label' => $s->label()],
            ApiScope::grantable(),
        );

        return $view;
    }
}
