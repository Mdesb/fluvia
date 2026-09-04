<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\ExpiredConfirmationLinkException;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Exception\InvalidSubscriptionTransitionException;
use App\Subscription\Exception\MissingMandateException;
use App\Subscription\Exception\UnknownConfirmationTokenException;
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
    /**
     * Durée de l'essai gratuit, arbitrée par Maxime le 04/09/2026.
     *
     * Quatorze jours : le temps d'évaluer l'outil. L'établissement n'aura pas vu passer une fin de
     * mois — c'est le prix de la brièveté, et c'était le choix.
     */
    public const TRIAL_DAYS = 14;

    /**
     * Fenêtre pendant laquelle le lien de confirmation reste actionnable.
     *
     * Alignée sur la validité d'une invitation d'utilisateur (72 h) : c'est le même geste — prouver
     * qu'on lit une adresse — et deux durées différentes pour le même geste se justifieraient mal
     * auprès de celui qui les découvre.
     */
    public const CONFIRMATION_HOURS = 72;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OfferCatalog $catalog,
        private readonly EditorTenantResolver $editorTenant,
        private readonly SubscriptionActivator $activator,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
        private readonly DemoConfiguration $demoConfiguration,
        private readonly EventBus $bus,
        private readonly SubscriptionMandates $mandates,
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
        ?Etablissement $demo = null,
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

        // Le paramétrage de démo est prélevé **maintenant**, pas au provisionnement (RG-ED-08, D11) :
        // entre les deux, le prospect peut abandonner et son bac à sable être détruit. Ce qu'on range
        // ici est un document autonome, qui survit à sa source.
        if (null !== $demo) {
            $subscription->setDemoConfiguration($this->demoConfiguration->capture($demo));
        }

        foreach ($extras as $capability) {
            $subscription->addOption($capability, $options[$capability]->getMonthlyPriceCents(), $at);
        }

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /**
     * Étape 2 bis — le prospect demande son essai gratuit, et on lui écrit pour vérifier l'adresse.
     *
     * **Rien n'est créé ici, et c'est tout l'intérêt.** L'abonnement reste en brouillon : aucun
     * établissement, aucun compte, aucune facture. Ce que déclenche cet appel est un courriel, et
     * c'est le clic sur son lien — donc la preuve que l'adresse existe et appartient au demandeur —
     * qui ouvrira la plateforme.
     *
     * **Le jeton n'est pas frappé ici.** Il l'est par celui qui l'envoie, au moment de composer le
     * message ({@see \App\Subscription\EventListener\SendTrialConfirmationEmail}) : un
     * événement se journalise, se rejoue et se transporte, et un secret qui voyage dans ces
     * conditions finit par vivre dans un fichier de journal. Même règle que le courriel de bienvenue.
     *
     * @throws InvalidSubscriptionTransitionException si l'abonnement n'est plus au panier
     */
    public function requestTrial(Subscription $subscription, \DateTimeImmutable $at): void
    {
        $this->assertStillInCart($subscription, 'demander un essai gratuit');

        $editor = $this->editorTenant->resolve();

        $this->bus->publish(new DomainEvent(
            'subscription.trial_requested',
            new EventTenant($editor->getId()),
            new EventSubject('Subscription', $subscription->getId()->toRfc4122()),
            [
                'planCode' => $subscription->getPlan()?->getCode() ?? '',
                'trialDays' => self::TRIAL_DAYS,
            ],
            null,
            $at,
        ));
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
        $this->assertMandateSignable($subscription, $at);

        $editor = $this->editorTenant->resolve();
        $prospect = $this->customerOf($subscription);

        $rum = $this->mandates->reference($subscription);
        $mandate = $this->mandates->any($subscription) ?? new MandatSepa();

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
     * Étape 3 bis — l'adresse est confirmée, l'essai gratuit commence, le provisionnement suit.
     *
     * **Le chemin de l'essai ne passe pas par `confirmPayment()`, et ce n'est pas un raccourci.**
     * Celle-ci exige un mandat actif parce qu'elle ouvre un service payant ; l'essai n'en exige pas
     * parce qu'il n'est pas payé. Ce qui remplace le mandat comme garde, c'est **la confirmation de
     * l'adresse** : sans elle, ce formulaire public créerait autant d'établissements réels — groupe,
     * région, administrateur — qu'on lui envoie de requêtes.
     *
     * **Un second clic sur le lien ne fait rien, et surtout n'échoue pas.** Les clients de messagerie
     * pré-visitent les liens, les gens cliquent deux fois, et un lien de confirmation qui répond
     * « erreur » au deuxième passage fait croire à un échec alors que tout a marché. On rend donc le
     * même abonnement, sans le réactiver.
     *
     * ⚠ **L'EMPREINTE DU JETON N'EST PAS EFFACÉE APRÈS USAGE, ET C'EST DÉLIBÉRÉ.** L'effacer était
     * ma première version : elle rendait le paragraphe ci-dessus impossible à tenir, puisque le
     * second clic ne retrouvait plus rien et tombait sur « lien inconnu ». Ce qui rend le jeton
     * inoffensif après coup n'est pas son effacement, c'est `emailConfirmedAt` : à partir de là il
     * n'ouvre plus rien, il ne fait plus que désigner une demande déjà servie.
     *
     * **Ce qui borne le jeton, c'est le temps** — {@see self::CONFIRMATION_HOURS}. Un lien qui ouvre
     * un établissement réel ne doit pas rester actionnable des mois dans une boîte de messagerie
     * qu'on ne maîtrise pas.
     *
     * @throws UnknownConfirmationTokenException si le jeton ne désigne rien
     * @throws ExpiredConfirmationLinkException  si le lien a dépassé sa fenêtre de validité
     */
    public function confirmEmailAndStartTrial(string $clearToken, \DateTimeImmutable $at): Subscription
    {
        $subscription = $this->em->getRepository(Subscription::class)->findOneBy([
            'emailConfirmationTokenHash' => hash('sha256', $clearToken),
        ]);

        if (!$subscription instanceof Subscription) {
            throw new UnknownConfirmationTokenException(
                'Aucune demande d\'essai ne correspond à ce lien de confirmation.'
            );
        }

        // Déjà confirmée : l'essai court, l'établissement existe. Rejouer l'activation lèverait une
        // transition invalide sur un parcours qui, du point de vue du prospect, a réussi.
        if (null !== $subscription->getEmailConfirmedAt()) {
            return $subscription;
        }

        $limite = $subscription->getCreatedAt()->modify(sprintf('+%d hours', self::CONFIRMATION_HOURS));
        if ($at > $limite) {
            throw new ExpiredConfirmationLinkException(sprintf(
                'Lien de confirmation expiré : demandé le %s, valable %d heures.',
                $subscription->getCreatedAt()->format('Y-m-d H:i'),
                self::CONFIRMATION_HOURS,
            ));
        }

        $subscription
            ->setEmailConfirmedAt($at)
            ->setTrialEndsAt($at->modify(sprintf('+%d days', self::TRIAL_DAYS)));

        $this->activator->activate($subscription, $at);

        return $subscription;
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

        $mandate = $this->mandates->active($subscription);

        if (null === $mandate) {
            throw new MissingMandateException(sprintf(
                'Abonnement « %s » : aucun mandat SEPA actif. Activer sans mandat ouvrirait un service '
                .'que rien ne paie, et ferait relancer un client qui n\'a jamais donné d\'autorisation.',
                $subscription->getId()->toRfc4122(),
            ));
        }

        $this->activator->activate($subscription, $at);
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
    /**
     * Un mandat se signe au panier — ou pendant un essai gratuit.
     *
     * ⚠ **CES DEUX RÈGLES SE CONTREDISAIENT, ET LA CONTRADICTION N'ÉTAIT PAS VISIBLE D'UN SEUL CÔTÉ.**
     * `assertStillInCart()` refuse tout ce qui n'est pas un brouillon, pour la bonne raison qu'on ne
     * rejoue pas des étapes de vente sur un contrat en cours. Mais la bascule payante décidée le
     * 04/09 suppose exactement l'inverse : le client signe **pendant** son essai, donc sur un
     * abonnement déjà `active`. Sans cette ouverture, un essai ne pouvait jamais devenir payant — et
     * on ne s'en apercevait qu'au quatorzième jour, quand tout le monde est suspendu.
     *
     * L'ouverture porte sur l'**essai en cours**, pas sur « actif ». Un abonnement payant en cours
     * n'est pas concerné : y changer des coordonnées bancaires est un geste de gestion, qui a son
     * écran et son autorisation, et sûrement pas le tunnel de vente.
     *
     * @throws InvalidSubscriptionTransitionException
     */
    private function assertMandateSignable(Subscription $subscription, \DateTimeImmutable $at): void
    {
        if ($subscription->isInTrial($at)) {
            return;
        }

        $this->assertStillInCart($subscription, 'signer un mandat');
    }

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
