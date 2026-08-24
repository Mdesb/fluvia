<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvalidSubscriptionTransitionException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'abonnement d'un client à la plateforme : une formule, des options, un état (ED-2).
 *
 * **Les transitions sont gardées ici, pas dans un service.** Un abonnement qui pourrait passer de
 * résilié à actif par une méthode oubliée serait un trou de facturation ; en refusant le passage au
 * niveau de l'objet, aucun appelant ne peut le contourner, pas même par distraction.
 *
 * **Suspendre n'efface rien** (RG-ED-06). Un impayé coupe l'exposition des modules, garde toutes les
 * données, et la régularisation les réexpose. C'est aussi ce qui fait qu'un client résilié reste
 * reconquérable : son établissement existe encore, éteint.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_subscription')]
class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * Référence du client côté CRM de l'éditeur (RG-ED-02).
     *
     * Chaîne et non relation : le CRM appartient à un autre module, et un abonnement n'a pas à
     * importer son entité pour exister — c'est la même discipline que celle du bus d'événements.
     */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $customerReference = '';

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Plan $plan = null;

    #[ORM\Column(length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Draft;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Renseigné au passage à `Active` : c'est la date qui fait foi pour la facturation. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** @var Collection<int, SubscriptionItem> */
    #[ORM\OneToMany(mappedBy: 'subscription', targetEntity: SubscriptionItem::class, cascade: ['persist'])]
    private Collection $items;

    /**
     * Le paramétrage réalisé en démo, exporté au moment de la souscription (RG-ED-08, D11).
     *
     * **Rangé ici et pas laissé dans l'établissement de démo**, parce que c'est un export et non une
     * copie de base : la démo reste un bac à sable jetable, qu'on peut détruire sans que le client
     * perde ce qu'il a configuré. C'est aussi ce qui évite d'avoir à faire expirer et purger des
     * milliers d'établissements fantômes non convertis.
     *
     * Configuration seulement — offres, tarifs, horaires. Jamais de données personnelles.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $demoConfiguration = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    /**
     * Change d'état, ou refuse.
     *
     * @throws InvalidSubscriptionTransitionException
     */
    public function transitionTo(SubscriptionStatus $cible, \DateTimeImmutable $quand): self
    {
        if ($this->status === $cible) {
            return $this;
        }

        if (!$this->status->canTransitionTo($cible)) {
            throw new InvalidSubscriptionTransitionException(sprintf(
                'Passage impossible de « %s » à « %s ». Depuis « %s », les seuls états atteignables sont : %s.',
                $this->status->value,
                $cible->value,
                $this->status->value,
                $this->transitionsLisibles(),
            ));
        }

        if (SubscriptionStatus::Active === $cible && null === $this->startedAt) {
            $this->startedAt = $quand;
        }

        if (SubscriptionStatus::Cancelled === $cible) {
            $this->endedAt = $quand;
        }

        $this->status = $cible;

        return $this;
    }

    /**
     * Ajoute une option prenant effet à la date donnée.
     *
     * Ré-ajouter une option déjà courante ne fait rien : l'opération doit pouvoir être rejouée sans
     * créer de doublon facturable — un webhook de paiement se répète (RG-ED-05).
     */
    public function addOption(string $capability, int $unitPriceCents, \DateTimeImmutable $depuis): self
    {
        if ($this->hasRunningOption($capability, $depuis)) {
            return $this;
        }

        $item = (new SubscriptionItem())
            ->setSubscription($this)
            ->setCapability($capability)
            ->setUnitPriceCents($unitPriceCents)
            ->setActiveFrom($depuis);

        $this->items->add($item);

        return $this;
    }

    /**
     * Retire une option **à la fin de la période déjà payée**.
     *
     * Couper à l'instant du clic ferait payer un service qu'on vient de retirer. La capacité reste
     * donc exposée jusqu'à `$finDePeriode`, et l'option n'est pas refacturée ensuite.
     */
    public function removeOption(string $capability, \DateTimeImmutable $finDePeriode): self
    {
        foreach ($this->items as $item) {
            if ($item->getCapability() === $capability && null === $item->getActiveTo()) {
                $item->setActiveTo($finDePeriode);
            }
        }

        return $this;
    }

    /**
     * Les capacités auxquelles le client a droit à cet instant : la formule plus ses options courantes.
     *
     * Un abonnement suspendu ou résilié n'en donne **aucune** — c'est l'exposition qui s'arrête, les
     * données restent (RG-ED-06). C'est cette liste que le provisionnement applique.
     *
     * @return list<string> triées, dédoublonnées
     */
    public function activeCapabilities(\DateTimeImmutable $instant): array
    {
        if (!$this->status->grantsAccess()) {
            return [];
        }

        $capacites = $this->plan?->getIncludedCapabilities() ?? [];

        foreach ($this->items as $item) {
            if ($item->isActiveAt($instant)) {
                $capacites[] = $item->getCapability();
            }
        }

        $capacites = array_values(array_unique($capacites));
        sort($capacites);

        return $capacites;
    }

    private function hasRunningOption(string $capability, \DateTimeImmutable $instant): bool
    {
        foreach ($this->items as $item) {
            if ($item->getCapability() === $capability && $item->isActiveAt($instant)) {
                return true;
            }
        }

        return false;
    }

    private function transitionsLisibles(): string
    {
        $etats = array_map(
            static fn (SubscriptionStatus $s): string => $s->value,
            $this->status->allowedTransitions(),
        );

        return [] === $etats ? 'aucun (état final)' : implode(', ', $etats);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCustomerReference(): string
    {
        return $this->customerReference;
    }

    public function setCustomerReference(string $customerReference): self
    {
        $this->customerReference = $customerReference;

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** @return Collection<int, SubscriptionItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return array<string, mixed>|null */
    public function getDemoConfiguration(): ?array
    {
        return $this->demoConfiguration;
    }

    /** @param array<string, mixed>|null $demoConfiguration */
    public function setDemoConfiguration(?array $demoConfiguration): self
    {
        $this->demoConfiguration = $demoConfiguration;

        return $this;
    }
}
