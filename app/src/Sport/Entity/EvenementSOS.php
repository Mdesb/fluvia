<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Securite\Entity\Utilisateur;
use App\Sport\Enum\StatutEvenementSOS;
use App\Sport\State\DeclencherSosProcessor;
use App\Sport\State\TraiterSosProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Déclenchement du bouton SOS (US-SPORT-09, §4.8 spec). ⚠ Risque n°7 du plan — le déclenchement
 * physique (`POST /sport/espaces/{id}/sos`) ne peut raisonnablement exiger un login humain classique
 * (device 24/7 sans personnel) : sécurité `PUBLIC_ACCESS` documentée comme point à cadrer (jeton
 * d'appareil non spécifié par ce lot).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_evenement_sos')]
#[ApiResource(
    shortName: 'EvenementSOS',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.superviser_nocturne')"),
        new Get(security: "is_granted('PERM', 'sport.superviser_nocturne')"),
        new Post(
            uriTemplate: '/sport/espaces/{id}/sos',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: DeclencherSosProcessor::class,
        ),
        new Post(
            uriTemplate: '/sport/sos/{id}/traiter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'sport.superviser_nocturne')",
            processor: TraiterSosProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['sos:read']],
)]
class EvenementSOS
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['sos:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['sos:read'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['sos:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['sos:read'])]
    private ?Support $declenchePar = null;

    #[ORM\Column(length: 10, enumType: StatutEvenementSOS::class, options: ['default' => 'ouverte'])]
    #[Groups(['sos:read'])]
    private StatutEvenementSOS $statut = StatutEvenementSOS::Ouverte;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['sos:read'])]
    private ?Utilisateur $traitePar = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['sos:read'])]
    private ?\DateTimeImmutable $dateTraitement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEspaceAcces(): ?EspaceAcces
    {
        return $this->espaceAcces;
    }

    public function setEspaceAcces(?EspaceAcces $espaceAcces): self
    {
        $this->espaceAcces = $espaceAcces;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getDeclenchePar(): ?Support
    {
        return $this->declenchePar;
    }

    public function setDeclenchePar(?Support $declenchePar): self
    {
        $this->declenchePar = $declenchePar;

        return $this;
    }

    public function getStatut(): StatutEvenementSOS
    {
        return $this->statut;
    }

    public function setStatut(StatutEvenementSOS $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getTraitePar(): ?Utilisateur
    {
        return $this->traitePar;
    }

    public function setTraitePar(?Utilisateur $traitePar): self
    {
        $this->traitePar = $traitePar;

        return $this;
    }

    public function getDateTraitement(): ?\DateTimeImmutable
    {
        return $this->dateTraitement;
    }

    public function setDateTraitement(?\DateTimeImmutable $dateTraitement): self
    {
        $this->dateTraitement = $dateTraitement;

        return $this;
    }
}
