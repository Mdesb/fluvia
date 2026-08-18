<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Facturation\Enum\TypeDestinataire;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Destinataire de facturation — **instantané figé** au moment de l'émission (RG-FACT-08,
 * `spec-facturation.md` §4.4). Une modification ultérieure de la fiche `Client` (M4) n'altère
 * jamais un document déjà émis : c'est une obligation de stabilité du document, cohérente avec
 * l'inaltérabilité NF525 (constitution §4.5).
 *
 * Une ligne **par document**, jamais partagée entre deux factures même pour un même client :
 * le partage rendrait possible une répercussion accidentelle. `clientRef` reste une référence
 * **logique** (Uuid, sans FK dure — même précédent que `Vente::$client` et
 * `EcritureComptable::$venteOrigine` côté M2/M6), ce qui autorise la facturation ponctuelle d'une
 * collectivité **sans** créer de fiche CRM (§4.4, hypothèse assumée par la spec).
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_destinataire')]
class DestinataireFacturation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facture:read', 'destinataire:read'])]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: TypeDestinataire::class, options: ['default' => 'particulier'])]
    #[Assert\NotNull]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private TypeDestinataire $type = TypeDestinataire::Particulier;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?string $nom = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?string $raisonSociale = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET doit comporter 14 chiffres.')]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?string $siret = null;

    /** Optionnel même pour une personne morale (auto-entrepreneur, hors UE, franchise — §7 spec). */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?string $tvaIntracommunautaire = null;

    /** @var array<string, mixed> {rue, complement, cp, ville, pays} */
    #[ORM\Column]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private array $adresse = [];

    /** Référence logique vers `App\Crm\Entity\Client` (M4) — sans FK, la fiche peut ne pas exister. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private ?Uuid $clientRef = null;

    /** Conditionne l'éligibilité au dépôt Chorus Pro (B2G, RG-FACT-07). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['facture:read', 'destinataire:read', 'destinataire:write'])]
    private bool $estOrganismePublic = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): TypeDestinataire
    {
        return $this->type;
    }

    public function setType(TypeDestinataire $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): self
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(?string $raisonSociale): self
    {
        $this->raisonSociale = $raisonSociale;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): self
    {
        $this->siret = $siret;

        return $this;
    }

    public function getTvaIntracommunautaire(): ?string
    {
        return $this->tvaIntracommunautaire;
    }

    public function setTvaIntracommunautaire(?string $tvaIntracommunautaire): self
    {
        $this->tvaIntracommunautaire = $tvaIntracommunautaire;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAdresse(): array
    {
        return $this->adresse;
    }

    /** @param array<string, mixed> $adresse */
    public function setAdresse(array $adresse): self
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getClientRef(): ?Uuid
    {
        return $this->clientRef;
    }

    public function setClientRef(?Uuid $clientRef): self
    {
        $this->clientRef = $clientRef;

        return $this;
    }

    public function isEstOrganismePublic(): bool
    {
        return $this->estOrganismePublic;
    }

    public function setEstOrganismePublic(bool $estOrganismePublic): self
    {
        $this->estOrganismePublic = $estOrganismePublic;

        return $this;
    }

    /** Dénomination imprimée sur le document (RG-FACT-02). */
    public function denomination(): string
    {
        if ($this->type === TypeDestinataire::PersonneMorale) {
            return $this->raisonSociale ?? '';
        }

        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    /**
     * Cohérence RG-FACT-08 : une personne morale doit porter raison sociale + SIRET ; un particulier
     * doit porter un nom. La TVA intracommunautaire reste toujours facultative (§7 spec).
     *
     * @return list<string> messages d'anomalie (vide = conforme)
     */
    public function anomalies(): array
    {
        $anomalies = [];
        if ($this->type === TypeDestinataire::PersonneMorale) {
            if ($this->raisonSociale === null || trim($this->raisonSociale) === '') {
                $anomalies[] = 'La raison sociale est requise pour une personne morale.';
            }
            if ($this->siret === null || trim($this->siret) === '') {
                $anomalies[] = 'Le SIRET est requis pour une personne morale.';
            }
        } elseif ($this->nom === null || trim($this->nom) === '') {
            $anomalies[] = 'Le nom est requis pour un particulier.';
        }
        if ($this->adresse === []) {
            $anomalies[] = "L'adresse du destinataire est requise (mention légale obligatoire).";
        }

        return $anomalies;
    }

    /** Copie profonde — garantit qu'un instantané n'est jamais partagé entre deux documents. */
    public function copier(): self
    {
        $copie = new self();
        $copie->type = $this->type;
        $copie->nom = $this->nom;
        $copie->prenom = $this->prenom;
        $copie->raisonSociale = $this->raisonSociale;
        $copie->siret = $this->siret;
        $copie->tvaIntracommunautaire = $this->tvaIntracommunautaire;
        $copie->adresse = $this->adresse;
        $copie->clientRef = $this->clientRef;
        $copie->estOrganismePublic = $this->estOrganismePublic;

        return $copie;
    }
}
