<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rattachement d'un Employé à un Établissement (RG-PERSO-09, multi-site) : porte le poste local
 * éventuel et la période d'effet. Un employé sans rattachement actif ne peut être affecté à aucun
 * créneau ni détenir de badge actif (cohérent RG-SOCLE-05).
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_rattachement')]
#[ApiResource(
    shortName: 'RattachementEmploye',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire')"),
        new Get(security: "is_granted('PERM', 'personnel.lire')"),
        new Post(security: "is_granted('PERM', 'personnel.gerer_employe')"),
        new Patch(security: "is_granted('PERM', 'personnel.gerer_employe')"),
        new Delete(security: "is_granted('PERM', 'personnel.gerer_employe')"),
    ],
    normalizationContext: ['groups' => ['rattachement:read']],
    denormalizationContext: ['groups' => ['rattachement:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['employe' => 'exact', 'etablissement' => 'exact'])]
class RattachementEmploye
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rattachement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['rattachement:read', 'rattachement:write'])]
    private ?Employe $employe = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['rattachement:read', 'rattachement:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['rattachement:read', 'rattachement:write'])]
    private ?string $posteLocal = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['rattachement:read', 'rattachement:write'])]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['rattachement:read', 'rattachement:write'])]
    private ?\DateTimeImmutable $fin = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;

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

    public function getPosteLocal(): ?string
    {
        return $this->posteLocal;
    }

    public function setPosteLocal(?string $posteLocal): self
    {
        $this->posteLocal = $posteLocal;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    /** Vrai si le rattachement couvre la date donnée (actif). */
    public function estActifA(\DateTimeImmutable $date): bool
    {
        return $this->debut !== null && $this->debut <= $date && ($this->fin === null || $this->fin >= $date);
    }
}
