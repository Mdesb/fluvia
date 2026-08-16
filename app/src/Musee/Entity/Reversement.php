<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Musee\Enum\StatutReversement;
use App\Musee\State\CreerReversementProcessor;
use App\Musee\State\MarquerReversementVerseProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Reversement OTA (US-MUSEE-07, RG-MUS-04) : montant calculé (tarif net + commission) sur les
 * `ReservationOTA.statutOta=confirmee` de la période.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_reversement')]
#[ApiResource(
    shortName: 'MuseeReversement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(
            uriTemplate: '/musee/reversements/generer',
            read: false,
            security: "is_granted('PERM', 'musee.gerer_ota')",
            processor: CreerReversementProcessor::class,
        ),
        new Post(
            uriTemplate: '/musee/reversements/{id}/marquer-verse',
            read: true,
            input: false,
            security: "is_granted('PERM', 'musee.gerer_ota')",
            processor: MarquerReversementVerseProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['reversement:read']],
    denormalizationContext: ['groups' => ['reversement:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['partenaire' => 'exact', 'statut' => 'exact'])]
class Reversement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reversement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartenaireOTA::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reversement:read'])]
    private ?PartenaireOTA $partenaire = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reversement:read'])]
    private ?\DateTimeImmutable $periodeDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reversement:read'])]
    private ?\DateTimeImmutable $periodeFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['reversement:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 10, enumType: StatutReversement::class, options: ['default' => 'a_verser'])]
    #[Groups(['reversement:read'])]
    private StatutReversement $statut = StatutReversement::AVerser;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reversement:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPartenaire(): ?PartenaireOTA
    {
        return $this->partenaire;
    }

    public function setPartenaire(?PartenaireOTA $partenaire): self
    {
        $this->partenaire = $partenaire;

        return $this;
    }

    public function getPeriodeDebut(): ?\DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(?\DateTimeImmutable $periodeDebut): self
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): ?\DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(?\DateTimeImmutable $periodeFin): self
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getStatut(): StatutReversement
    {
        return $this->statut;
    }

    public function setStatut(StatutReversement $statut): self
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
}
