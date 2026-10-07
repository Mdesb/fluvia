<?php

declare(strict_types=1);

namespace App\Signature\Entity;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Signature\Enum\SignedDocumentType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * SIGNATURE ÉLECTRONIQUE AVANCÉE, SCELLÉE (eIDAS niveau « avancé », fait maison).
 *
 * Elle ne « qualifie » rien au sens eIDAS art. 25.2 — ça exigerait un certificat d'un prestataire
 * accrédité. Mais elle porte les trois propriétés d'une signature AVANCÉE, et c'est ce que réclament
 * un mandat SEPA et un contrat d'abonnement B2C :
 *
 *   1. LIÉE AU SIGNATAIRE — le client identifié au comptoir (`signer`) et son nom saisi ;
 *   2. LIÉE AU DOCUMENT — `documentHash` est le SHA-256 du document EXACT présenté au signataire.
 *      Modifier le mandat/contrat après coup rend le hash faux : la preuve ne colle plus ;
 *   3. INTÈGRE ET OPPOSABLE — l'ensemble (hash du document, image manuscrite, identité, opérateur,
 *      horodatage, IP) est SCELLÉ dans une chaîne de hachages chaînés + HMAC (même procédé que
 *      NF525, dupliqué et non couplé), et rendu INALTÉRABLE par `SignatureImmutabilityListener`.
 *
 * En cas de litige, la charge de la preuve nous revient (faute de certificat qualifié) : c'est
 * précisément ce faisceau scellé qui la porte. Pour un mandat SEPA, le débiteur peut contester
 * jusqu'à 13 mois — la trace scellée est notre opposabilité.
 *
 * ⚠ APPEND-ONLY. Aucun PATCH/DELETE (garde ORM par `SignatureImmutabilityListener`). Une signature
 * qu'on pourrait réécrire ne prouverait plus rien.
 */
#[ORM\Entity]
#[ORM\Table(name: 'electronic_signature')]
#[ORM\UniqueConstraint(name: 'uniq_electronic_signature_seq', columns: ['etablissement_id', 'sequence_number'])]
class ElectronicSignature
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * ANCRE DE LA CHAÎNE ET DU CLOISONNEMENT. La chaîne de hachages est chaînée PAR ÉTABLISSEMENT :
     * chaque établissement a sa propre suite de séquences, et une signature ne se compare qu'aux
     * siennes. Non nul — une preuve sans périmètre ne s'oppose à personne. (`etablissement` reprend
     * la classe historique `Etablissement` — référence permise, pas du vocabulaire neuf.)
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 24, enumType: SignedDocumentType::class)]
    private SignedDocumentType $documentType;

    /** Type court de l'entité signée (« SepaMandate », « SubscriptionContract ») — le document visé. */
    #[ORM\Column(length: 64)]
    private string $targetType = '';

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $targetId;

    /** SHA-256 du document EXACT présenté au signataire : le lien signature ↔ document (eIDAS). */
    #[ORM\Column(length: 128)]
    private string $documentHash = '';

    /**
     * L'image de la signature manuscrite capturée au canvas (PNG encodé, `data:` sans le préfixe),
     * ou `null` pour un consentement sans dessin. Son HASH est inclus dans le sceau — on ne scelle
     * pas ses octets (une image de plusieurs Ko dans le payload rejouable n'ajouterait aucune preuve
     * qu'un hash n'apporte déjà).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signatureImage = null;

    #[ORM\Column(length: 255)]
    private string $signerName = '';

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $signer = null;

    /** L'opérateur qui a recueilli la signature au comptoir — un maillon du faisceau de preuve. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $operator = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $signedAt;

    // ── Sceau (chaîne d'inaltérabilité) ─────────────────────────────────────────────────────────

    #[ORM\Column(type: 'bigint')]
    private int $sequenceNumber = 0;

    #[ORM\Column(length: 128)]
    private string $hash = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $previousHash = null;

    /** Le HMAC-SHA256 du hash sous la clé de scellement — nommé « seal » et non « signature » pour ne
     *  pas le confondre avec la signature manuscrite qu'il protège. */
    #[ORM\Column(length: 512)]
    private string $seal = '';

    /** @var array<string, mixed> Le faisceau figé, rejouable pour recontrôle. */
    #[ORM\Column]
    private array $canonicalPayload = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->targetId = Uuid::v4();
        $this->signedAt = new \DateTimeImmutable();
        $this->documentType = SignedDocumentType::SepaMandate;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDocumentType(): SignedDocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(SignedDocumentType $documentType): self
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function setTargetId(Uuid $targetId): self
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getDocumentHash(): string
    {
        return $this->documentHash;
    }

    public function setDocumentHash(string $documentHash): self
    {
        $this->documentHash = $documentHash;

        return $this;
    }

    public function getSignatureImage(): ?string
    {
        return $this->signatureImage;
    }

    public function setSignatureImage(?string $signatureImage): self
    {
        $this->signatureImage = $signatureImage;

        return $this;
    }

    public function getSignerName(): string
    {
        return $this->signerName;
    }

    public function setSignerName(string $signerName): self
    {
        $this->signerName = $signerName;

        return $this;
    }

    public function getSigner(): ?Client
    {
        return $this->signer;
    }

    public function setSigner(?Client $signer): self
    {
        $this->signer = $signer;

        return $this;
    }

    public function getOperator(): ?Utilisateur
    {
        return $this->operator;
    }

    public function setOperator(?Utilisateur $operator): self
    {
        $this->operator = $operator;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getSignedAt(): \DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(\DateTimeImmutable $signedAt): self
    {
        $this->signedAt = $signedAt;

        return $this;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function setSequenceNumber(int $sequenceNumber): self
    {
        $this->sequenceNumber = $sequenceNumber;

        return $this;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function setPreviousHash(?string $previousHash): self
    {
        $this->previousHash = $previousHash;

        return $this;
    }

    public function getSeal(): string
    {
        return $this->seal;
    }

    public function setSeal(string $seal): self
    {
        $this->seal = $seal;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCanonicalPayload(): array
    {
        return $this->canonicalPayload;
    }

    /** @param array<string, mixed> $canonicalPayload */
    public function setCanonicalPayload(array $canonicalPayload): self
    {
        $this->canonicalPayload = $canonicalPayload;

        return $this;
    }
}
