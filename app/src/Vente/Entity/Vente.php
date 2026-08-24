<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Caisse\Entity\SessionCaisse;
use App\Organisation\Entity\Etablissement;
use App\Vente\Enum\StatutVente;
use App\Vente\State\AjoutLigneProcessor;
use App\Vente\State\AnnulerVenteProcessor;
use App\Vente\State\CreerVenteProcessor;
use App\Vente\State\ModifierLigneProcessor;
use App\Vente\State\PaiementProcessor;
use App\Vente\State\RattacherClientProcessor;
use App\Vente\State\RembourserVenteProcessor;
use App\Vente\State\RetirerLigneProcessor;
use App\Vente\State\TicketProcessor;
use App\Vente\State\ValiderVenteProcessor;
use App\Vente\State\ViderPanierProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Vente / Ticket (« Commande » au cahier, US-L2-01). Panier composé en session ouverte, encaissé
 * par paiement scindé jusqu'à reste dû = 0 (RG-M2-03), puis validé et scellé (NF525). Après
 * validation, la vente est figée (immuabilité RG-M2-07 / CA-15) ; toute correction passe par
 * contre-passation (avoir/remboursement). Portée par la clé d'idempotence pour la resynchro
 * hors-ligne sans doublon (RG-M2-08).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_vente')]
#[ORM\UniqueConstraint(name: 'uniq_vente_numero', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_vente_cle_idempotence', columns: ['cle_idempotence'])]
#[ApiResource(
    shortName: 'Vente',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
        new Post(
            uriTemplate: '/ventes',
            read: false,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: CreerVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/lignes',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: AjoutLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/modifier-ligne',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: ModifierLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/retirer-ligne',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: RetirerLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/vider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: ViderPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/client',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: RattacherClientProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/paiements',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.encaisser')",
            processor: PaiementProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.encaisser')",
            processor: ValiderVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.annuler')",
            processor: AnnulerVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/rembourser',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.rembourser')",
            processor: RembourserVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/ticket',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.lire')",
            processor: TicketProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['vente:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['session' => 'exact', 'statut' => 'exact', 'numero' => 'exact'])]
class Vente
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vente:read', 'avoir:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Groups(['vente:read'])]
    private string $numero = '';

    #[ORM\ManyToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['vente:read'])]
    private ?SessionCaisse $session = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['vente:read'])]
    private \DateTimeImmutable $date;

    /** Réf. logique Client (M4) — pas de FK dure ; vente anonyme si null (US-L2-05). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $client = null;

    #[ORM\Column(length: 16, enumType: StatutVente::class, options: ['default' => 'en_cours'])]
    #[Groups(['vente:read'])]
    private StatutVente $statut = StatutVente::EnCours;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $total = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $totalRemises = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $resteAPayer = '0.00';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read'])]
    private bool $origineHorsLigne = false;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['vente:read'])]
    private Uuid $cleIdempotence;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['vente:read'])]
    private ?Etablissement $etablissement = null;

    /** Vrai si le ticket a été imprimé (conditionne l'invalidation de support à l'annulation). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read'])]
    private bool $imprime = false;

    /** @var Collection<int, LigneVente> */
    #[ORM\OneToMany(targetEntity: LigneVente::class, mappedBy: 'vente', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['vente:read'])]
    private Collection $lignes;

    /** @var Collection<int, Paiement> */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'vente', cascade: ['persist'])]
    #[Groups(['vente:read'])]
    private Collection $paiements;

    /** @var Collection<int, BilletSupport> */
    #[ORM\OneToMany(targetEntity: BilletSupport::class, mappedBy: 'vente', cascade: ['persist'])]
    #[Groups(['vente:read'])]
    private Collection $supports;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->date = new \DateTimeImmutable();
        $this->cleIdempotence = Uuid::v4();
        $this->lignes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->supports = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    public function setSession(?SessionCaisse $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getClient(): ?Uuid
    {
        return $this->client;
    }

    public function setClient(?Uuid $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getStatut(): StatutVente
    {
        return $this->statut;
    }

    public function setStatut(StatutVente $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getTotalRemises(): string
    {
        return $this->totalRemises;
    }

    public function setTotalRemises(string $totalRemises): self
    {
        $this->totalRemises = $totalRemises;

        return $this;
    }

    public function getResteAPayer(): string
    {
        return $this->resteAPayer;
    }

    public function setResteAPayer(string $resteAPayer): self
    {
        $this->resteAPayer = $resteAPayer;

        return $this;
    }

    public function isOrigineHorsLigne(): bool
    {
        return $this->origineHorsLigne;
    }

    public function setOrigineHorsLigne(bool $origineHorsLigne): self
    {
        $this->origineHorsLigne = $origineHorsLigne;

        return $this;
    }

    public function getCleIdempotence(): Uuid
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(Uuid $cleIdempotence): self
    {
        $this->cleIdempotence = $cleIdempotence;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function isImprime(): bool
    {
        return $this->imprime;
    }

    public function setImprime(bool $imprime): self
    {
        $this->imprime = $imprime;

        return $this;
    }

    /** @return Collection<int, LigneVente> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneVente $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setVente($this);
        }

        return $this;
    }

    public function removeLigne(LigneVente $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    /** @return Collection<int, Paiement> */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): self
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setVente($this);
        }

        return $this;
    }

    /** @return Collection<int, BilletSupport> */
    public function getSupports(): Collection
    {
        return $this->supports;
    }

    public function addSupport(BilletSupport $support): self
    {
        if (!$this->supports->contains($support)) {
            $this->supports->add($support);
            $support->setVente($this);
        }

        return $this;
    }

    /**
     * Retire un support de la collection. Utilisé par `ValiderVenteService::valider()` pour couper la
     * cascade `persist` lorsqu'un support cree dans la transaction de validation doit etre abandonne
     * apres un rollback SQL (l'UnitOfWork ne se vide pas tout seul) — sinon un flush ulterieur de
     * l'appelant reinsererait un support orphelin.
     */
    public function removeSupport(BilletSupport $support): self
    {
        $this->supports->removeElement($support);

        return $this;
    }

    public function estScellee(): bool
    {
        return $this->statut->estScellee();
    }
}
