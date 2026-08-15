<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Activation d'un compte invité (RG-M8-01, CA-1/CA-2) : `{jeton, motDePasse}` → jeton
 * inconnu/expiré/déjà consommé ⇒ refus générique (422) ; sinon hash du mot de passe,
 * `statut = actif`, jeton consommé.
 */
#[AsController]
final class ActivationController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/utilisateurs/activation', name: 'securite_activation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $jeton = (string) ($donnees['jeton'] ?? '');
        $motDePasse = (string) ($donnees['motDePasse'] ?? '');

        if ($jeton === '' || $motDePasse === '') {
            return new JsonResponse(['message' => 'Jeton et mot de passe requis.'], 422);
        }

        $hash = hash('sha256', $jeton);
        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['jetonInvitation' => $hash]);

        if ($utilisateur === null) {
            return new JsonResponse(['message' => 'Jeton invalide.'], 422);
        }

        $expire = $utilisateur->getJetonInvitationExpire();
        if ($expire === null || $expire < new \DateTimeImmutable()) {
            return new JsonResponse(['message' => 'Jeton expiré.'], 410);
        }

        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setStatut(StatutUtilisateur::Actif);
        $utilisateur->setJetonInvitation(null);
        $utilisateur->setJetonInvitationExpire(null);
        $this->em->flush();

        return new JsonResponse(['message' => 'Compte activé.'], 200);
    }
}
