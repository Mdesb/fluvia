<?php

declare(strict_types=1);

namespace App\Autorisation\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Service\VerificateurPerimetreLimite;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde de `LimiteAutorisation` (§4.1 plan) : cible exclusive rôle XOR utilisateur (RG-AUTZ-02),
 * établissement du corps réellement géré par l'auteur (`VerificateurPerimetreLimite`, pas seulement
 * le contexte actif), garde applicative « au plus une limite par (opération, cible, établissement) »
 * (§1 plan, pas de contrainte SQL native possible avec NULL en index unique MariaDB).
 *
 * @implements ProcessorInterface<LimiteAutorisation, LimiteAutorisation|null>
 */
final class LimiteAutorisationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<LimiteAutorisation, LimiteAutorisation> $persistProcessor
     * @param ProcessorInterface<LimiteAutorisation, null>                $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly EntityManagerInterface $em,
        private readonly VerificateurPerimetreLimite $verificateur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof LimiteAutorisation);

        $etablissement = $data->getEtablissement();
        $auteurCourant = $this->security->getUser();

        if ($operation instanceof DeleteOperationInterface) {
            if ($etablissement !== null && $auteurCourant instanceof Utilisateur) {
                $this->verificateur->verifier($auteurCourant, $etablissement);
            }

            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        $role = $data->getRole();
        $utilisateur = $data->getUtilisateur();
        if (($role === null) === ($utilisateur === null)) {
            throw new UnprocessableEntityHttpException("Une limite porte soit un rôle, soit un utilisateur, jamais les deux ni aucun (RG-AUTZ-02).");
        }

        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement requis.');
        }

        if ($auteurCourant instanceof Utilisateur) {
            $this->verificateur->verifier($auteurCourant, $etablissement);
            // `auteur` est en lecture seule côté API (hors groupe `limite:write`) : assigné ici
            // inconditionnellement à l'appelant réel, à chaque create/update, pour empêcher toute
            // usurpation de signature (défaut majeur revue de cohérence).
            $data->setAuteur($auteurCourant);
        }

        // Garde applicative « au plus une limite par (opération, cible, établissement) » (§1 plan).
        $existante = $this->em->getRepository(LimiteAutorisation::class)->findOneBy([
            'operation' => $data->getOperation(),
            'etablissement' => $etablissement,
            'role' => $role,
            'utilisateur' => $utilisateur,
        ]);
        if ($existante !== null && $existante !== $data) {
            throw new UnprocessableEntityHttpException('Une limite existe déjà pour cette opération/cible/établissement.');
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
