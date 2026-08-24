<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Organisation\Service\EditorTenantResolver;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Exception\InvalidSubscriptionTransitionException;
use App\Subscription\Exception\MissingMandateException;
use App\Subscription\Exception\UnknownCustomerException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le tunnel de souscription : panier → mandat SEPA → confirmation (ED-3, spec §2).
 *
 * Trois étapes, trois appels, et un abonnement qui n'avance jamais tout seul. Le prospect peut
 * abandonner après chacune : c'est le cas nominal d'un tunnel de vente, pas une erreur. Ce qu'il
 * laisse derrière lui — une fiche client, un abonnement en brouillon, un mandat orphelin — est
 * inoffensif tant qu'aucun établissement n'est créé, et **rien n'est créé avant la confirmation**.
 *
 * **Tout vit dans le périmètre de l'éditeur** (RG-ED-01, D12). Le prospect n'a pas d'établissement :
 * il en aura un quand il aura payé, et c'est le provisionnement qui le crée. Sa fiche, son abonnement
 * et son mandat appartiennent donc au tenant de l'éditeur, désigné par {@see EditorTenantResolver}
 * (Q-1, D36) — pas déduit, pas replié sur une valeur par défaut.
 *
 * **La confirmation n'encaisse pas.** Elle constate que le prélèvement est possible — un mandat actif
 * existe — et active l'abonnement. L'encaissement réel relève de `Sepa` et de ses remises ; le
 * confondre avec l'activation ferait dépendre l'ouverture du service du calendrier interbancaire,
 * alors que le client veut sa plateforme le jour où il signe.
 */
