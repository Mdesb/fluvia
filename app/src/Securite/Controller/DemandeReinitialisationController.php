<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Entity\JetonReinitialisation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Notification\ReinitialisationMailer;
use Doctrine\ORM\EntityManagerInterface;
use App\Platform\Notification\ExpediteurCourriel;
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
        private readonly ExpediteurCourriel $courriel,
    ) {
    }

    #[Route('/mot-de-passe/oublie', name: 'securite_mdp_oublie', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true) ?: [];
        $email = (string) ($donnees['email'] ?? '');

        // ⚠ CE MESSAGE ÉTAIT UN MENSONGE, ET IL L'ÉTAIT POUR TOUT LE MONDE.
        //
        // « Si ce compte existe, un e-mail a été envoyé » est une formule anti-énumération : elle
        // répond la même chose que le compte existe ou non, pour qu'on ne puisse pas découvrir les
        // adresses inscrites en les essayant. Cette propriété est juste et on la garde.
        //
        // Mais aucun e-mail n'est envoyé — `MAILER_DSN` vaut `null://null`, le transport nul avale
        // tout en silence. La personne attendait donc un message qui ne viendrait jamais, sans rien
        // à l'écran pour le lui dire, et sans autre porte : `Login.jsx` n'offrait même pas ce
        // parcours.
        //
        // ⚠ DIRE LA VÉRITÉ ICI NE COMPROMET PAS L'ANTI-ÉNUMÉRATION. « Un expéditeur est-il
        // configuré » est un fait GLOBAL de l'instance : il ne dépend pas de l'adresse saisie, donc
        // il ne dit rien sur elle. Les deux réponses restent indiscernables compte par compte.
        //
        // Et c'est un fait d'exécution, pas une constante : le jour où Maxime branche un expéditeur,
        // ce message redevient vrai tout seul. C'était la condition posée — six phrases écrites en
        // dur auraient été six mensonges différés.
        $branche = $this->courriel->estBranche();

        $reponse = new JsonResponse([
            'message' => $branche
                ? 'Si ce compte existe, un e-mail a été envoyé.'
                : 'Aucun message ne partira : cette instance n’a pas d’expéditeur de courriel '
                    . 'configuré. Demandez à votre administrateur de vous poser un nouveau mot de '
                    . 'passe depuis la fiche de votre compte.',
            'envoiCourrielBranche' => $branche,
        ], 202);

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

        // Le jeton est créé même sans expéditeur : il ne coûte rien, il expire seul, et le jour où
        // un expéditeur existe le parcours fonctionne sans rien changer ici. Ce qui serait faux,
        // c'est de prétendre l'avoir envoyé.
        if ($branche) {
            $this->mailer->envoyer($utilisateur, $jetonClair);
        }

        return $reponse;
    }
}
