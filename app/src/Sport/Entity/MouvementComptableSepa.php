<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Sport\Enum\StatutTransmissionMouvementComptable;
use App\Sport\Enum\TypeMouvementComptableSepa;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * File d'attente comptable transitoire, **append-only** (§1.8/§2.4 du plan, Risque n°1). Journalise
 * chaque encaissement/impayé confirmé, en attendant une extension M6 qui consommera cette file pour
 * générer une écriture NF525 scellée. **Aucune écriture `App\Compta\Entity\EcritureComptable` n'est
 * produite par ce lot** — cette entité ne remplace pas la comptabilisation réelle, elle la prépare.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_mouvement_comptable_sepa')]
#[ApiResource(
    shortName: 'MouvementComptableSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire') or is_granted('PERM', 'sport.piloter_impayes')"),
        new Get(security: "is_granted('PERM', 'compta.lire') or is_granted('PERM', 'sport.piloter_impayes')"),
    ],
    normalizationContext: ['groups' => ['mouvement_compta:read']],
)]
class MouvementComptableSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mouvement_compta:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement_compta:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement_compta:read'])]
    private ?AbonnementFitness $abonnement = null;

    #[ORM\Column(length: 12, enumType: TypeMouvementComptableSepa::class)]
    #[Groups(['mouvement_compta:read'])]
    private TypeMouvementComptableSepa $type = TypeMouvementComptableSepa::Encaissement;

    #[ORM\Column]
    #[Groups(['mouvement_compta:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mouvement_compta:read'])]
    private \DateTimeImmutable $dateFaitGenerateur;

    #[ORM\Column(length: 20)]
    #[Groups(['mouvement_compta:read'])]
    private string $origine = '';

    #[ORM\Column(length: 12, enumType: StatutTransmissionMouvementComptable::class, options: ['default' => 'en_attente'])]
    #[Groups(['mouvement_compta:read'])]
    private StatutTransmissionMouvementComptable $statutTransmission = StatutTransmissionMouvementComptable::EnAttente;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mouvement_compta:read'])]
    private ?\DateTimeImmutable $transmisLe = null;

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

    public function getAbonnement(): ?AbonnementFitness
    {
        return $this->abonnement;
    }

    public function setAbonnement(?AbonnementFitness $abonnement): self
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getType(): TypeMouvementComptableSepa
    {
        return $this->type;
    }

    public function setType(TypeMouvementComptableSepa $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getDateFaitGenerateur(): \DateTimeImmutable
    {
        return $this->dateFaitGenerateur;
    }

    public function setDateFaitGenerateur(\DateTimeImmutable $dateFaitGenerateur): self
    {
        $this->dateFaitGenerateur = $dateFaitGenerateur;

        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function getStatutTransmission(): StatutTransmissionMouvementComptable
    {
        return $this->statutTransmission;
    }

    public function setStatutTransmission(StatutTransmissionMouvementComptable $statutTransmission): self
    {
        $this->statutTransmission = $statutTransmission;

        return $this;
    }

    public function getTransmisLe(): ?\DateTimeImmutable
    {
        return $this->transmisLe;
    }

    public function setTransmisLe(?\DateTimeImmutable $transmisLe): self
    {
        $this->transmisLe = $transmisLe;

        return $this;
    }
}
