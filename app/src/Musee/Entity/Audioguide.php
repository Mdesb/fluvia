<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Audioguide : produit boutique optionnel multilingue (US-MUSEE-11), spécialisation musée d'un
 * `Produit` (M1, **réutilisé** : tarif, TVA). Support de la bascule §4.4 (`BasculeAudioguide`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_audioguide')]
#[ORM\UniqueConstraint(name: 'uniq_audioguide_produit', columns: ['produit_id'])]
#[ApiResource(
    shortName: 'MuseeAudioguide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.configurer')"),
        new Patch(security: "is_granted('PERM', 'musee.configurer')"),
    ],
    normalizationContext: ['groups' => ['audioguide:read']],
    denormalizationContext: ['groups' => ['audioguide:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact'])]
class Audioguide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['audioguide:read', 'bascule:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['audioguide:read', 'audioguide:write'])]
    private ?Produit $produit = null;

    /** @var list<string> Codes ISO des langues disponibles (⚠ HYPOTHÈSE catalogue non chiffré, §4.11). */
    #[ORM\Column]
    #[Assert\Count(min: 1, minMessage: 'Au moins une langue est requise.')]
    #[Groups(['audioguide:read', 'audioguide:write'])]
    private array $langues = [];

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['audioguide:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    /** @return list<string> */
    public function getLangues(): array
    {
        return $this->langues;
    }

    /** @param list<string> $langues */
    public function setLangues(array $langues): self
    {
        $this->langues = array_values($langues);

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
