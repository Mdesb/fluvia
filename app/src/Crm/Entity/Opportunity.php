<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\LossReason;
use App\Crm\Enum\OpportunityStage;
use App\Crm\State\CrmEstablishmentStampProcessor;
use App\Crm\State\PipelineProvider;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE AFFAIRE EN COURS — ce qui vit entre « un client appelle » et « un devis part ».
 *
 * **Le trou que ça comble.** La chaîne devis → commande → facture → relance était déjà écrite et
 * branchée. Ce qui manquait est *avant* : une demande qualifiée, un montant estimé, une échéance. Sans
 * ça, une affaire n'existe dans le logiciel qu'au moment où quelqu'un rédige un devis — donc toutes
 * celles qui n'y arrivent pas n'ont jamais existé, et on ne peut rien en apprendre.
 *
 * **Le montant est PRÉVISIONNEL, et le mot compte.** `estimatedAmount` n'engage rien et ne se
 * confronte à aucune règle tarifaire : c'est ce que le commercial pense vendre. Le montant réel naît
 * avec le devis, et le devis fait foi dès qu'il existe. Les afficher dans la même colonne serait la
 * même faute que le prix indicatif corrigé à la caisse cette semaine.
 *
 * ---
 *
 * **LA RÉFÉRENCE AU DEVIS EST UN `?Uuid` NU, PAS UNE RELATION.**
 *
 * `CommercialDocument` vit dans `App\Facturation`. Une relation Doctrine créerait une dépendance de
 * mapping entre deux modules qui doivent vivre séparément (D2) — c'est la convention du dépôt, suivie
 * par vingt-trois propriétés.
 *
 * ⚠ Le prix de cette convention est D58 : sur une colonne `uuid` nue, Doctrine ne convertit pas, **et
 * ne s'en plaint pas**. Toute lecture par cette référence doit lier son paramètre avec le type
 * `'uuid'` explicite. `PipelineProvider` le fait ; personne ne doit l'oublier ailleurs.
 *
 * ---
 *
 * **L'ÉTAPE STOCKÉE N'EST PAS TOUJOURS L'ÉTAPE VRAIE, ET C'EST VOULU.**
 *
 * Dès qu'un devis est rattaché, c'est **lui** qui dit où en est l'affaire : émis, accepté, refusé.
 * L'étape stockée ne gouverne plus que la période d'avant. `PipelineProvider` rend donc une étape
 * *effective*, calculée ; rien n'est recopié dans cette table.
 *
 * Recopier aurait été plus simple à lire et faux à l'usage : une copie prend du retard, et une étape
 * en retard est pire qu'absente — elle a l'air d'être à jour.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_opportunity')]
