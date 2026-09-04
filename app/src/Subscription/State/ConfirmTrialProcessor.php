<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Subscription\ApiResource\PublicTrialConfirmation;
use App\Subscription\Entity\Subscription;
use App\Subscription\Exception\ExpiredConfirmationLinkException;
use App\Subscription\Exception\UnknownConfirmationTokenException;
use App\Subscription\Security\CartRateLimiter;
use App\Subscription\Service\SubscriptionFunnel;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ouvre l'essai gratuit quand le prospect a prouvé qu'il lit son adresse (ED-5).
 *
 * **Le débit est borné ici aussi, et pour une raison différente des autres routes.** Celle-ci prend
 * un secret en entrée : sans borne, on peut essayer des jetons en série. Trente-deux octets ne se
 * devinent pas, mais un compteur qui n'existe pas ne se regrette qu'après.
 *
 * **Les deux refus rendent le même code et deux messages différents.** Jeton inconnu : on ne dit pas
 * s'il a existé — cette route deviendrait un moyen de savoir quelles demandes d'essai existent.
 * Jeton expiré : on le dit franchement, avec le geste suivant, parce que celui qui le tient sait
 * déjà qu'il a existé et qu'il n'a rien fait de mal.
 *
 * ---
 *
 * **@cloisonnement-verifie : le jeton est la preuve, et rien n'est résolu par un identifiant client.**
 *
 * Le seul élément fourni par le visiteur est un secret de trente-deux octets, tiré au sort par nous
 * et envoyé à une seule adresse. L'abonnement n'est donc pas « résolu par un identifiant client » :
 * il est désigné par une valeur que seul le destinataire du courriel possède — une preuve plus forte
 * qu'un périmètre, puisqu'elle prouve l'adresse et pas seulement l'appartenance.
 *
 * La lecture de la fiche client qui suit part de `getCustomerReference()`, c'est-à-dire d'une donnée
 * de l'entité déjà authentifiée par le jeton, jamais d'une valeur du corps de la requête. Aucun
 * identifiant venu du dehors n'atteint un `find()`.
 */
final class ConfirmTrialProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SubscriptionFunnel $funnel,
        private readonly EntityManagerInterface $em,
        private readonly CartRateLimiter $limiter,
        private readonly LecteurCorps $lecteur,
        private readonly RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PublicTrialConfirmation
    {
        $this->limiter->assertNotExceeded($this->requests->getCurrentRequest()?->getClientIp());

        $corps = $this->lecteur->corps();
        $jeton = \is_string($corps['token'] ?? null) ? trim($corps['token']) : '';

        if ('' === $jeton) {
            throw new UnprocessableEntityHttpException('Ce lien de confirmation est incomplet.');
        }

        try {
            $subscription = $this->funnel->confirmEmailAndStartTrial($jeton, new \DateTimeImmutable());
        } catch (UnknownConfirmationTokenException $refus) {
            throw new UnprocessableEntityHttpException(
                'Ce lien de confirmation n\'est pas valable. Vérifiez qu\'il a été copié en entier, '
                .'ou recomposez votre offre pour en recevoir un nouveau.',
                $refus,
            );
        } catch (ExpiredConfirmationLinkException $refus) {
            throw new UnprocessableEntityHttpException(
                sprintf(
                    'Ce lien a expiré : il était valable %d heures. Recomposez votre offre, '
                    .'nous vous en envoyons un nouveau.',
                    SubscriptionFunnel::CONFIRMATION_HOURS,
                ),
                $refus,
            );
        }

        $fin = $subscription->getTrialEndsAt();

        $reponse = new PublicTrialConfirmation();
        $reponse->id = $subscription->getId()->toRfc4122();
        $reponse->companyName = $this->raisonSocialeDe($subscription);
        $reponse->trialEndsAt = $fin?->format(\DateTimeInterface::ATOM) ?? '';

        return $reponse;
    }

    private function raisonSocialeDe(Subscription $subscription): string
    {
        $prospect = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());

        return $prospect instanceof Client ? ($prospect->getRaisonSociale() ?? '') : '';
    }
}
