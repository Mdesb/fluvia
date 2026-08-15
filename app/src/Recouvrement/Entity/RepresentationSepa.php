<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Recouvrement\Enum\ResultatRepresentationSepa;
use App\Recouvrement\State\EnregistrerResultatRepresentationProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Représentation SEPA programmée pour un incident — moteur générique partagé (extrait de
 * `App\Sport\Entity\RepresentationSepa`). Résultat enregistré par le « Système ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'recouvrement_representation')]
#[ApiResource(
    shortName: 'RepresentationRecouvrement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'recouvrement.piloter')"),
        new Get(security: "is_granted('PERM', 'recouvrement.piloter')"),
        new Post(
            uriTemplate: '/recouvrement/representations/{id}/enregistrer-resultat',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.piloter')",
            processor: EnregistrerResultatRepresentationProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['representation:read']],
)]
class RepresentationSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['representation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IncidentImpaye::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['representation:read'])]
    private ?IncidentImpaye $incident = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['representation:read'])]
    private \DateTimeImmutable $dateProgrammee;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['representation:read'])]
    private ?\DateTimeImmutable $dateExecution = null;

    #[ORM\Column(length: 10, enumType: ResultatRepresentationSepa::class, options: ['default' => 'en_attente'])]
    #[Groups(['representation:read'])]
    private ResultatRepresentationSepa $resultat = ResultatRepresentationSepa::EnAttente;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIncident(): ?IncidentImpaye
    {
        return $this->incident;
    }

    public function setIncident(?IncidentImpaye $incident): self
    {
        $this->incident = $incident;

        return $this;
    }

    public function getDateProgrammee(): \DateTimeImmutable
    {
        return $this->dateProgrammee;
    }

    public function setDateProgrammee(\DateTimeImmutable $dateProgrammee): self
    {
        $this->dateProgrammee = $dateProgrammee;

        return $this;
    }

    public function getDateExecution(): ?\DateTimeImmutable
    {
        return $this->dateExecution;
    }

    public function setDateExecution(?\DateTimeImmutable $dateExecution): self
    {
        $this->dateExecution = $dateExecution;

        return $this;
    }

    public function getResultat(): ResultatRepresentationSepa
    {
        return $this->resultat;
    }

    public function setResultat(ResultatRepresentationSepa $resultat): self
    {
        $this->resultat = $resultat;

        return $this;
    }
}
