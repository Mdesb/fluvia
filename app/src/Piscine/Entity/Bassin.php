<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bassin décomposé en lignes d'eau (US-L6-05) : capacité propre, distincte de la FMI établissement.
 * `espaceAccesDedie` n'est renseigné que si le bassin dispose d'un point de contrôle physique propre
 * (condition d'un `Poss.perimetre = bassin`, plan §0 point 5 — ⚠ risque n°1, topologie à valider).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_bassin')]
#[ApiResource(
    shortName: 'Bassin',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')"),
        new Patch(security: "is_granted('PERM', 'piscine.configurer')"),
    ],
    normalizationContext: ['groups' => ['bassin:read']],
    denormalizationContext: ['groups' => ['bassin:write']],
)]
class Bassin
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bassin:read', 'poss:read', 'ligne:read', 'creneau:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    // ⚠ LES MEMES GROUPES QUE L'IDENTIFIANT, ET C'EST LE POINT.
    //
    // `$id` porte deja `poss:read`, `ligne:read` et `creneau:read` : la relation sort donc en objet
    // partout, mais son NOM n'y etait pas. `Piscine.jsx` lisait `r.bassin?.libelle`, obtenait
    // `undefined`, et repliait sur la fin de l'IRI — un plan de surveillance affichant
    // « 4f2a1c8e » la ou un maitre-nageur attend « Petit bain ».
    //
    // Montrer l'identite d'un objet sans son nom n'expose rien de moins ; ca oblige seulement
    // l'ecran a inventer un repli. Releve par le garde-fou n°32.
    #[Groups(['bassin:read', 'bassin:write', 'poss:read', 'ligne:read', 'creneau:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Un espace du socle est requis (RG-SOCLE-01).')]
    #[Groups(['bassin:read', 'bassin:write'])]
    private ?Espace $espace = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive(message: 'Le nombre de lignes doit être strictement positif (US-L6-05).')]
    #[Groups(['bassin:read', 'bassin:write'])]
    private int $nbLignes = 1;

    #[ORM\Column]
    #[Assert\Positive(message: 'La capacité doit être strictement positive (US-L6-05).')]
    #[Groups(['bassin:read', 'bassin:write'])]
    private int $capacite = 1;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['bassin:read'])]
    private int $occupationCourante = 0;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: true, unique: true)]
    #[Groups(['bassin:read', 'bassin:write'])]
    private ?EspaceAcces $espaceAccesDedie = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['bassin:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getEspace(): ?Espace
    {
        return $this->espace;
    }

    public function setEspace(?Espace $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getNbLignes(): int
    {
        return $this->nbLignes;
    }

    public function setNbLignes(int $nbLignes): self
    {
        $this->nbLignes = $nbLignes;

        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): self
    {
        $this->capacite = $capacite;

        return $this;
    }

    public function getOccupationCourante(): int
    {
        return $this->occupationCourante;
    }

    public function setOccupationCourante(int $occupationCourante): self
    {
        $this->occupationCourante = max(0, $occupationCourante);

        return $this;
    }

    public function getEspaceAccesDedie(): ?EspaceAcces
    {
        return $this->espaceAccesDedie;
    }

    public function setEspaceAccesDedie(?EspaceAcces $espaceAccesDedie): self
    {
        $this->espaceAccesDedie = $espaceAccesDedie;

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
