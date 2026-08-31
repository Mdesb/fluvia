<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\EtatControleur;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrôleur rattaché à un concentrateur ITBOX (référence logique, pas de FK dure) et à un
 * EspaceAcces (US-L3-01). Porte le cycle réseau (état, heartbeat) support de la bascule
 * online/offline automatique (§4.6, RG-ACC-05) et la version de la liste de révocation embarquée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_controleur')]
#[ApiResource(
    shortName: 'Controleur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.gerer')"),
        new Patch(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['controleur:read']],
    denormalizationContext: ['groups' => ['controleur:write']],
)]
class Controleur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['controleur:read', 'equipement:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['controleur:read', 'controleur:write', 'equipement:read', 'passage:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Un contrôleur orphelin (sans espace) est refusé (CA-1).')]
    #[Groups(['controleur:read', 'controleur:write', 'equipement:read'])]
    private ?EspaceAcces $espace = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank(message: 'Un contrôleur sans ITBOX est refusé (CA-1).')]
    #[Groups(['controleur:read', 'controleur:write'])]
    private string $itboxRef = '';

    /**
     * LES AUTRES ZONES QUE CE LECTEUR DESSERT, EN PLUS DE LA SIENNE.
     *
     * Un tourniquet placé entre la piscine et la salle de sport dessert les deux. Sans cette
     * collection, un abonnement salle s'y voyait refuser l'entrée dès qu'une déclaration de zone
     * existait sur le produit — le contrôleur ne connaissait qu'un espace.
     *
     * ⚠ ELLE N'ÉLARGIT QUE LA DÉCISION, ET C'EST DÉLIBÉRÉ.
     *
     * `$espace` reste l'espace PRINCIPAL, celui de la porte physique. La jauge décrémentée, la
     * portée de l'anti-passback et l'espace inscrit sur le passage restent les siens, même quand le
     * titre est accepté au titre d'un espace desservi : le porteur a franchi CETTE porte, et c'est
     * la seule chose qui reste vraie physiquement.
     *
     * Un exploitant qui veut deux jauges distinctes a besoin de deux lecteurs — le modèle le permet
     * déjà, et l'inverse reviendrait à décompter une entrée à un endroit où personne n'est passé.
     *
     * @var Collection<int, EspaceAcces>
     */
    #[ORM\ManyToMany(targetEntity: EspaceAcces::class)]
    #[ORM\JoinTable(name: 'access_controller_served_space')]
    #[Groups(['controleur:read', 'controleur:write'])]
    private Collection $servedSpaces;

    #[ORM\Column(length: 16, enumType: EtatControleur::class, options: ['default' => 'en_ligne'])]
    #[Groups(['controleur:read', 'controleur:write'])]
    private EtatControleur $etat = EtatControleur::EnLigne;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['controleur:read'])]
    private ?\DateTimeImmutable $dernierHeartbeat = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['controleur:read'])]
    private int $versionRevocation = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['controleur:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->servedSpaces = new ArrayCollection();
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

    public function getEspace(): ?EspaceAcces
    {
        return $this->espace;
    }

    public function setEspace(?EspaceAcces $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getItboxRef(): string
    {
        return $this->itboxRef;
    }

    public function setItboxRef(string $itboxRef): self
    {
        $this->itboxRef = $itboxRef;

        return $this;
    }

    public function getEtat(): EtatControleur
    {
        return $this->etat;
    }

    public function setEtat(EtatControleur $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getDernierHeartbeat(): ?\DateTimeImmutable
    {
        return $this->dernierHeartbeat;
    }

    public function setDernierHeartbeat(?\DateTimeImmutable $dernierHeartbeat): self
    {
        $this->dernierHeartbeat = $dernierHeartbeat;

        return $this;
    }

    public function getVersionRevocation(): int
    {
        return $this->versionRevocation;
    }

    public function setVersionRevocation(int $versionRevocation): self
    {
        $this->versionRevocation = $versionRevocation;

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

    /** @return Collection<int, EspaceAcces> */
    public function getServedSpaces(): Collection
    {
        return $this->servedSpaces;
    }

    public function addServedSpace(EspaceAcces $espace): self
    {
        if (!$this->servedSpaces->contains($espace)) {
            $this->servedSpaces->add($espace);
        }

        return $this;
    }

    /**
     * ⚠ Posé en même temps que l'ajout, jamais après.
     *
     * Le sérialiseur de Symfony n'accepte une collection en écriture que si l'ajout ET le retrait
     * existent ; sans les deux il ignore la propriété, sans erreur. `SousReseau` en est mort la
     * nuit du 28 : `PATCH { espaces: [...] }` répondait 200 et n'enregistrait rien.
     */
    public function removeServedSpace(EspaceAcces $espace): self
    {
        $this->servedSpaces->removeElement($espace);

        return $this;
    }

    /**
     * Tous les espaces que ce lecteur dessert, le principal compris.
     *
     * Un seul calcul, plusieurs appelants : la décision d'accès s'y adosse plutôt que de recomposer
     * l'union à chaque endroit — c'est ainsi qu'un appelant finit par en oublier une moitié.
     *
     * @return list<EspaceAcces>
     */
    public function espacesOuverts(): array
    {
        $espaces = $this->espace === null ? [] : [$this->espace];
        foreach ($this->servedSpaces as $desservi) {
            $espaces[] = $desservi;
        }

        return $espaces;
    }
}
