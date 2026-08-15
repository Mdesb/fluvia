<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Lettrage d'une ligne d'écriture (§4.2 spec) : rapprochement recette / mode de paiement / versement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_lettrage_ecriture')]
class LettrageEcriture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ecriture:lettrage'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LigneEcriture::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:lettrage'])]
    private ?LigneEcriture $ligne = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['ecriture:lettrage'])]
    private \DateTimeImmutable $dateLettrage;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:lettrage'])]
    private ?Utilisateur $auteur = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateLettrage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLigne(): ?LigneEcriture
    {
        return $this->ligne;
    }

    public function setLigne(?LigneEcriture $ligne): self
    {
        $this->ligne = $ligne;

        return $this;
    }

    public function getDateLettrage(): \DateTimeImmutable
    {
        return $this->dateLettrage;
    }

    public function setDateLettrage(\DateTimeImmutable $dateLettrage): self
    {
        $this->dateLettrage = $dateLettrage;

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
}
