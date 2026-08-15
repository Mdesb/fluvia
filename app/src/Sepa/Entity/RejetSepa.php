<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Sepa\State\DeclarerRejetSepaProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Retour SEPA (rejet) générique, rattaché à une `LigneRemiseSepa` (plan §2/§4/§6). Aucun parser
 * pain.002 réel n'existe (§9 du plan) : alimenté pour l'instant par une saisie/simulation manuelle
 * (`POST /sepa/rejets`) en attendant le retour bancaire réel (`RetourSepaInterface`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_rejet')]
#[ApiResource(
    shortName: 'RejetSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            security: "is_granted('PERM', 'sepa.declarer_rejet')",
            processor: DeclarerRejetSepaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['rejet_sepa:read']],
)]
class RejetSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rejet_sepa:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LigneRemiseSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rejet_sepa:read'])]
    private ?LigneRemiseSepa $ligne = null;

    #[ORM\Column(length: 35)]
    #[Groups(['rejet_sepa:read'])]
    private string $endToEndId = '';

    #[ORM\Column(length: 35)]
    #[Groups(['rejet_sepa:read'])]
    private string $mndtId = '';

    /** Code retour SEPA (« R-code »), ex. AM04, MD01, MS03. */
    #[ORM\Column(length: 4)]
    #[Groups(['rejet_sepa:read'])]
    private string $codeMotif = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['rejet_sepa:read'])]
    private ?string $libelleMotif = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['rejet_sepa:read'])]
    private \DateTimeImmutable $dateRejet;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['rejet_sepa:read'])]
    private \DateTimeImmutable $dateSaisie;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateSaisie = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLigne(): ?LigneRemiseSepa
    {
        return $this->ligne;
    }

    public function setLigne(?LigneRemiseSepa $ligne): self
    {
        $this->ligne = $ligne;

        return $this;
    }

    public function getEndToEndId(): string
    {
        return $this->endToEndId;
    }

    public function setEndToEndId(string $endToEndId): self
    {
        $this->endToEndId = $endToEndId;

        return $this;
    }

    public function getMndtId(): string
    {
        return $this->mndtId;
    }

    public function setMndtId(string $mndtId): self
    {
        $this->mndtId = $mndtId;

        return $this;
    }

    public function getCodeMotif(): string
    {
        return $this->codeMotif;
    }

    public function setCodeMotif(string $codeMotif): self
    {
        $this->codeMotif = $codeMotif;

        return $this;
    }

    public function getLibelleMotif(): ?string
    {
        return $this->libelleMotif;
    }

    public function setLibelleMotif(?string $libelleMotif): self
    {
        $this->libelleMotif = $libelleMotif;

        return $this;
    }

    public function getDateRejet(): \DateTimeImmutable
    {
        return $this->dateRejet;
    }

    public function setDateRejet(\DateTimeImmutable $dateRejet): self
    {
        $this->dateRejet = $dateRejet;

        return $this;
    }

    public function getDateSaisie(): \DateTimeImmutable
    {
        return $this->dateSaisie;
    }
}
