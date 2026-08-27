<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Marketing\State\MarketingEstablishmentStampProcessor;
use App\Marketing\State\SegmentPreviewProvider;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * UN SEGMENT EST UNE DÉFINITION, PAS UNE LISTE.
 *
 * Il porte des **critères** ; ses membres sont calculés à chaque lecture. C'est ce que veut dire
 * « segment dynamique » dans le cahier des charges, et c'est ce qu'exige son critère d'acceptation :
 * *« un segment créé est immédiatement disponible et à jour »*.
 *
 * > **Un état qui se calcule ne se stocke pas.**
 *
 * Stocker la liste des membres donnerait un segment juste le jour de sa création et faux le
 * lendemain — sans que rien ne le signale, puisqu'une liste périmée a exactement la même allure
 * qu'une liste à jour.
 *
 * **La seule liste qui se fige est celle des DESTINATAIRES d'un envoi** (`CampaignRecipient`), et
 * pour une raison opposée : sans elle, on ne saurait plus à qui on a écrit, ni à quoi rattacher les
 * ventes qui suivent. Le segment dit *qui correspond aujourd'hui* ; l'envoi dit *à qui on a écrit ce
 * jour-là*. Confondre les deux fait perdre l'un des deux.
 *
 * ── CE QUE LES CRITÈRES NE CONTIENNENT PAS, ET POURQUOI ─────────────────────────────────────────
 *
 * **Jamais de requête écrite à la main** (RG-CMP-01). Un exploitant de piscine ne rédige pas de SQL,
 * et une requête libre serait une porte ouverte sur les données des autres établissements : le
 * cloisonnement porte sur les clients **résolus**, pas sur les critères, et une expression arbitraire
 * rendrait ce contrôle impossible à tenir.
 *
 * ── LES CRITÈRES DE CETTE PREMIÈRE VERSION ──────────────────────────────────────────────────────
 *
 * Tous lisent des données que `Client` porte déjà — dernière visite, chiffre d'affaires cumulé, date
 * de naissance, établissement, type. Ce sont celles qu'aucun outil d'emailing généraliste ne possède.
 *
 * Ceux qui manquent — abonnement échéant, solde de carte, activité pratiquée — vivent dans d'autres
 * modules. Les lire d'ici demanderait un **port** (le patron de `SupportAccessRightsInterface`), pas
 * un appel direct : D2 interdit qu'un module aille chercher dans un autre. C'est écrit ici pour que
 * la prochaine version ajoute un port et non un raccourci.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_segment')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_segment_label', columns: ['establishment_id', 'label'])]
#[ApiResource(
    shortName: 'Segment',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'campagne.lire')"),
        new Get(security: "is_granted('PERM', 'campagne.lire')"),
        new Post(
            security: "is_granted('PERM', 'campagne.gerer')",
            denormalizationContext: ['groups' => ['segment:write']],
            processor: MarketingEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'campagne.gerer')",
            denormalizationContext: ['groups' => ['segment:write']],
        ),
        new Delete(security: "is_granted('PERM', 'campagne.gerer')"),

        // L'EFFECTIF AVANT L'ENVOI (RG-CMP-02). Sans ce chiffre, l'exploitant découvre l'ampleur de
        // son geste après l'avoir fait.
        new Get(
            uriTemplate: '/marketing/segments/{id}/apercu',
            security: "is_granted('PERM', 'campagne.lire')",
            provider: SegmentPreviewProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['segment:read']],
)]
class Segment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['segment:read'])]
    private Uuid $id;

    /** Posé par `MarketingEstablishmentStampProcessor`, jamais par le corps de la requête (D41). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['segment:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['segment:read', 'segment:write'])]
    private string $label = '';

    /**
     * Les critères, sous une forme fermée : seules les clés connues sont interprétées.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['segment:read', 'segment:write'])]
    private array $criteria = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /** @param array<string, mixed> $criteria */
    public function setCriteria(array $criteria): self
    {
        $this->criteria = $criteria;

        return $this;
    }

    /**
     * UN CRITÈRE INCONNU EST REFUSÉ, IL N'EST PAS IGNORÉ.
     *
     * Ignorer silencieusement `derniereVisiteAvant` mal orthographié rendrait un segment **plus
     * large** que ce que l'exploitant croit avoir écrit — et il l'apprendrait en envoyant. Un critère
     * qu'on ne sait pas appliquer doit arrêter la saisie, pas élargir l'audience.
     *
     * C'est la même règle que partout ailleurs ici : le sens sûr de l'erreur est celui qui restreint.
     */
    #[Assert\Callback]
    public function validerCriteres(ExecutionContextInterface $context): void
    {
        foreach (array_keys($this->criteria) as $cle) {
            if (!\in_array($cle, SegmentCriteria::CLES_CONNUES, true)) {
                $context->buildViolation('Critère inconnu : « {{ cle }} ». Un critère non appliqué élargirait l’audience sans le dire.')
                    ->setParameter('{{ cle }}', (string) $cle)
                    ->atPath('criteria')
                    ->addViolation();
            }
        }

        if ($this->criteria === []) {
            $context->buildViolation('Un segment sans critère désigne tout le monde : précisez au moins une condition.')
                ->atPath('criteria')
                ->addViolation();
        }
    }
}
