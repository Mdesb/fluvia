<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\Validator as OffreAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Offre\State\TenantReferenceProcessor;
use App\Organisation\Entity\Etablissement;

/**
 * Tranche de quotient familial (US-L1-04) : bornes [min, max] rattachées à un type de tarif.
 * Les tranches d'un même type de tarif doivent être contiguës, sans trou ni chevauchement,
 * afin qu'une valeur de QF résolve toujours vers une et une seule tranche (CA-6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_tranche_qf')]
#[OffreAssert\TranchesQfCoherentes]
#[ApiResource(
    shortName: 'TrancheQuotientFamilial',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        // D51 — entierement cloisonne : l etablissement est estampille depuis le CONTEXTE
        // serveur, jamais lu du corps de la requete (D3/D8). C est la seule raison pour laquelle un
        // appelant ne peut pas deposer sa saison ou sa grille de quotient chez le voisin.
        new Post(security: "is_granted('PERM', 'offre.gerer')", processor: TenantReferenceProcessor::class),
        new Patch(security: "is_granted('PERM', 'offre.gerer')", processor: TenantReferenceProcessor::class),
        new Delete(security: "is_granted('PERM', 'offre.gerer')"),
    ],
    normalizationContext: ['groups' => ['qf:read']],
    denormalizationContext: ['groups' => ['qf:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['typeTarif' => 'exact'])]
class TrancheQuotientFamilial
{
    /**
     * L'établissement propriétaire. **Nul uniquement pour les lignes antérieures au cloisonnement**
     * (D51) — et une ligne nulle n'est visible de personne.
     *
     * C'est voulu : la migration les laisse orphelines plutôt que de leur inventer un propriétaire.
     * Une saison rattachée au hasard produirait des tarifs calculés sur la saison d'un autre
     * établissement, et pour les tranches de quotient familial, D51 rappelle qu'une erreur de grille
     * est une **erreur de facturation opposable**. Une donnée manquante reste visiblement manquante ;
     * une donnée fausse ne se voit pas.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['qf:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['qf:read', 'grille:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TypeTarif::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['qf:read', 'qf:write', 'grille:read'])]
    private ?TypeTarif $typeTarif = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Groups(['qf:read', 'qf:write', 'grille:read'])]
    private ?string $borneMin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\Expression(
        'this.getBorneMax() === null or this.getBorneMin() === null or this.getBorneMax() > this.getBorneMin()',
        message: 'La borne max doit être strictement supérieure à la borne min.'
    )]
    #[Groups(['qf:read', 'qf:write', 'grille:read'])]
    private ?string $borneMax = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTypeTarif(): ?TypeTarif
    {
        return $this->typeTarif;
    }

    public function setTypeTarif(?TypeTarif $typeTarif): self
    {
        $this->typeTarif = $typeTarif;

        return $this;
    }

    public function getBorneMin(): ?string
    {
        return $this->borneMin;
    }

    public function setBorneMin(?string $borneMin): self
    {
        $this->borneMin = $borneMin;

        return $this;
    }

    public function getBorneMax(): ?string
    {
        return $this->borneMax;
    }

    public function setBorneMax(?string $borneMax): self
    {
        $this->borneMax = $borneMax;

        return $this;
    }

    /** Vrai si la valeur de QF tombe dans [borneMin, borneMax[ (borne max exclue pour contiguïté). */
    public function contient(float $qf): bool
    {
        return $qf >= (float) $this->borneMin && $qf < (float) $this->borneMax;
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
