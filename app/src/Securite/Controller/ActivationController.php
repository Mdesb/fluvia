<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Service\PasswordPolicy;
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
    /** Longueur minimale exigee sur ce flux — la meme que celle annoncee par l'ecran. */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $passwords,
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

        // ⚠ LE MEME SEUIL QUE CELUI ANNONCE PAR L'ECRAN, MAIS ICI IL ENGAGE.
        // Cote client il n'etait qu'un confort : cette route acceptait toute chaine non vide,
        // donc un appel direct posait « a ». Un formulaire qui affiche une regle que le serveur
        // ignore annonce le trou au lieu de le fermer.
        // Depuis le 06/09 la règle vit dans `PasswordPolicy`, la même pour les quatre portes.
        $violation = $this->passwords->violation($motDePasse);
        if ($violation !== null) {
            return new JsonResponse(['message' => $violation], 422);
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
