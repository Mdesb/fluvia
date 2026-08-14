<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hache le mot de passe fourni en clair (RG-SOCLE-06) avant la persistance, puis délègue
 * au processor Doctrine standard. Le hash n'est jamais renvoyé (aucun groupe de lecture).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class UtilisateurProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Utilisateur, Utilisateur> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Utilisateur && $data->getMotDePasseClair() !== null && $data->getMotDePasseClair() !== '') {
            $data->setMotDePasse($this->hasher->hashPassword($data, $data->getMotDePasseClair()));
            $data->eraseCredentials();
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
