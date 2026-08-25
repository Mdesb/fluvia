<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Subscription\ApiResource\PublicSubscriptionCart;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Security\CartRateLimiter;
use App\Subscription\Service\OfferCatalog;
use App\Subscription\Service\SubscriptionFunnel;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Ouvre un panier de souscription depuis le site vitrine (ED-5, étape 1 du tunnel).
 *
 * **Le seul point d'entrée non authentifié qui écrit dans le CRM de l'éditeur.** D'où l'ordre des
 * opérations ci-dessous, qui n'est pas arbitraire : on borne le débit **avant** de valider, et on
 * valide **avant** d'écrire. Borner en dernier reviendrait à faire le travail coûteux — lecture du
 * catalogue, écriture de la fiche — pour une requête qu'on allait refuser.
 *
 * **Aucun message interne ne sort d'ici, et c'est plus subtil qu'il n'y paraît.** La première version
 * relayait le message d'`InvalidOfferException` en 422, en le croyant « écrit pour un humain ». Il
 * l'est — mais pour l'**éditeur** : « La capacité « casiers » n'est pas vendable en option.
 * Ajoute-la au catalogue d'options avant de la proposer. » Tutoiement, vocabulaire interne, et une
 * consigne d'administration adressée à un prospect qui voulait juste acheter.
 *
 * On rend donc un message écrit pour le visiteur, et on garde l'exception d'origine en cause
 * chaînée : les journaux conservent le détail exact, l'écran public n'en voit rien.
 */
final class OpenCartProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SubscriptionFunnel $funnel,
        private readonly OfferCatalog $catalog,
        private readonly CartRateLimiter $limiter,
        private readonly LecteurCorps $lecteur,
        private readonly RequestStack $requests,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PublicSubscriptionCart
    {
        $this->limiter->assertNotExceeded($this->requests->getCurrentRequest()?->getClientIp());

        $corps = $this->lecteur->corps();

        $raisonSociale = $this->texte($corps, 'companyName');
        $email = $this->texte($corps, 'email');
        $planCode = $this->texte($corps, 'planCode');
        $capacites = $this->capacites($corps);

        if ('' === $raisonSociale || '' === $email || '' === $planCode) {
            throw new UnprocessableEntityHttpException(
                'Il manque le nom de votre structure, votre adresse e-mail ou la formule choisie.'
            );
        }

        // L'adresse sert à inviter l'administrateur après le paiement : une adresse mal formée fait
        // échouer le provisionnement bien plus tard, quand le client a déjà payé.
        if (0 !== \count($this->validator->validate($email, new Email()))) {
            throw new UnprocessableEntityHttpException('Cette adresse e-mail ne paraît pas valide.');
        }

        $maintenant = new \DateTimeImmutable();

        try {
            $subscription = $this->funnel->openCart($raisonSociale, $email, $planCode, $capacites, $maintenant);
            $plan = $subscription->getPlan();
            \assert(null !== $plan);

            $prix = $this->catalog->monthlyPriceCents($plan, $capacites);
        } catch (InvalidOfferException $refus) {
            // Le message d'origine s'adresse à l'éditeur, pas au visiteur : il part en cause chaînée,
            // vers les journaux, et l'écran reçoit une phrase écrite pour lui. Voir le commentaire de
            // classe — c'est un défaut qu'on a introduit une fois.
            throw new UnprocessableEntityHttpException(
                'Cette composition n\'est pas disponible : l\'offre a peut-être changé depuis '
                .'l\'affichage de la page. Rechargez-la, ou écrivez-nous et nous la composons avec vous.',
                $refus,
            );
        }

        $panier = new PublicSubscriptionCart();
        $panier->id = $subscription->getId()->toRfc4122();
        $panier->planCode = $planCode;
        $panier->capabilities = array_values($capacites);
        $panier->monthlyPriceCents = $prix;

        return $panier;
    }

    /** @param array<string, mixed> $corps */
    private function texte(array $corps, string $cle): string
    {
        return \is_string($corps[$cle] ?? null) ? trim($corps[$cle]) : '';
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return list<string>
     */
    private function capacites(array $corps): array
    {
        $brutes = $corps['capabilities'] ?? [];

        if (!\is_array($brutes)) {
            throw new UnprocessableEntityHttpException('« capabilities » doit être une liste de modules.');
        }

        $capacites = [];
        foreach ($brutes as $capacite) {
            if (!\is_string($capacite)) {
                throw new UnprocessableEntityHttpException('« capabilities » doit être une liste de modules.');
            }

            $capacites[] = $capacite;
        }

        // Cocher deux fois la même option ne la facture pas deux fois : `OfferCatalog` dédoublonne
        // déjà, on lui présente une liste propre plutôt que de compter sur lui pour le faire.
        return array_values(array_unique($capacites));
    }
}
