<?php

declare(strict_types=1);

namespace App\Organisation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Établissement : pivot du cloisonnement multi-entités (RG-SOCLE-05). Rattaché à une Région.
 * Les lectures sont bornées au périmètre affecté à l'utilisateur courant (extension Doctrine).
 */
#[ORM\Entity]
#[ORM\Table(name: 'org_etablissement')]
#[ApiResource(
    shortName: 'Etablissement',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(security: "is_granted('PERM', 'organisation.gerer')"),
        new Patch(security: "is_granted('PERM', 'organisation.gerer')"),
        // PAS DE `Delete`, ET C'EST DELIBERE.
        //
        // Un etablissement n'est pas une ligne de referentiel : c'est **la frontiere de cloisonnement
        // a laquelle tout est rattache**. 119 entites du depot portent une relation vers lui, et
        // **deux** declarent un comportement de suppression. Les 117 autres retombent donc sur le
        // refus de la base : une suppression echouerait par une violation de cle etrangere brute --
        // ou, sur un etablissement encore vide, **reussirait**, en detruisant un perimetre reel.
        //
        // Aucun des deux comportements n'est acceptable, et le second est le pire : il ne se produit
        // que sur un etablissement recemment cree, c'est-a-dire au moment ou l'on tatonne encore.
        //
        // Ce qu'un exploitant veut reellement, c'est **desactiver** : le site cesse d'etre propose,
        // l'historique reste consultable, et c'est reversible. Le champ `actif` existe pour ca.
        //
        // Trouve par claude-H, qui avait deja refuse d'exposer le bouton cote ecran -- « un bouton qui
        // echoue une fois sur deux enseigne surtout qu'on peut reessayer ». Elle avait raison sur
        // l'ecran ; le trou etait dans l'API, ou n'importe qui portant `organisation.gerer` pouvait
        // appeler l'operation directement, bouton ou pas. Cacher un bouton ne ferme pas une porte.
        //
        // Si une fermeture definitive doit exister un jour, elle merite un geste dedie, ses propres
        // avertissements et une reprise des 119 relations -- pas la meme croix qu'un taux de TVA.
    ],
    normalizationContext: ['groups' => ['etablissement:read']],
    denormalizationContext: ['groups' => ['etablissement:write']],
)]
class Etablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['etablissement:read', 'espace:read', 'affectation:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['etablissement:read', 'etablissement:write', 'espace:read', 'affectation:read'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: Region::class, inversedBy: 'etablissements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private ?Region $region = null;

    #[ORM\Column]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private bool $actif = true;

    /** @var Collection<int, Espace> */
    #[ORM\OneToMany(targetEntity: Espace::class, mappedBy: 'etablissement')]
    private Collection $espaces;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->espaces = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): self
    {
        $this->region = $region;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** @return Collection<int, Espace> */
    public function getEspaces(): Collection
    {
        return $this->espaces;
    }
}
