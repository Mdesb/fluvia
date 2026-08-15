<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Décore le success handler lexik (§2.3 plan-backoffice.md, US-L7-03) : connexion à deux étapes
 * si le MFA est actif. Sans MFA, comportement socle inchangé (CA-2 socle non régressé) — délègue
 * tel quel au handler lexik, jeton complet immédiat. Avec MFA actif, n'émet pas de jeton complet :
 * répond `{mfaRequis: true, jetonPreAuth}` (jeton court porteur du claim `mfaEnAttente`), bloqué
 * par `VerificateurJwtActifListener` sur toute route autre que `/auth/mfa-verifier`.
 */
final class GestionnaireSuccesConnexionMfa implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandlerInterface $handlerComplet,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return $this->handlerComplet->onAuthenticationSuccess($request, $token);
        }

        if (!$utilisateur->isMfaActif()) {
            $utilisateur->setDernierAcces(new \DateTimeImmutable());
            $this->em->flush();

            return $this->handlerComplet->onAuthenticationSuccess($request, $token);
        }

        $jetonPreAuth = $this->jwtManager->createFromPayload($utilisateur, ['mfaEnAttente' => true]);

        return new JsonResponse(['mfaRequis' => true, 'jetonPreAuth' => $jetonPreAuth]);
    }
}
