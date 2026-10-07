<?php

declare(strict_types=1);

namespace App\PublicApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\PublicApi\ApiResource\PartnerAccess;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Service\PartnerAccessManager;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /partner-accesses/{application}/grant` et `/withdraw` — accorder ou retirer, pour l'établissement
 * ACTIF, et pour lui seul.
 *
 * ⚠ L'établissement ne vient jamais du corps ni de l'URL : il vient de `ContexteEtablissement`, dont
 * l'en-tête a été validé pour l'utilisateur (voir `PartnerAccessProvider`). L'application résolue par
 * l'URL n'appartient à aucun établissement ; l'accord touché est cherché par le couple (application,
 * établissement actif) — un exploitant ne peut donc atteindre que l'accord de son établissement.
 *
 * @cloisonnement-verifie : `PartnerApplication` n'appartient à aucun établissement (une application sert
 * plusieurs clients) ; ce qui est cloisonné, c'est l'accord, et `PartnerAccessManager` ne touche que celui
 * du couple (application, établissement actif validé). Témoin : `PartnerAccessApiTest`.
 */
final class PartnerAccessProcessor implements ProcessorInterface
{
    public const GRANT = 'partner_access_grant';
    public const WITHDRAW = 'partner_access_withdraw';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerAccessProvider $provider,
        private readonly PartnerAccessManager $manager,
        private readonly LecteurCorps $body,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PartnerAccess
    {
        $establishment = $this->provider->activeEstablishment();

        $reference = $uriVariables['id'] ?? null;
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            throw new NotFoundHttpException();
        }
        $application = $this->em->getRepository(PartnerApplication::class)->find(Uuid::fromString($reference));
        if (!$application instanceof PartnerApplication) {
            throw new NotFoundHttpException();
        }

        if (self::GRANT === $operation->getName()) {
            $user = $this->security->getUser();
            $grant = $this->manager->grant($application, $establishment, $this->scopes(), $user instanceof Utilisateur ? $user : null);

            return PartnerAccessProvider::view($application, $grant);
        }

        $this->manager->withdraw($application, $establishment);

        return PartnerAccessProvider::view($application, null);
    }

    /** @return list<ApiScope> */
    private function scopes(): array
    {
        $raw = $this->body->corps()['scopes'] ?? null;
        if (!\is_array($raw)) {
            throw new UnprocessableEntityHttpException('Indiquez les portées accordées (`scopes`).');
        }

        $scopes = [];
        foreach ($raw as $value) {
            $scope = \is_string($value) ? ApiScope::tryFrom($value) : null;
            if (null === $scope) {
                throw new UnprocessableEntityHttpException(sprintf('Portée inconnue : « %s ».', \is_scalar($value) ? (string) $value : '?'));
            }
            $scopes[] = $scope;
        }

        return $scopes;
    }
}
