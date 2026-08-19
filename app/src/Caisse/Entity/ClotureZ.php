<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caisse\Enum\TypeCloture;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture Z (RG-M2-06 / CA-14) : totalise ventes, moyens de paiement, remboursements, calcule
 * l'écart théorique vs compté, produit un état de régie archivé/ré-imprimable, fige la session.
 * Irréversible et immuable (NF525) : aucune opération d'écriture exposée, protégée par le listener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_cloture_z')]
#[ApiResource(
    shortName: 'ClotureZ',
    operations: [
        // RG-CAISSEZ-03 : le Z (attendu/écart/détail par moyen/état de régie) exige désormais
        // `caisse.voir_z` (au lieu de `caisse.lire`, trop large — aurait laissé un profil « lecture
        // seule » voir le Z). Un porteur de `caisse.*` (wildcard, ex. admin) reste couvert
        // automatiquement (RG-CAISSEZ-09, cf. `CalculateurDroits::autorise()`).
        new GetCollection(security: "is_granted('PERM', 'caisse.voir_z')"),
        new Get(security: "is_granted('PERM', 'caisse.voir_z')"),
        new Get(
            uriTemplate: '/clotures-z/{id}/etat-regie',
            security: "is_granted('PERM', 'caisse.voir_z')",
            normalizationContext: ['groups' => ['cloture:read', 'cloture:etat']],
        ),
    ],
    normalizationContext: ['groups' => ['cloture:read']],
)]
class ClotureZ
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['cloture:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['cloture:read'])]
    private ?SessionCaisse $session = null;

    /** @var list<array{moyen: string, theorique: string, compte: string, ecart: string}> */
    #[ORM\Column]
    #[Groups(['cloture:read'])]
    private array $comptages = [];

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['cloture:read'])]
    private string $totalVentes = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['cloture:read'])]
    private string $totalRemboursements = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['cloture:read'])]
    private string $versement = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['cloture:read'])]
    private string $fondReporte = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['cloture:read'])]
    private string $ecartTotal = '0.00';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['cloture:read'])]
    private \DateTimeImmutable $horodatage;

    /** @var array<string, mixed> Document de régie figé, ré-imprimable / exportable (RG-M2-06). */
    #[ORM\Column]
    #[Groups(['cloture:read', 'cloture:etat'])]
    private array $etatDeRegie = [];

    #[ORM\Column(length: 12, enumType: TypeCloture::class, options: ['default' => 'Z'])]
    #[Groups(['cloture:read'])]
    private TypeCloture $typeCloture = TypeCloture::Z;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    public function setSession(?SessionCaisse $session): self
    {
        $this->session = $session;

        return $this;
    }

    /** @return list<array{moyen: string, theorique: string, compte: string, ecart: string}> */
    public function getComptages(): array
    {
        return $this->comptages;
    }

    /** @param list<array{moyen: string, theorique: string, compte: string, ecart: string}> $comptages */
    public function setComptages(array $comptages): self
    {
        $this->comptages = $comptages;

        return $this;
    }

    public function getTotalVentes(): string
    {
        return $this->totalVentes;
    }

    public function setTotalVentes(string $totalVentes): self
    {
        $this->totalVentes = $totalVentes;

        return $this;
    }

    public function getTotalRemboursements(): string
    {
        return $this->totalRemboursements;
    }

    public function setTotalRemboursements(string $totalRemboursements): self
    {
        $this->totalRemboursements = $totalRemboursements;

        return $this;
    }

    public function getVersement(): string
    {
        return $this->versement;
    }

    public function setVersement(string $versement): self
    {
        $this->versement = $versement;

        return $this;
    }

    public function getFondReporte(): string
    {
        return $this->fondReporte;
    }

    public function setFondReporte(string $fondReporte): self
    {
        $this->fondReporte = $fondReporte;

        return $this;
    }

    public function getEcartTotal(): string
    {
        return $this->ecartTotal;
    }

    public function setEcartTotal(string $ecartTotal): self
    {
        $this->ecartTotal = $ecartTotal;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    /** @return array<string, mixed> */
    public function getEtatDeRegie(): array
    {
        return $this->etatDeRegie;
    }

    /** @param array<string, mixed> $etatDeRegie */
    public function setEtatDeRegie(array $etatDeRegie): self
    {
        $this->etatDeRegie = $etatDeRegie;

        return $this;
    }

    public function getTypeCloture(): TypeCloture
    {
        return $this->typeCloture;
    }

    public function setTypeCloture(TypeCloture $typeCloture): self
    {
        $this->typeCloture = $typeCloture;

        return $this;
    }
}
