<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Enum\StatutPmv;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Porte-monnaie virtuel (RG-M4-03) : solde prépayé avec échéance, 1:1 avec `Client`, créé **à la
 * demande** (1ʳᵉ recharge). Le solde ne peut jamais devenir négatif (invariant applicatif et débit
 * atomique côté `App\Crm\Adapter\PorteMonnaieVirtuelAdapter`, §2.2 plan-crm.md).
 *
 * Pas de `#[ApiResource]` propre : exposé exclusivement via les sous-ressources déclarées sur
 * `App\Crm\Entity\Client` (`/clients/{id}/pmv`, `/pmv/mouvements`, `/pmv/recharger`) pour éviter
 * l'ambiguïté d'un `{id}` d'URI désignant en réalité un `Client` (pattern API Platform).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_pmv')]
#[ORM\UniqueConstraint(name: 'uniq_pmv_client', columns: ['client_id'])]
class PorteMonnaieVirtuel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pmv:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['pmv:read'])]
    private ?Client $client = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['pmv:read'])]
    private string $solde = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Groups(['pmv:read'])]
    private string $devise = 'EUR';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['pmv:read'])]
    private ?\DateTimeImmutable $dateEcheance = null;

    #[ORM\Column(length: 12, enumType: StatutPmv::class, options: ['default' => 'expire'])]
    #[Groups(['pmv:read'])]
    private StatutPmv $statut = StatutPmv::Expire;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getSolde(): string
    {
        return $this->solde;
    }

    public function setSolde(string $solde): self
    {
        $this->solde = $solde;

        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): self
    {
        $this->devise = $devise;

        return $this;
    }

    public function getDateEcheance(): ?\DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(?\DateTimeImmutable $dateEcheance): self
    {
        $this->dateEcheance = $dateEcheance;

        return $this;
    }

    public function getStatut(): StatutPmv
    {
        return $this->statut;
    }

    public function setStatut(StatutPmv $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function estActif(): bool
    {
        return $this->statut === StatutPmv::Actif;
    }

    public function estExpire(\DateTimeImmutable $reference = new \DateTimeImmutable()): bool
    {
        return $this->dateEcheance !== null && $this->dateEcheance < $reference;
    }
}
