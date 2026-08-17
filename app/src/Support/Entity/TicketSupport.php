<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Support\Enum\NiveauAffectation;
use App\Support\Enum\PrioriteTicket;
use App\Support\Enum\StatutTicket;
use App\Support\Security\TicketSoiVoter;
use App\Support\State\EscaladerTicketProcessor;
use App\Support\State\LierArticleTicketProcessor;
use App\Support\State\OuvrirTicketProcessor;
use App\Support\State\PrendreEnChargeTicketProcessor;
use App\Support\State\ReaffecterTicketProcessor;
use App\Support\State\RouvrirTicketProcessor;
use App\Support\State\StatutTicketProcessor;
use App\Support\State\TableauBordTicketProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ticket de support léger (US-SUP-09 à 14, RG-SUP-09 à 14) : ouverture par un exploitant
 * authentifié, cycle de vie nouveau→en_cours→[en_attente_client]→résolu→fermé, affectation/escalade
 * N1/N2, lien optionnel vers des `ArticleAide` (table de jointure `support_ticket_article_lie`,
 * modélisée en `ManyToMany` directe — pas d'entité `TicketArticleLie` dédiée, simplification sans
 * perte fonctionnelle, la table porte le nom prévu au plan §1.4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_ticket_support')]
#[ApiResource(
    shortName: 'TicketSupport',
    operations: [
        new GetCollection(
            uriTemplate: '/support/tickets',
            security: "is_granted('PERM', 'support.lire_ticket_soi') or is_granted('PERM', 'support.lire_ticket_etablissement') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
        ),
        new GetCollection(
            uriTemplate: '/support/tickets/tableau-de-bord',
            security: "is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
            provider: TableauBordTicketProvider::class,
        ),
        new Get(
            uriTemplate: '/support/tickets/{id}',
            security: "is_granted('" . TicketSoiVoter::ATTRIBUTE . "', object) or is_granted('PERM', 'support.lire_ticket_etablissement') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
        ),
        new Post(
            uriTemplate: '/support/tickets',
            security: "is_granted('PERM', 'support.ouvrir_ticket')",
            processor: OuvrirTicketProcessor::class,
            denormalizationContext: ['groups' => ['ticket:write']],
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/prendre-en-charge',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2')",
            processor: PrendreEnChargeTicketProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/statut',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2')",
            processor: StatutTicketProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/rouvrir',
            read: true,
            input: false,
            security: "is_granted('" . TicketSoiVoter::ATTRIBUTE . "', object) or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
            processor: RouvrirTicketProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/escalader',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.traiter_ticket_n1')",
            processor: EscaladerTicketProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/reaffecter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.traiter_ticket_n2')",
            processor: ReaffecterTicketProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{id}/lier-article',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2')",
            processor: LierArticleTicketProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ticket:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'priorite' => 'exact', 'moduleConcerne' => 'partial', 'niveauAffectation' => 'exact', 'affecteA' => 'exact', 'etablissement' => 'exact'])]
class TicketSupport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ticket:read'])]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Groups(['ticket:read', 'ticket:write'])]
    private string $sujet = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['ticket:read', 'ticket:write'])]
    private string $description = '';

    #[ORM\Column(length: 8, enumType: PrioriteTicket::class, options: ['default' => 'normale'])]
    #[Groups(['ticket:read', 'ticket:write'])]
    private PrioriteTicket $priorite = PrioriteTicket::Normale;

    #[ORM\Column(length: 20, enumType: StatutTicket::class, options: ['default' => 'nouveau'])]
    #[Groups(['ticket:read'])]
    private StatutTicket $statut = StatutTicket::Nouveau;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?string $moduleConcerne = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ticket:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ticket:read'])]
    private ?Utilisateur $demandeur = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'affecte_a_id', nullable: true)]
    #[Groups(['ticket:read'])]
    private ?Utilisateur $affecteA = null;

    #[ORM\Column(length: 2, enumType: NiveauAffectation::class, nullable: true)]
    #[Groups(['ticket:read'])]
    private ?NiveauAffectation $niveauAffectation = null;

    /** @var Collection<int, ArticleAide> */
    #[ORM\ManyToMany(targetEntity: ArticleAide::class)]
    #[ORM\JoinTable(name: 'support_ticket_article_lie')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'article_id', referencedColumnName: 'id')]
    #[Groups(['ticket:read'])]
    private Collection $articlesLies;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['ticket:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['ticket:read'])]
    private \DateTimeImmutable $dateDerniereMaj;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['ticket:read'])]
    private ?\DateTimeImmutable $dateResolution = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['ticket:read'])]
    private ?\DateTimeImmutable $dateFermeture = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['ticket:read'])]
    private ?string $motifFermeture = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateDerniereMaj = $this->dateCreation;
        $this->articlesLies = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSujet(): string
    {
        return $this->sujet;
    }

    public function setSujet(string $sujet): self
    {
        $this->sujet = $sujet;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPriorite(): PrioriteTicket
    {
        return $this->priorite;
    }

    public function setPriorite(PrioriteTicket $priorite): self
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function getStatut(): StatutTicket
    {
        return $this->statut;
    }

    public function setStatut(StatutTicket $statut): self
    {
        $this->statut = $statut;
        $this->toucherDateMaj();

        return $this;
    }

    public function getModuleConcerne(): ?string
    {
        return $this->moduleConcerne;
    }

    public function setModuleConcerne(?string $moduleConcerne): self
    {
        $this->moduleConcerne = $moduleConcerne;

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

    public function getDemandeur(): ?Utilisateur
    {
        return $this->demandeur;
    }

    public function setDemandeur(?Utilisateur $demandeur): self
    {
        $this->demandeur = $demandeur;

        return $this;
    }

    public function getAffecteA(): ?Utilisateur
    {
        return $this->affecteA;
    }

    public function setAffecteA(?Utilisateur $affecteA): self
    {
        $this->affecteA = $affecteA;

        return $this;
    }

    public function getNiveauAffectation(): ?NiveauAffectation
    {
        return $this->niveauAffectation;
    }

    public function setNiveauAffectation(?NiveauAffectation $niveauAffectation): self
    {
        $this->niveauAffectation = $niveauAffectation;

        return $this;
    }

    /** @return Collection<int, ArticleAide> */
    public function getArticlesLies(): Collection
    {
        return $this->articlesLies;
    }

    public function ajouterArticleLie(ArticleAide $article): self
    {
        if (!$this->articlesLies->contains($article)) {
            $this->articlesLies->add($article);
        }

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateDerniereMaj(): \DateTimeImmutable
    {
        return $this->dateDerniereMaj;
    }

    public function toucherDateMaj(): void
    {
        $this->dateDerniereMaj = new \DateTimeImmutable();
    }

    public function getDateResolution(): ?\DateTimeImmutable
    {
        return $this->dateResolution;
    }

    public function setDateResolution(?\DateTimeImmutable $dateResolution): self
    {
        $this->dateResolution = $dateResolution;

        return $this;
    }

    public function getDateFermeture(): ?\DateTimeImmutable
    {
        return $this->dateFermeture;
    }

    public function setDateFermeture(?\DateTimeImmutable $dateFermeture): self
    {
        $this->dateFermeture = $dateFermeture;

        return $this;
    }

    public function getMotifFermeture(): ?string
    {
        return $this->motifFermeture;
    }

    public function setMotifFermeture(?string $motifFermeture): self
    {
        $this->motifFermeture = $motifFermeture;

        return $this;
    }
}
