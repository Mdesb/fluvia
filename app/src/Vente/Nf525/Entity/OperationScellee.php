<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\State\VerifierChaineProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Maillon de la chaîne d'inaltérabilité NF525 (US-L2-11). Une chaîne par point de vente : chaque
 * opération validée (vente, avoir, clôture) est scellée dans la transaction de validation, chaînée
 * au précédent (empreinte = hash(payload + empreinte précédente)) et signée. Append-only : aucun
 * PATCH/DELETE (ni API ni ORM, garde par InalterabiliteListener). Une rupture (trou de séquence /
 * empreinte incohérente / signature invalide) est détectée par verifieChaine (CA-15).
 */
#[ORM\Entity]
#[ORM\Table(name: 'nf525_operation_scellee')]
#[ORM\UniqueConstraint(name: 'uniq_op_pdv_sequence', columns: ['point_de_vente_id', 'numero_sequence'])]
#[ApiResource(
    shortName: 'OperationScellee',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.lire')"),
        new Get(security: "is_granted('PERM', 'caisse.lire')"),
        new Post(
            uriTemplate: '/nf525/verifier-chaine',
            read: false,
            input: false,
            security: "is_granted('PERM', 'caisse.lire')",
            processor: VerifierChaineProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['nf525:read']],
)]
class OperationScellee
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['nf525:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['nf525:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\Column(length: 24, enumType: TypeOperationScellee::class)]
    #[Groups(['nf525:read'])]
    private TypeOperationScellee $typeOperation = TypeOperationScellee::Vente;

    #[ORM\Column(length: 64)]
    #[Groups(['nf525:read'])]
    private string $cibleType = '';

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['nf525:read'])]
    private Uuid $cibleId;

    #[ORM\Column(type: 'bigint')]
    #[Groups(['nf525:read'])]
    private int $numeroSequence = 0;

    #[ORM\Column(length: 128)]
    #[Groups(['nf525:read'])]
    private string $empreinte = '';

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['nf525:read'])]
    private ?string $empreintePrecedente = null;

    #[ORM\Column(length: 512)]
    #[Groups(['nf525:read'])]
    private string $signature = '';

    /** @var array<string, mixed> Données figées de l'opération, rejouables pour recontrôle. */
    #[ORM\Column]
    #[Groups(['nf525:read'])]
    private array $payloadCanonique = [];

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['nf525:read'])]
    private \DateTimeImmutable $horodatage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->cibleId = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getPointDeVente(): ?PointDeVente
    {
        return $this->pointDeVente;
    }

    public function setPointDeVente(?PointDeVente $pointDeVente): self
    {
        $this->pointDeVente = $pointDeVente;

        return $this;
    }

    public function getTypeOperation(): TypeOperationScellee
    {
        return $this->typeOperation;
    }

    public function setTypeOperation(TypeOperationScellee $typeOperation): self
    {
        $this->typeOperation = $typeOperation;

        return $this;
    }

    public function getCibleType(): string
    {
        return $this->cibleType;
    }

    public function setCibleType(string $cibleType): self
    {
        $this->cibleType = $cibleType;

        return $this;
    }

    public function getCibleId(): Uuid
    {
        return $this->cibleId;
    }

    public function setCibleId(Uuid $cibleId): self
    {
        $this->cibleId = $cibleId;

        return $this;
    }

    public function getNumeroSequence(): int
    {
        return $this->numeroSequence;
    }

    public function setNumeroSequence(int $numeroSequence): self
    {
        $this->numeroSequence = $numeroSequence;

        return $this;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(string $empreinte): self
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function getEmpreintePrecedente(): ?string
    {
        return $this->empreintePrecedente;
    }

    public function setEmpreintePrecedente(?string $empreintePrecedente): self
    {
        $this->empreintePrecedente = $empreintePrecedente;

        return $this;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function setSignature(string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayloadCanonique(): array
    {
        return $this->payloadCanonique;
    }

    /** @param array<string, mixed> $payloadCanonique */
    public function setPayloadCanonique(array $payloadCanonique): self
    {
        $this->payloadCanonique = $payloadCanonique;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }
}