final class SubscriptionFunnel
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OfferCatalog $catalog,
        private readonly EditorTenantResolver $editorTenant,
        private readonly SubscriptionActivator $activator,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
    ) {
    }

    /**
     * Étape 1 — le prospect compose son panier.
     *
     * La composition est **validée avant d'être enregistrée** : `OfferCatalog` refuse une capacité
     * inconnue du catalogue technique ou non mise en vente (RG-ED-03). Enregistrer d'abord et
     * vérifier ensuite produirait un abonnement vendu qu'on ne saurait pas livrer — et on ne s'en
     * apercevrait qu'après le paiement.
     *
     * Seuls les **suppléments** deviennent des options : ce que la formule comprend est déjà payé,
     * et le facturer une seconde fois est le genre d'erreur qu'un client relève avant nous.
     *
     * @param list<string> $capabilities capacités voulues, formule comprise
     *
     * @throws InvalidOfferException si la formule ou une capacité n'est pas vendable
     */
    public function openCart(
        string $companyName,
        string $email,
        string $planCode,
        array $capabilities,
        \DateTimeImmutable $at,
    ): Subscription {
        $editor = $this->editorTenant->resolve();

        $plan = $this->catalog->planByCode($planCode);
        if (null === $plan || !$plan->isActive()) {
            throw new InvalidOfferException(sprintf('Formule « %s » inconnue ou hors vente.', $planCode));
        }

        $this->catalog->assertPlanIsCoherent($plan);
        $extras = $this->catalog->billableExtras($plan, $capabilities);
        $options = $this->catalog->activeOptions();

        $prospect = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($companyName)
            ->setEmail($email)
            ->setGroupe($editor->getRegion()?->getGroupe())
            ->setEtablissementCreation($editor);
        $this->em->persist($prospect);
        $this->em->flush(); // l'identifiant du prospect est la référence portée par l'abonnement

        $subscription = (new Subscription())
            ->setCustomerReference($prospect->getId()->toRfc4122())
            ->setPlan($plan);

        foreach ($extras as $capability) {
            $subscription->addOption($capability, $options[$capability]->getMonthlyPriceCents(), $at);
        }

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /**
     * Étape 2 — le prospect signe son mandat de prélèvement.
     *
     * **Le RUM est dérivé de l'abonnement, pas tiré au hasard.** Un prospect qui se trompe d'IBAN
     * recommence ; un formulaire renvoyé deux fois arrive deux fois. Avec un RUM aléatoire, chaque
     * passage créerait un mandat de plus pour le même client, et la remise suivante ne saurait pas
     * lequel présenter. Dérivé, il désigne toujours le même mandat : on le met à jour, et une
     * signature rejouée est sans effet de bord.
     *
     * L'IBAN n'est jamais stocké en clair : jeton pour la recherche, chiffré pour la restitution,
     * quatre derniers chiffres pour l'affichage — c'est ce que fait déjà tout le module `Sepa`.
     *
     * @throws InvalidSubscriptionTransitionException si l'abonnement n'est plus au panier
     */
    public function signMandate(
        Subscription $subscription,
        string $iban,
        string $bic,
        string $holderName,
        \DateTimeImmutable $at,
    ): MandatSepa {
        $this->assertStillInCart($subscription, 'signer un mandat');

        $editor = $this->editorTenant->resolve();
        $prospect = $this->customerOf($subscription);

        $rum = $this->mandateReference($subscription);
        $mandate = $this->em->getRepository(MandatSepa::class)->findOneBy(['rum' => $rum]) ?? new MandatSepa();

        $token = $this->tokenisation->tokeniser($iban);

        $mandate->setRum($rum)
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer($iban))
            ->setBicDebiteur($bic)
            ->setDebiteurNom($holderName)
            ->setDateSignature($at)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($prospect)
            ->setEtablissement($editor);

        $this->em->persist($mandate);
        $this->em->flush();

        return $mandate;
    }

    /**
     * Étape 3 — le paiement est confirmé, l'abonnement s'active, le provisionnement suit.
     *
     * On vérifie qu'un mandat **actif** existe avant d'activer. Sans lui, on ouvrirait un service
     * qu'aucun prélèvement ne viendra payer, et le recouvrement partirait d'un client qui n'a jamais
     * donné d'autorisation — ce qui n'est pas un impayé, c'est une erreur de notre côté.
     *
     * Cette méthode n'appelle pas le provisionnement : elle active, l'activation annonce le fait, et
     * l'abonné du bus provisionne (RG-ED-04). Le tunnel ignore jusqu'à son existence.
     *
     * @throws InvalidSubscriptionTransitionException si l'abonnement n'est plus au panier
     * @throws MissingMandateException               si aucun mandat actif n'a été signé
     */
    public function confirmPayment(Subscription $subscription, \DateTimeImmutable $at): void
    {
        $this->assertStillInCart($subscription, 'confirmer le paiement');

        $mandate = $this->em->getRepository(MandatSepa::class)->findOneBy([
            'rum' => $this->mandateReference($subscription),
            'statut' => StatutMandatSepa::Actif,
        ]);

        if (!$mandate instanceof MandatSepa) {
            throw new MissingMandateException(sprintf(
                'Abonnement « %s » : aucun mandat SEPA actif. Activer sans mandat ouvrirait un service '
                .'que rien ne paie, et ferait relancer un client qui n\'a jamais donné d\'autorisation.',
                $subscription->getId()->toRfc4122(),
            ));
        }

        $this->activator->activate($subscription, $at);
    }

    /**
     * La référence de mandat de cet abonnement — déterministe, et dans les 35 caractères de la colonne.
     *
     * Vingt-quatre caractères hexadécimaux tirés de l'identifiant de l'abonnement : c'est le même
     * identifiant qui porte l'idempotence du provisionnement, donc deux mécanismes qui ne peuvent pas
     * se désynchroniser.
     */
    private function mandateReference(Subscription $subscription): string
    {
        return 'RUM-ED-'.strtoupper(substr(str_replace('-', '', $subscription->getId()->toRfc4122()), 0, 24));
    }

    private function customerOf(Subscription $subscription): Client
    {
        $prospect = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());
        if (!$prospect instanceof Client) {
            throw new UnknownCustomerException(sprintf(
                'Abonnement « %s » : aucune fiche client « %s ».',
                $subscription->getId()->toRfc4122(),
                $subscription->getCustomerReference(),
            ));
        }

        return $prospect;
    }

    /**
     * Le tunnel ne revient pas en arrière.
     *
     * Resigner un mandat ou reconfirmer un abonnement déjà actif rejouerait des étapes de vente sur
     * un contrat en cours. L'abonnement actif se modifie par ajout et retrait d'options (ED-2), pas
     * en repassant par le tunnel.
     */
    private function assertStillInCart(Subscription $subscription, string $action): void
    {
        if (SubscriptionStatus::Draft === $subscription->getStatus()) {
            return;
        }

        throw new InvalidSubscriptionTransitionException(sprintf(
            'Impossible de %s : l\'abonnement « %s » est « %s » et non « brouillon ». Le tunnel ne se '
            .'rejoue pas sur un contrat en cours.',
            $action,
            $subscription->getId()->toRfc4122(),
            $subscription->getStatus()->value,
        ));
    }
}
