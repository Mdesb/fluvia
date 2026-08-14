<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Audit\Service\JournalAudit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Gère le verrouillage après N échecs (RG-SOCLE-06, CA-3) et journalise les connexions (RG-SOCLE-07).
 */
final class EcouteurConnexion implements EventSubscriberInterface
{
    public const MAX_TENTATIVES = 5;
    public const DUREE_VERROU_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSucces',
            LoginFailureEvent::class => 'onEchec',
        ];
    }

    public function onSucces(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        if ($utilisateur->getTentativesEchouees() !== 0 || $utilisateur->getVerrouilleJusqua() !== null) {
            $utilisateur->setTentativesEchouees(0);
            $utilisateur->setVerrouilleJusqua(null);
            $this->em->flush();
        }

        $this->journal->enregistrer('connexion.succes', Utilisateur::class, (string) $utilisateur->getId(), null, $utilisateur->getEmail());
        $this->em->flush();
    }

    public function onEchec(LoginFailureEvent $event): void
    {
        $utilisateur = $this->resoudreUtilisateur($event);
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $utilisateur->setTentativesEchouees($utilisateur->getTentativesEchouees() + 1);
        if ($utilisateur->getTentativesEchouees() >= self::MAX_TENTATIVES) {
            $utilisateur->setVerrouilleJusqua(
                new \DateTimeImmutable('+' . self::DUREE_VERROU_MINUTES . ' minutes')
            );
        }

        $this->journal->enregistrer('connexion.echec', Utilisateur::class, (string) $utilisateur->getId(), null, $utilisateur->getEmail());
        $this->em->flush();
    }

    private function resoudreUtilisateur(LoginFailureEvent $event): ?Utilisateur
    {
        $passport = $event->getPassport();
        if ($passport !== null) {
            try {
                $user = $passport->getUser();
                if ($user instanceof Utilisateur) {
                    return $user;
                }
            } catch (\Throwable) {
                // Badge utilisateur indisponible (identifiant inconnu).
            }
        }

        $exception = $event->getException();
        if ($exception instanceof BadCredentialsException) {
            // Rien de plus à résoudre.
        }

        return null;
    }
}
