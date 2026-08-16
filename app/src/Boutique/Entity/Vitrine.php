<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
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
 * Vitrine white-label par établissement (US-L8-01, RG-M3-01/08). Porte l'identité visuelle, les
 * langues, les canaux actifs et le délai d'expiration du panier (RG-M3-03, décision actée « X
 * minutes »). Lecture publique (CA-1) ; gestion réservée à `boutique.gerer_vitrine`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_vitrine')]
#[ORM\UniqueConstraint(name: 'uniq_vitrine_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'BoutiqueVitrine',
    operations: [
        new GetCollection(uriTemplate: '/boutique/vitrines', security: "is_granted('PERM', 'boutique.lire')"),
        new Get(uriTemplate: '/boutique/vitrines/{id}', security: "is_granted('PUBLIC_ACCESS')"),
        new Post(uriTemplate: '/boutique/vitrines', security: "is_granted('PERM', 'boutique.gerer_vitrine')"),
        new Patch(uriTemplate: '/boutique/vitrines/{id}', security: "is_granted('PERM', 'boutique.gerer_vitrine')"),
    ],
    normalizationContext: ['groups' => ['vitrine:read']],
    denormalizationContext: ['groups' => ['vitrine:write']],
)]
class Vitrine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vitrine:read', 'panier:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?string $logo = null;

    /** @var array<string, string>|null palette {primaire, secondaire...} */
    #[ORM\Column(nullable: true)]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?array $couleurs = null;

    /** @var list<string> ISO, non vide, défaut ['fr'] */
    #[ORM\Column]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private array $langues = ['fr'];

    /** @var list<string> ⊂ {en_ligne, app} */
    #[ORM\Column]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private array $canauxActifs = ['en_ligne'];

    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    #[Assert\Positive]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private int $delaiExpirationPanierMinutes = 15;

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

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getCouleurs(): ?array
    {
        return $this->couleurs;
    }

    /** @param array<string, string>|null $couleurs */
    public function setCouleurs(?array $couleurs): self
    {
        $this->couleurs = $couleurs;

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
        $this->langues = $langues === [] ? ['fr'] : array_values($langues);

        return $this;
    }

    /** @return list<string> */
    public function getCanauxActifs(): array
    {
        return $this->canauxActifs;
    }

    /** @param list<string> $canauxActifs */
    public function setCanauxActifs(array $canauxActifs): self
    {
        $this->canauxActifs = array_values($canauxActifs);

        return $this;
    }

    public function getDelaiExpirationPanierMinutes(): int
    {
        return $this->delaiExpirationPanierMinutes;
    }

    public function setDelaiExpirationPanierMinutes(int $delaiExpirationPanierMinutes): self
    {
        $this->delaiExpirationPanierMinutes = $delaiExpirationPanierMinutes;

        return $this;
    }
}
