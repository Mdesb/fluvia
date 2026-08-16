<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Musee\State\CreerBasculeAudioguideProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bascule vers un audioguide (US-MUSEE-04/11, décision actée « Guide indisponible ») : issue de repli
 * lorsqu'aucun guide qualifié n'est disponible dans la langue demandée (§4.4, CA-4) — applique une
 * remise automatique sur le tarif de la visite guidée initialement visée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_bascule_audioguide')]
#[ApiResource(
    shortName: 'MuseeBasculeAudioguide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Post(
            uriTemplate: '/musee/bascules-audioguide',
            security: "is_granted('PERM', 'musee.gerer_visite') or is_granted('PERM', 'reservation.reserver_soi')",
            processor: CreerBasculeAudioguideProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['bascule:read']],
    denormalizationContext: ['groups' => ['bascule:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'beneficiaire' => 'exact'])]
class BasculeAudioguide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bascule:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: VisiteGuidee::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['bascule:read', 'bascule:write'])]
    private ?VisiteGuidee $visiteGuideeRefInitiale = null;

    #[ORM\ManyToOne(targetEntity: Audioguide::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bascule:read', 'bascule:write'])]
    private ?Audioguide $audioguide = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['bascule:read', 'bascule:write'])]
    private ?Beneficiaire $beneficiaire = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['bascule:read'])]
    private string $tauxRemise = '0.00';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['bascule:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['bascule:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVisiteGuideeRefInitiale(): ?VisiteGuidee
    {
        return $this->visiteGuideeRefInitiale;
    }

    public function setVisiteGuideeRefInitiale(?VisiteGuidee $visiteGuideeRefInitiale): self
    {
        $this->visiteGuideeRefInitiale = $visiteGuideeRefInitiale;

        return $this;
    }

    public function getAudioguide(): ?Audioguide
    {
        return $this->audioguide;
    }

    public function setAudioguide(?Audioguide $audioguide): self
    {
        $this->audioguide = $audioguide;

        return $this;
    }

    public function getBeneficiaire(): ?Beneficiaire
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Beneficiaire $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getTauxRemise(): string
    {
        return $this->tauxRemise;
    }

    public function setTauxRemise(string $tauxRemise): self
    {
        $this->tauxRemise = $tauxRemise;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
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
