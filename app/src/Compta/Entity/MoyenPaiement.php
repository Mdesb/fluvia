<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Référentiel réel des moyens de paiement (RG-M2-02, §3 du plan) : M6 en est la source, consommée
 * par M2 via `ReferentielReglementDoctrineAdapter implements App\Vente\Port\ReferentielReglementInterface`.
 * Le filtrage par acte de régie reste porté par `PointDeVente.moyensAutorises` (M2, cohérence
 * manuelle avec `RegieRecettes.modesAutorises`, non synchronisée automatiquement dans ce lot).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_moyen_paiement')]
#[ORM\UniqueConstraint(name: 'uniq_moyen_code', columns: ['code'])]
#[ApiResource(
    shortName: 'MoyenPaiement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['moyen:read']],
    denormalizationContext: ['groups' => ['moyen:write']],
)]
class MoyenPaiement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['moyen:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['moyen:read', 'moyen:write'])]
    private string $code = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['moyen:read', 'moyen:write'])]
    private string $libelle = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['moyen:read', 'moyen:write'])]
    private bool $autoriseRendu = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['moyen:read', 'moyen:write'])]
    private bool $exigeReference = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['moyen:read', 'moyen:write'])]
    private bool $autoriseDiffere = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['moyen:read', 'moyen:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
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

    public function isAutoriseRendu(): bool
    {
        return $this->autoriseRendu;
    }

    public function setAutoriseRendu(bool $autoriseRendu): self
    {
        $this->autoriseRendu = $autoriseRendu;

        return $this;
    }

    public function isExigeReference(): bool
    {
        return $this->exigeReference;
    }

    public function setExigeReference(bool $exigeReference): self
    {
        $this->exigeReference = $exigeReference;

        return $this;
    }

    public function isAutoriseDiffere(): bool
    {
        return $this->autoriseDiffere;
    }

    public function setAutoriseDiffere(bool $autoriseDiffere): self
    {
        $this->autoriseDiffere = $autoriseDiffere;

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
}
