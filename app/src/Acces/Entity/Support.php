<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeSupport;
use App\Acces\State\BloquerSupportProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Support d'accès (QR/RFID/wallet, A-02) identifié par son `identifiant` lu par le lecteur — miroir
 * de `BilletSupport.identifiantSupport` (M2). Passe à `bloque` en cas de perte/vol (RG-ACC-07,
 * CA-10) : tout scan est alors refusé, y compris hors-ligne (liste de révocation embarquée, §4.6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_support')]
#[ORM\UniqueConstraint(name: 'uniq_support_identifiant', columns: ['identifiant'])]
#[ORM\Index(columns: ['version_maj'], name: 'idx_support_version_maj')]
#[ApiResource(
    shortName: 'Support',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.appairer')"),
        new Post(
            uriTemplate: '/acces/supports/{id}/bloquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'acces.bloquer_support')",
            processor: BloquerSupportProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['support:read']],
    denormalizationContext: ['groups' => ['support:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['identifiant' => 'exact', 'statut' => 'exact'])]
class Support
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['support:read', 'appairage:read', 'passage:read', 'pertevol:read'])]
    private Uuid $id;

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['support:read', 'support:write', 'appairage:read', 'passage:read', 'pertevol:read', 'sos:read'])]
    private string $identifiant = '';

    #[ORM\Column(length: 12, enumType: TypeSupport::class)]
    #[Assert\NotNull]
    #[Groups(['support:read', 'support:write', 'appairage:read'])]
    private ?TypeSupport $type = null;

    #[ORM\Column(length: 12, enumType: StatutSupport::class, options: ['default' => 'actif'])]
    #[Groups(['support:read', 'pertevol:read'])]
    private StatutSupport $statut = StatutSupport::Actif;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['support:read'])]
    private ?Etablissement $etablissement = null;

    /**
     * Curseur monotone du snapshot terminal (US-TERM-03/04, plan-acces-terminal.md §1.4) : horodate
     * logiquement la dernière mutation affectant la projection de ce support (blocage/déblocage,
     * appairage/révocation, décompte crédit du `DroitAcces` appairé). Alimenté par
     * `App\Acces\Service\VersionSnapshotSequencer` (séquence native MariaDB `acces_snapshot_seq`).
     * Backfill à `0` uniforme sur les supports existants (correct : le premier snapshot complet sans
     * `depuis` renvoie tout indépendamment de la valeur).
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['support:read'])]
    private int $versionMaj = 0;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIdentifiant(): string
    {
        return $this->identifiant;
    }

    public function setIdentifiant(string $identifiant): self
    {
        $this->identifiant = $identifiant;

        return $this;
    }

    public function getType(): ?TypeSupport
    {
        return $this->type;
    }

    public function setType(?TypeSupport $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatut(): StatutSupport
    {
        return $this->statut;
    }

    public function setStatut(StatutSupport $statut): self
    {
        $this->statut = $statut;

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

    public function getVersionMaj(): int
    {
        return $this->versionMaj;
    }

    public function setVersionMaj(int $versionMaj): self
    {
        $this->versionMaj = $versionMaj;

        return $this;
    }
}
