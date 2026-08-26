<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Recouvrement\State\RecoveryPolicyWriteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Politique de recouvrement (1 par établissement) — moteur générique partagé, extrait de
 * `App\Sport\Entity\PolitiqueAntiImpayes` pour être réutilisable par toute activité à abonnement
 * (Sport, Piscine en régie, futures). Pilote `App\Recouvrement\Service\MoteurRecouvrementHandler` :
 * nombre de représentations, calendrier, moment du refus d'accès (paramétrable, y compris dès le 1ᵉʳ
 * échec). Rattachée à l'établissement, pas à une verticale.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recouvrement_politique')]
#[ORM\UniqueConstraint(name: 'uniq_recouvrement_politique_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'PolitiqueRecouvrement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'recouvrement.lire')"),
        new Get(security: "is_granted('PERM', 'recouvrement.lire')"),
        new Post(security: "is_granted('PERM', 'recouvrement.parametrer')", processor: RecoveryPolicyWriteProcessor::class),
        new Patch(security: "is_granted('PERM', 'recouvrement.parametrer')", processor: RecoveryPolicyWriteProcessor::class),
    ],
    normalizationContext: ['groups' => ['politique_recouvrement:read']],
    denormalizationContext: ['groups' => ['politique_recouvrement:write']],
)]
class PolitiqueRecouvrement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['politique_recouvrement:read'])]
    private Uuid $id;

    // D3/D8/D41 : `etablissement` n'est PAS dans le groupe d'écriture — un appelant ne doit jamais
    // pouvoir rattacher/déplacer une politique vers un autre établissement via le corps de la requête.
    // Posé côté serveur, depuis le contexte établissement actif, par `RecoveryPolicyWriteProcessor`.
    // Pas d'`#[Assert\NotNull]` : la validation API Platform s'exécute AVANT le processor (qui pose
    // l'établissement) — elle échouerait donc toujours à la création. La présence est garantie par le
    // processor (échec fermé si aucun contexte) et, en dernier ressort, par la contrainte DB NOT NULL.
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['politique_recouvrement:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Assert\PositiveOrZero]
    #[Groups(['politique_recouvrement:read', 'politique_recouvrement:write'])]
    private int $nbRepresentationsMax = 1;

    /** @var list<int> Délais en jours après rejet, un par représentation. */
    #[ORM\Column]
    #[Groups(['politique_recouvrement:read', 'politique_recouvrement:write'])]
    private array $calendrierRepresentationJours = [5];

    #[ORM\Column(length: 32, enumType: MomentRefusAcces::class, options: ['default' => 'apres_representation_echouee'])]
    #[Groups(['politique_recouvrement:read', 'politique_recouvrement:write'])]
    private MomentRefusAcces $momentRefusAcces = MomentRefusAcces::ApresRepresentationEchouee;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['politique_recouvrement:read', 'politique_recouvrement:write'])]
    private ?int $nReprAvantBlocage = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['politique_recouvrement:read', 'politique_recouvrement:write'])]
    private ?int $delaiAvantSuspensionContratJours = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getNbRepresentationsMax(): int
    {
        return $this->nbRepresentationsMax;
    }

    public function setNbRepresentationsMax(int $nbRepresentationsMax): self
    {
        $this->nbRepresentationsMax = $nbRepresentationsMax;

        return $this;
    }

    /** @return list<int> */
    public function getCalendrierRepresentationJours(): array
    {
        return $this->calendrierRepresentationJours;
    }

    /** @param list<int> $calendrierRepresentationJours */
    public function setCalendrierRepresentationJours(array $calendrierRepresentationJours): self
    {
        $this->calendrierRepresentationJours = $calendrierRepresentationJours;

        return $this;
    }

    public function getMomentRefusAcces(): MomentRefusAcces
    {
        return $this->momentRefusAcces;
    }

    public function setMomentRefusAcces(MomentRefusAcces $momentRefusAcces): self
    {
        $this->momentRefusAcces = $momentRefusAcces;

        return $this;
    }

    public function getNReprAvantBlocage(): ?int
    {
        return $this->nReprAvantBlocage;
    }

    public function setNReprAvantBlocage(?int $nReprAvantBlocage): self
    {
        $this->nReprAvantBlocage = $nReprAvantBlocage;

        return $this;
    }

    public function getDelaiAvantSuspensionContratJours(): ?int
    {
        return $this->delaiAvantSuspensionContratJours;
    }

    public function setDelaiAvantSuspensionContratJours(?int $delaiAvantSuspensionContratJours): self
    {
        $this->delaiAvantSuspensionContratJours = $delaiAvantSuspensionContratJours;

        return $this;
    }
}
