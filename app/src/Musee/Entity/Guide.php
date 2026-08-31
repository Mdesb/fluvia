<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Musee\State\EstablishmentStampProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guide (ressource, US-MUSEE-03) : ⚠ HYPOTHÈSE — `Utilisateur` socle avec affectation établissement
 * (décision structurante n°6 du plan), par analogie avec l'Encadrant MNS/BNSSA (Piscine) et
 * l'instructeur (Sport). À confirmer si un profil RH distinct émerge du module Réservation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_guide')]
#[ApiResource(
    shortName: 'MuseeGuide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer')", processor: EstablishmentStampProcessor::class),
    ],
    normalizationContext: ['groups' => ['guide:read']],
    denormalizationContext: ['groups' => ['guide:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'utilisateur' => 'exact'])]
class Guide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['guide:read', 'qualif:read', 'visite:read', 'dossier:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['guide:read', 'guide:write'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['guide:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

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
