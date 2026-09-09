<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use App\Organisation\Entity\Etablissement;
use App\Signature\Entity\ElectronicSignature;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * LE CONTRAT D'ABONNEMENT SIGNÉ, GELÉ AU MOMENT DE LA SIGNATURE.
 *
 * `documentText` est le texte EXACT que le client a vu et signé. On ne le RECOMPOSE pas plus tard
 * depuis les termes de l'abonnement : ceux-ci peuvent bouger (le montant courant, notamment), et un
 * contrat qui changerait après signature ne prouverait plus ce qui a été convenu. Le gel du texte,
 * plus le hash de ce texte porté par la `signature`, font que ce qui est opposable est ce qui a été
 * présenté — pas une reconstruction.
 *
 * La `signature` (module `App\Signature`) porte la preuve : image manuscrite, identité, horodatage,
 * et le hash du document, le tout scellé et inaltérable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_contract')]
class SubscriptionContract
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** L'abonnement dont ce contrat gèle les termes (`subscription`, jamais `abonnement` : D5). */
    #[ORM\ManyToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?AbonnementFitness $subscription = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    /** Le texte EXACT signé — gelé, jamais recomposé. C'est lui qui est haché par la signature. */
    #[ORM\Column(type: 'text')]
    private string $documentText = '';

    #[ORM\OneToOne(targetEntity: ElectronicSignature::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ElectronicSignature $signature = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscription(): ?AbonnementFitness
    {
        return $this->subscription;
    }

    public function setSubscription(?AbonnementFitness $subscription): self
    {
        $this->subscription = $subscription;

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

    public function getDocumentText(): string
    {
        return $this->documentText;
    }

    public function setDocumentText(string $documentText): self
    {
        $this->documentText = $documentText;

        return $this;
    }

    public function getSignature(): ?ElectronicSignature
    {
        return $this->signature;
    }

    public function setSignature(?ElectronicSignature $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
