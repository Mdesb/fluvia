<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

use App\Organisation\Entity\Etablissement;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Enum\GrantStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cet établissement autorise-t-il, MAINTENANT, cette application à recevoir ses événements ?
 *
 * Lu en base à chaque appel, jamais mis en cache : c'est la réponse qu'un retrait d'accord doit
 * changer dès la tentative suivante. Abonnement coupé ou application désactivée valent refus.
 */
final class PartnerConsent
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function allows(PartnerWebhookSubscription $subscription, Etablissement $establishment): bool
    {
        $application = $subscription->getApplication();
        if (!$subscription->isActive() || null === $application || !$application->isActive()) {
            return false;
        }

        /** @var list<ApiGrant> $grants */
        $grants = $this->em->getRepository(ApiGrant::class)->findBy([
            'application' => $application,
            'etablissement' => $establishment,
            'status' => GrantStatus::Active,
        ]);
        foreach ($grants as $grant) {
            $this->em->refresh($grant);
            if ($grant->allows(ApiScope::EventsSubscribe)) {
                return true;
            }
        }

        return false;
    }
}
