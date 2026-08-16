<?php

declare(strict_types=1);

namespace App\Reporting\Entity\Trait;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Enum\NiveauEntite;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Rattachement polymorphe (§1.1 plan-reporting.md) : triplet niveau + 3 FK nullables, une seule
 * renseignée cohérente avec `niveau` (contrôlé en service/Processor, pas en contrainte SQL CHECK,
 * pour rester portable Doctrine). `region`/`groupe` sont dénormalisés (ancêtres directs) même sur
 * une ligne `niveau = etablissement`, pour filtrer une région/un groupe sans jointure.
 *
 * Les groupes de sérialisation couvrent volontairement toutes les entités qui utilisent ce trait
 * (`mesure:read`, `objectif:read/write`, `tdb:read/write`, `rapport:read/write`, `destinataire:*`,
 * `export:read`) : un groupe non pertinent pour une entité donnée est simplement ignoré côté
 * normalizer (pas d'effet de bord).
 */
trait RattachementNiveauTrait
{
    #[ORM\Column(length: 20, enumType: NiveauEntite::class)]
    #[Groups(['mesure:read', 'objectif:read', 'objectif:write', 'tdb:read', 'tdb:write', 'rapport:read', 'destinataire:read', 'destinataire:write', 'export:read'])]
    private NiveauEntite $niveau = NiveauEntite::Etablissement;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mesure:read', 'objectif:read', 'objectif:write', 'tdb:read', 'tdb:write', 'rapport:read', 'destinataire:read', 'destinataire:write', 'export:read'])]
    private ?Etablissement $etablissement = null;

    /** Dénormalisé : renseigné pour les lignes `etablissement` (ancêtre) ET `region`. */
    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mesure:read', 'objectif:read', 'objectif:write', 'tdb:read', 'tdb:write', 'rapport:read', 'destinataire:read', 'destinataire:write', 'export:read'])]
    private ?Region $region = null;

    /** Dénormalisé : renseigné sur TOUTES les lignes (ancêtre racine). */
    #[ORM\ManyToOne(targetEntity: Groupe::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mesure:read', 'objectif:read', 'objectif:write', 'tdb:read', 'tdb:write', 'rapport:read', 'destinataire:read', 'destinataire:write', 'export:read'])]
    private ?Groupe $groupe = null;

    public function getNiveau(): NiveauEntite
    {
        return $this->niveau;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    /**
     * Point d'entrée unique pour définir un rattachement cohérent (§1.1) : dénormalise
     * automatiquement région/groupe à partir de l'entité de niveau le plus fin fournie.
     */
    public function definirRattachementEtablissement(Etablissement $etablissement): static
    {
        $this->niveau = NiveauEntite::Etablissement;
        $this->etablissement = $etablissement;
        $this->region = $etablissement->getRegion();
        $this->groupe = $this->region?->getGroupe();

        return $this;
    }

    public function definirRattachementRegion(Region $region): static
    {
        $this->niveau = NiveauEntite::Region;
        $this->etablissement = null;
        $this->region = $region;
        $this->groupe = $region->getGroupe();

        return $this;
    }

    public function definirRattachementGroupe(Groupe $groupe): static
    {
        $this->niveau = NiveauEntite::Groupe;
        $this->etablissement = null;
        $this->region = null;
        $this->groupe = $groupe;

        return $this;
    }
}
