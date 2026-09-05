<?php

declare(strict_types=1);

namespace App\PublicApi\Security;

use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\ApiScope;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'application tierce authentifiee, et ce a quoi les etablissements l'ont autorisee.
 *
 * ⚠ **DISTINCT PAR CONSTRUCTION DE `App\Securite\Entity\Utilisateur`**, exactement comme
 * {@see \App\Acces\Security\TerminalUtilisateur}. Une cle de partenaire ne debloque jamais les
 * permissions humaines : elle ne porte que `ROLE_PARTNER`, et le `PermissionVoter` humain ne la voit
 * pas. C'est la propriete qui empeche qu'une integration devienne un compte d'administration.
 *
 * @phpstan-type Perimetre array<string, list<string>>
 */
final class PartnerUser implements UserInterface
{
    /** @param list<ApiGrant> $grants les consentements ACTIFS, resolus a l'authentification */
    public function __construct(
        public readonly PartnerApplication $application,
        public readonly array $grants,
    ) {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_PARTNER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->application->getId();
    }

    /**
     * Les etablissements que cette application peut voir pour une portee donnee.
     *
     * ⚠ **C'EST LA SEULE SOURCE DU PERIMETRE DE L'API PUBLIQUE.** Elle ne lit ni la requete ni un
     * en-tete : un partenaire qui demanderait un etablissement qu'il n'a pas obtient une liste vide,
     * pas une erreur — la meme reponse que s'il n'existait pas.
     *
     * @return list<Uuid>
     */
    public function establishmentsFor(ApiScope $scope): array
    {
        $ids = [];

        foreach ($this->grants as $grant) {
            $etablissement = $grant->getEtablissement();

            if (null !== $etablissement && $grant->allows($scope)) {
                $ids[] = $etablissement->getId();
            }
        }

        return $ids;
    }

    public function hasScope(ApiScope $scope): bool
    {
        return [] !== $this->establishmentsFor($scope);
    }
}
