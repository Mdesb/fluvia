<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Subscription\ApiResource\PublicTrialRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Exception\InvalidSubscriptionTransitionException;
use App\Subscription\Security\CartRateLimiter;
use App\Subscription\Service\SubscriptionFunnel;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Déclenche le courriel de confirmation d'un essai gratuit (ED-5).
 *
 * **Le débit est borné avant tout le reste**, comme sur l'ouverture de panier : cette route est
 * publique et provoque un envoi de courriel. Sans borne, elle devient un moyen d'inonder une adresse
 * de messages qui portent notre nom.
 *
 * **L'adresse rendue est masquée.** La page a besoin de rappeler au visiteur où regarder — il vient
 * de la saisir, il peut l'avoir mal tapée. Mais la rendre en clair transformerait un identifiant de
 * panier en moyen de lire une adresse, et les paniers ne sont pas des secrets.
 *
 * ---
 *
 * **@cloisonnement-verifie : l'appartenance se prouve par l'ADRESSE, pas par le périmètre.**
 *
 * Cette route est publique : il n'y a ni session, ni utilisateur, donc aucune autorité à recalculer.
 * Le contrôle canonique (`codesEffectifs` contre l'établissement de l'entité) n'a rien à quoi
 * s'appliquer — un prospect n'a pas d'établissement, c'est toute la raison d'être du tunnel.
 *
 * Ce qui le remplace : **le demandeur doit redonner l'adresse qu'il a saisie**, et elle doit être
 * celle du panier. Sans cela, tenir un identifiant de panier suffirait à déclencher l'envoi d'un
 * courriel à son propriétaire — autant de fois que la borne de débit l'autorise — et à lire le
 * domaine de son adresse dans la réponse. Avec, il faut connaître les deux, et celui qui les
 * connaît est celui qui vient de remplir le formulaire.
 */
final class RequestTrialProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SubscriptionFunnel $funnel,
        private readonly EntityManagerInterface $em,
        private readonly CartRateLimiter $limiter,
        private readonly LecteurCorps $lecteur,
        private readonly RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PublicTrialRequest
    {
        $this->limiter->assertNotExceeded($this->requests->getCurrentRequest()?->getClientIp());

        $corps = $this->lecteur->corps();
        $brut = \is_string($corps['cartId'] ?? null) ? trim($corps['cartId']) : '';
        $adresseDonnee = \is_string($corps['email'] ?? null) ? trim($corps['email']) : '';

        if (!Uuid::isValid($brut) || '' === $adresseDonnee) {
            throw new UnprocessableEntityHttpException('Ce panier n\'est pas identifiable.');
        }

        $subscription = $this->em->getRepository(Subscription::class)->find(Uuid::fromString($brut));
        if (!$subscription instanceof Subscription) {
            // Même message qu'un panier mal formé : cette route ne dit pas quels paniers existent.
            throw new UnprocessableEntityHttpException('Ce panier n\'est pas identifiable.');
        }

        // La preuve d'appartenance. Un seul message pour les trois refus — panier mal formé, panier
        // inconnu, adresse qui ne correspond pas : les distinguer ferait de cette route un moyen de
        // savoir quels paniers existent, et à qui.
        if (0 !== strcasecmp($this->emailDe($subscription), $adresseDonnee)) {
            throw new UnprocessableEntityHttpException('Ce panier n\'est pas identifiable.');
        }

        try {
            $this->funnel->requestTrial($subscription, new \DateTimeImmutable());
        } catch (InvalidSubscriptionTransitionException $refus) {
            // Le message d'origine s'adresse à nous — il nomme des états internes. Le visiteur, lui,
            // a seulement rechargé une page ou cliqué deux fois : on lui dit ce qui le concerne.
            throw new UnprocessableEntityHttpException(
                'Cette demande a déjà été envoyée. Regardez votre boîte de réception, '
                .'ou recomposez votre offre pour recommencer.',
                $refus,
            );
        }

        $reponse = new PublicTrialRequest();
        $reponse->id = $subscription->getId()->toRfc4122();
        $reponse->maskedEmail = $this->masquer($this->emailDe($subscription));
        $reponse->confirmationHours = SubscriptionFunnel::CONFIRMATION_HOURS;

        return $reponse;
    }

    private function emailDe(Subscription $subscription): string
    {
        $prospect = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());

        return $prospect instanceof Client ? ($prospect->getEmail() ?? '') : '';
    }

    /**
     * `marie.dupont@piscine-du-lac.fr` devient `m•••@piscine-du-lac.fr`.
     *
     * Le domaine reste lisible parce que c'est lui qui permet de reconnaître une faute de frappe —
     * `@gmial.com` saute aux yeux, `m•••@•••` ne dit rien à personne.
     */
    private function masquer(string $email): string
    {
        $arobase = strpos($email, '@');
        if (false === $arobase || $arobase < 1) {
            return '';
        }

        return substr($email, 0, 1).'•••'.substr($email, $arobase);
    }
}
