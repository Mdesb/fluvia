<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Entity\JetonReinitialisation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Notification\ReinitialisationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /mot-de-passe/oublie {email}` (US-L0-03 différée, CA-6) : répond TOUJOURS 202 identique,
 * que l'e-mail existe ou non (pas de fuite d'information). Si le compte existe, crée un jeton à
 * usage unique et durée limitée (1h) et envoie l'e-mail.
 */
#[AsController]
final class DemandeReinitialisationController
{
    public const DUREE_VALIDITE_HEURES = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReinitialisationMailer $mailer,
    ) {
    }

    #[Route('/mot-de-passe/oublie', name: 'securite_mdp_oublie', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true) ?: [];
        $email = (string) ($donnees['email'] ?? '');

        $reponse = new JsonResponse(['message' => 'Si ce compte existe, un e-mail a été envoyé.'], 202);

        if ($email === '') {
            return $reponse;
        }

        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($utilisateur === null) {
            return $reponse;
        }

        $jetonClair = bin2hex(random_bytes(32));
        $jeton = new JetonReinitialisation();
        $jeton->setUtilisateur($utilisateur);
        $jeton->setJeton(hash('sha256', $jetonClair));
        $jeton->setDateExpiration(new \DateTimeImmutable('+' . self::DUREE_VALIDITE_HEURES . ' hour'));
        $this->em->persist($jeton);
        $this->em->flush();

        $this->mailer->envoyer($utilisateur, $jetonClair);

        return $reponse;
    }
}
