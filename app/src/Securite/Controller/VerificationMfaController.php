<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Audit\Service\JournalAudit;
use App\I18n\CodedHttpException;
use App\Securite\Crypto\ChiffreurSecret;
use App\Securite\Entity\Utilisateur;
use App\Securite\Security\EcouteurConnexion;
use App\Securite\Service\GenerateurCodesRecuperation;
use App\Securite\Service\GenerateurTotp;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Second facteur de la connexion à deux étapes (§2.3 plan-backoffice.md, CA-5) : authentifié via
 * le jeton pré-auth (`Authorization: Bearer <jetonPreAuth>`, claim `mfaEnAttente`). Vérifie `code`
 * contre le secret TOTP déchiffré OU un code de récupération non consommé (le marque consommé si
 * utilisé) ; échec ⇒ 401 + comptage tentativesEchouees/verrouilleJusqua (même politique que le mot
 * de passe) ; succès ⇒ émission du jeton complet, `dernierAcces` mis à jour.
 */
#[AsController]
final class VerificationMfaController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly GenerateurTotp $totp,
        private readonly ChiffreurSecret $chiffreur,
        private readonly GenerateurCodesRecuperation $codes,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly JournalAudit $journal,
    ) {
    }

    #[Route('/auth/mfa-verifier', name: 'securite_mfa_verifier', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return (new CodedHttpException(401, 'auth.unauthenticated', 'Non authentifié.'))->toResponse();
        }

        if ($utilisateur->estVerrouille()) {
            return (new CodedHttpException(401, 'auth.account_locked', 'Compte temporairement verrouillé.'))->toResponse();
        }

        $donnees = json_decode($request->getContent(), true) ?: [];
        $code = (string) ($donnees['code'] ?? '');

        if (!$this->codeValide($utilisateur, $code)) {
            $utilisateur->setTentativesEchouees($utilisateur->getTentativesEchouees() + 1);
            if ($utilisateur->getTentativesEchouees() >= EcouteurConnexion::MAX_TENTATIVES) {
                $utilisateur->setVerrouilleJusqua(new \DateTimeImmutable('+' . EcouteurConnexion::DUREE_VERROU_MINUTES . ' minutes'));
            }
            $this->journal->enregistrer('connexion.mfa_echec', Utilisateur::class, (string) $utilisateur->getId(), null, $utilisateur->getEmail());
            $this->em->flush();

            return (new CodedHttpException(401, 'auth.mfa_invalid_code', 'Code invalide.'))->toResponse();
        }

        $utilisateur->setTentativesEchouees(0);
        $utilisateur->setVerrouilleJusqua(null);
        $utilisateur->setDernierAcces(new \DateTimeImmutable());
        $this->journal->enregistrer('connexion.mfa_succes', Utilisateur::class, (string) $utilisateur->getId(), null, $utilisateur->getEmail());
        $this->em->flush();

        $token = $this->jwtManager->create($utilisateur);

        return new JsonResponse(['token' => $token]);
    }

    private function codeValide(Utilisateur $utilisateur, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $secretChiffre = $utilisateur->getMfaSecret();
        if ($secretChiffre !== null && $this->totp->verifier($this->chiffreur->dechiffrer($secretChiffre), $code)) {
            return true;
        }

        $hashes = $utilisateur->getMfaCodesRecuperation() ?? [];
        $restants = $this->codes->consommer($hashes, $code);
        if ($restants !== null) {
            $utilisateur->setMfaCodesRecuperation($restants);

            return true;
        }

        return false;
    }
}
