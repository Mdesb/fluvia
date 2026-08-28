<?php

declare(strict_types=1);

namespace App\Organisation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Service\StructureOnboarding;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /organisation/structures` — ouvrir un client en un geste.
 *
 * Le corps est lu à la main (idiome du dépôt) : ce qu'on reçoit n'est pas une entité mais le
 * résultat d'une recherche dans l'annuaire, plus ce que l'exploitant a corrigé.
 *
 * ── LE RÔLE DE L'AUTEUR EST REPRIS, JAMAIS INVENTÉ ──────────────────────────────────────────────
 *
 * On lui donne sur le nouveau site le rôle qui lui a permis d'arriver ici. Créer un rôle
 * « administrateur de Gione fitness » en fabriquerait un par client, et personne ne saurait
 * lesquels purger.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final readonly class StructureOnboardingProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private CalculateurDroits $calculateur,
        private StructureOnboarding $ouverture,
        private RequestStack $requetes,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Aucun utilisateur authentifié.');
        }

        $requete = $this->requetes->getCurrentRequest();
        $corps = json_decode((string) $requete?->getContent(), true);
        if (!\is_array($corps)) {
            throw new UnprocessableEntityHttpException('Corps de requête illisible.');
        }

        $role = $this->roleQuiAdministre($utilisateur);
        if (!$role instanceof Role) {
            throw new UnprocessableEntityHttpException(
                'Aucun de vos rôles ne permet d’administrer une organisation : la structure serait '
                . 'créée et vous ne pourriez pas l’ouvrir.',
            );
        }

        $etablissement = $this->ouverture->ouvrir($corps, $utilisateur, $role);

        return new JsonResponse([
            'etablissement' => (string) $etablissement->getId(),
            'nom' => $etablissement->getNom(),
            // Ce que l'écran annoncera : le site est vendable, pas seulement créé.
            'pretAVendre' => true,
            'message' => sprintf(
                '« %s » est ouverte. Un point de vente « Accueil » a été créé : vous pouvez encaisser.',
                $etablissement->getNom(),
            ),
        ], 201);
    }

    private function roleQuiAdministre(Utilisateur $utilisateur): ?Role
    {
        /** @var list<Affectation> $affectations */
        $affectations = $this->entityManager->getRepository(Affectation::class)
            ->findBy(['utilisateur' => $utilisateur]);

        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            $etablissement = $affectation->getEtablissement();
            if ($role === null || $etablissement === null) {
                continue;
            }

            $codes = $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId());
            if ($this->calculateur->autorise($codes, 'organisation', 'gerer')) {
                return $role;
            }
        }

        return null;
    }
}