#[ORM\Index(name: 'idx_crm_opportunity_pipeline', columns: ['establishment_id', 'stage'])]
#[ApiResource(
    shortName: 'Opportunity',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
        new Post(
            security: "is_granted('PERM', 'crm.creer') or is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['opportunity:write']],
            processor: CrmEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['opportunity:write']],
        ),
        // LE TABLEAU, colonne par colonne, avec l'etape EFFECTIVE lue du devis.
        new GetCollection(
            uriTemplate: '/crm/pipeline',
            security: "is_granted('PERM', 'crm.lire')",
            provider: PipelineProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['opportunity:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['stage' => 'exact'])]
class Opportunity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['opportunity:read'])]
    private Uuid $id;

    /**
     * Posé par `CrmEstablishmentStampProcessor`, jamais par le corps de la requête (D41).
     *
     * Exposé en écriture, l'appelant choisirait dans quel pipeline atterrit son affaire — et lirait
     * ensuite le prévisionnel d'un autre établissement.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['opportunity:read'])]
    private ?Etablissement $establishment = null;

    /**
     * La société ou la personne concernée.
     *
     * Nullable : une demande arrive souvent **avant** que le client existe en base — un courriel, un
     * appel. Exiger le client dès la création ferait saisir une fiche pour une affaire qui n'ira
     * peut-être nulle part, et l'affaire ne serait pas saisie du tout.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private ?Client $customer = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private string $title = '';

    #[ORM\Column(length: 20, enumType: OpportunityStage::class)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private OpportunityStage $stage = OpportunityStage::ToQualify;

    /** Ce que le commercial pense vendre. N'engage rien : voir le docbloc de classe. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private string $estimatedAmount = '0.00';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private ?\DateTimeImmutable $expectedCloseDate = null;

    /** Référence libre vers `App\Facturation\Entity\CommercialDocument` — voir le docbloc de classe. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private ?Uuid $commercialDocumentRef = null;

    #[ORM\Column(length: 20, nullable: true, enumType: LossReason::class)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private ?LossReason $lossReason = null;

    /** Le détail. La liste porte le décompte, le commentaire porte l'histoire. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['opportunity:read', 'opportunity:write'])]
    private ?string $lossComment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['opportunity:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['opportunity:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getCustomer(): ?Client
    {
        return $this->customer;
    }

    public function setCustomer(?Client $customer): self
    {
        $this->customer = $customer;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getStage(): OpportunityStage
    {
        return $this->stage;
    }

    /**
     * ⚠ Seules les étapes que l'humain peut poser sont acceptées.
     *
     * `QuoteSent` et `Won` sont des **conséquences** de l'état du devis. Les laisser poser à la main
     * permettrait de déclarer une affaire gagnée sans devis accepté — c'est-à-dire de fabriquer un
     * prévisionnel qui ne correspond à aucun engagement, et de le découvrir au moment de facturer.
     */
    public function setStage(OpportunityStage $stage): self
    {
        if (!$stage->manuallySettable()) {
            return $this;
        }

        $this->stage = $stage;
        $this->touch();

        return $this;
    }

    public function getEstimatedAmount(): string
    {
        return $this->estimatedAmount;
    }

    public function setEstimatedAmount(string $estimatedAmount): self
    {
        $this->estimatedAmount = $estimatedAmount;
        $this->touch();

        return $this;
    }

    public function getExpectedCloseDate(): ?\DateTimeImmutable
    {
        return $this->expectedCloseDate;
    }

    public function setExpectedCloseDate(?\DateTimeImmutable $expectedCloseDate): self
    {
        $this->expectedCloseDate = $expectedCloseDate;
        $this->touch();

        return $this;
    }

    public function getCommercialDocumentRef(): ?Uuid
    {
        return $this->commercialDocumentRef;
    }

    public function setCommercialDocumentRef(?Uuid $commercialDocumentRef): self
    {
        $this->commercialDocumentRef = $commercialDocumentRef;
        $this->touch();

        return $this;
    }

    public function getLossReason(): ?LossReason
    {
        return $this->lossReason;
    }

    public function setLossReason(?LossReason $lossReason): self
    {
        $this->lossReason = $lossReason;
        $this->touch();

        return $this;
    }

    public function getLossComment(): ?string
    {
        return $this->lossComment;
    }

    public function setLossComment(?string $lossComment): self
    {
        $this->lossComment = $lossComment;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Une affaire perdue sans motif est une affaire dont on n'apprendra rien.
     *
     * Vérifié à la validation plutôt qu'au `setStage` : le motif et l'étape arrivent dans la même
     * requête, et refuser dans le mutateur dépendrait de l'ordre de désérialisation — un ordre que
     * personne ne contrôle et qui change en changeant de version d'API Platform.
     */
    #[Assert\Callback]
    public function validerMotifDePerte(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->stage === OpportunityStage::Lost && $this->lossReason === null) {
            $context->buildViolation('Une affaire perdue demande un motif : c’est la seule donnée du pipeline qui serve encore dans six mois.')
                ->atPath('lossReason')
                ->addViolation();
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
