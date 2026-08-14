<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Avoir issu d'une annulation/remboursement (RG-M2-07, décision actée). Contre-passation tracée :
 * horodatage, motif, opérateur, rattachement à la vente d'origine ; aucune ligne d'origine n'est
 * supprimée. Une annulation après impression invalide le support émis côté Accès (US-L2-09).
 * Créé via annuler/rembourser ; immuable (NF525), aucune écriture API exposée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_avoir')]
#[ORM\UniqueConstraint(name: 'uniq_avoir_numero', columns: ['numero'])]
#[ApiResource(
    shortName: 'Avoir',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
    ],
    normalizationContext: ['groups' => ['avoir:read']],
)]
class Avoir
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['avoir:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Groups(['avoir:read'])]
    private string $numero = '';

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['avoir:read'])]
    private ?Vente $venteOrigine = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['avoir:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 255)]
    #[Groups(['avoir:read'])]
    private string $motif = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['avoir:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['avoir:read'])]
    private \DateTimeImmutable $dateHeure;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['avoir:read'])]
    private bool $supportInvalide = false;

    /** Type : « annulation » ou « remboursement » (traçabilité RG-M2-07). */
    #[ORM\Column(length: 16, options: ['default' => 'annulation'])]
    #[Groups(['avoir:read'])]
    private string $nature = 'annulation';

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getVenteOrigine(): ?Vente
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(?Vente $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }

    public function isSupportInvalide(): bool
    {
        return $this->supportInvalide;
    }

    public function setSupportInvalide(bool $supportInvalide): self
    {
        $this->supportInvalide = $supportInvalide;

        return $this;
    }

    public function getNature(): string
    {
        return $this->nature;
    }

    public function setNature(string $nature): self
    {
        $this->nature = $nature;

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
