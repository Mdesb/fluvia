<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Service\PasswordPolicy;
use App\Audit\Service\JournalAudit;
use App\Securite\Entity\JetonReinitialisation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /mot-de-passe/reinitialiser {jeton, nouveauMotDePasse}` (CA-6) : jeton inconnu/expiré/déjà
 * utilisé ⇒ refus générique (410/422) ; sinon hash du nouveau mot de passe, `utilise = true`,
 * `tentativesEchouees = 0`, `verrouilleJusqua = null`, `tokenVersion++` (déconnecte les sessions
 * existantes), action `mot_de_passe.reinitialise` journalisée explicitement.
 */
#[AsController]
final class ReinitialisationMotDePasseController
{
    /** Longueur minimale exigee sur ce flux — la meme que celle annoncee par l'ecran. */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $passwords,
        private readonly JournalAudit $journal,
    ) {
    }

    #[Route('/mot-de-passe/reinitialiser', name: 'securite_mdp_reinitialiser', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true) ?: [];
        $jetonClair = (string) ($donnees['jeton'] ?? '');
        $nouveauMotDePasse = (string) ($donnees['nouveauMotDePasse'] ?? '');

        if ($jetonClair === '' || $nouveauMotDePasse === '') {
            return new JsonResponse(['message' => 'Jeton et nouveau mot de passe requis.'], 422);
        }

        // Meme seuil que l'activation, et pour la meme raison : l'ecran l'annonce, le serveur
        // l'applique. Voir `ActivationController`.
        // Depuis le 06/09 la règle vit dans `PasswordPolicy`, la même pour les quatre portes.
        $violation = $this->passwords->violation($nouveauMotDePasse);
        if ($violation !== null) {
            return new JsonResponse(['message' => $violation], 422);
        }

        $hash = hash('sha256', $jetonClair);
        $jeton = $this->em->getRepository(JetonReinitialisation::class)->findOneBy(['jeton' => $hash]);

        if ($jeton === null) {
            return new JsonResponse(['message' => 'Jeton invalide.'], 422);
        }

        if (!$jeton->estValide(new \DateTimeImmutable())) {
            return new JsonResponse(['message' => 'Jeton expiré ou déjà utilisé.'], 410);
        }

        $utilisateur = $jeton->getUtilisateur();
        \assert($utilisateur instanceof Utilisateur);

        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, $nouveauMotDePasse));
        $utilisateur->setTentativesEchouees(0);
        $utilisateur->setVerrouilleJusqua(null);
        $utilisateur->setTokenVersion($utilisateur->getTokenVersion() + 1);
        $jeton->setUtilise(true);

        $this->journal->enregistrer(
            'mot_de_passe.reinitialise',
            Utilisateur::class,
            (string) $utilisateur->getId(),
            null,
            $utilisateur->getEmail(),
        );

        $this->em->flush();

        return new JsonResponse(['message' => 'Mot de passe réinitialisé.'], 200);
    }
}
