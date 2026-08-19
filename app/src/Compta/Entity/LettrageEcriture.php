<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\State\LettrerGroupeProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Lettrage d'une ligne d'écriture (§4.2 spec) : rapprochement recette / mode de paiement / versement.
 *
 * Extension FIN-1 (RG-M6-14, additive) : `reconciliationCode` (nullable) partagé entre toutes les
 * `LettrageEcriture` créées ensemble par un lettrage **groupé** (`LettrageHandler::lettrerGroupe()`).
 * `null` pour un lettrage simple (`lettrer()`, inchangé, RG-M6-14 « reste disponible »).
 *
 * `#[ApiResource]` ajouté par ce lot : l'entité n'était accessible qu'en interne jusqu'ici (appelée
 * uniquement par `ReglementFactureHandler`, M4), aucune opération existante retirée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_lettrage_ecriture')]
#[ApiResource(
    shortName: 'LettrageEcriture',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/lettrages/groupe',
            read: false,
            input: false,
            output: false,
            security: "is_granted('PERM', 'compta.lettrer')",
            processor: LettrerGroupeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ecriture:lettrage']],
)]
#[ApiFilter(SearchFilter::class, properties: ['ligne' => 'exact', 'reconciliationCode' => 'exact'])]
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

    /** Partagé entre toutes les lignes d'un même lettrage groupé (RG-M6-14) ; `null` en lettrage simple. */
    #[ORM\Column(length: 36, nullable: true)]
    #[Groups(['ecriture:lettrage'])]
    private ?string $reconciliationCode = null;

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

    public function getReconciliationCode(): ?string
    {
        return $this->reconciliationCode;
    }

    public function setReconciliationCode(?string $reconciliationCode): self
    {
        $this->reconciliationCode = $reconciliationCode;

        return $this;
    }
}
