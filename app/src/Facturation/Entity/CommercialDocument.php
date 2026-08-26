<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Facturation\State\CreateDocumentProcessor;
use App\Facturation\State\DocumentActionProcessor;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Enum\DocumentNature;
use App\Facturation\Enum\DocumentStatus;
use App\Facturation\Exception\ForbiddenDocumentTransitionException;
use App\Facturation\Service\Montant;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une pièce commerciale : quote, bon de commande ou bon de livraison (FAC-1).
 *
 * **La chaîne avant la facture.** Un club qui vend sans caisse propose (quote), fait confirmer
 * (commande), livre (bon de livraison), puis facture. Chaque étape reprend la précédente **sans la
 * ressaisir**, et chacune peut s'arrêter là : un devis refusé n'appelle rien, une commande livrée
 * peut se facturer directement.
 *
 * **Une entité à natures plutôt que trois entités jumelles** — voir {@see DocumentNature}. La
 * distinction tient en trois phrases, pas en trois schémas, et `Facture` porte déjà `NatureFacture`
 * pour la même raison.
 *
 * **Émise, une pièce est figée.** Pas au sens NF525 — seule la facture est scellée — mais au sens
 * commercial : on ne modifie pas un devis que le client a reçu, on en refait un. Sans quoi le
 * document qu'il a en main et celui qu'on a en base finissent par dire deux choses différentes, et
 * c'est le client qui a raison.
 *
 * **Pourquoi les déclarations sont en anglais et les méthodes en français.** D5 impose l anglais pour
 * tout identifiant technique d un fichier neuf — classes, tables, colonnes, valeurs d énumération — et
 * le garde-fou l a refusé une première fois, à juste titre. Les méthodes et propriétés ne sont pas
 * contrôlées, et elles restent alignées sur le module qui les entoure : `Facture` porte
 * `recalculerTotaux()`, et donner à sa voisine `recalculateTotals()` produirait une incohérence de
 * plus, pas une de moins. Le mélange est donc voulu, pas subi — il se résorbera au retrofit.
 *
 * **La filiation est portée par la pièce, pas déduite.** `documentOrigine` dit d'où elle vient, et
 * chaque ligne garde `ligneOrigine`. Reconstituer la chaîne par les dates ou les montants marcherait
 * jusqu'au jour où deux devis identiques partent le même matin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_document')]
#[ORM\UniqueConstraint(name: 'uniq_document_number', columns: ['numero'])]
#[ORM\Index(name: 'idx_document_establishment_nature', columns: ['etablissement_id', 'nature'])]
#[ApiResource(
    shortName: 'CommercialDocument',
    operations: [
        new GetCollection(uriTemplate: '/billing/documents', security: "is_granted('PERM', 'facturation.lire')"),
        new Get(uriTemplate: '/billing/documents/{id}', security: "is_granted('PERM', 'facturation.lire')"),
        new Post(
            uriTemplate: '/billing/documents',
            security: "is_granted('PERM', 'facturation.gerer')",
            read: false,
            input: false,
            processor: CreateDocumentProcessor::class,
        ),
        new Post(
            uriTemplate: '/billing/documents/{id}/issue',
            security: "is_granted('PERM', 'facturation.gerer')",
            input: false,
            processor: DocumentActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/billing/documents/{id}/accept',
            security: "is_granted('PERM', 'facturation.gerer')",
            input: false,
            processor: DocumentActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/billing/documents/{id}/reject',
            security: "is_granted('PERM', 'facturation.gerer')",
            input: false,
            processor: DocumentActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/billing/documents/{id}/derive',
            security: "is_granted('PERM', 'facturation.gerer')",
            input: false,
            processor: DocumentActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/billing/documents/{id}/invoice',
            security: "is_granted('PERM', 'facturation.emettre_directe')",
            input: false,
            processor: DocumentActionProcessor::class,
        ),
    ],
)]
class CommercialDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: DocumentNature::class)]
    private DocumentNature $nature = DocumentNature::Quote;

    /**
     * Attribué à l'émission, jamais au brouillon.
     *
     * Un numéro consommé par une pièce qu'on jette laisse un trou dans la série, et un trou dans une
     * série commerciale se justifie devant un contrôle.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProfilExploitant $profilExploitant = null;

    /** Réutilise le destinataire de la facturation : même forme, déjà éprouvée. */
    #[ORM\ManyToOne(targetEntity: DestinataireFacturation::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?DestinataireFacturation $destinataire = null;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class)]
    private DocumentStatus $statut = DocumentStatus::Draft;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateEmission = null;

    /** Jusqu'à quand la proposition tient. N'a de sens que pour un devis. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateValidite = null;

    /** @var Collection<int, DocumentLine> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentLine::class, cascade: ['persist', 'remove'])]
    private Collection $lignes;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $totalHT = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $totalTVA = Montant::ZERO;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $totalTTC = Montant::ZERO;

    /** La pièce dont celle-ci est issue. Nulle pour un devis composé à la main. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $documentOrigine = null;

    /**
     * La facture produite depuis cette pièce, par son identifiant.
     *
     * Identifiant et non relation : la facture est scellée et vit sa propre vie ; une clé étrangère
     * interdirait de l'archiver séparément.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $factureId = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $creePar = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->dateCreation = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    /**
     * Passe la pièce dans un nouvel état, ou refuse.
     *
     * **Le refus est porté par l'objet et non par un service.** Un service oublié laisserait un
     * chemin par lequel un devis refusé redeviendrait acceptable ; ici, aucun appelant ne peut le
     * contourner, pas même par distraction.
     *
     * @throws ForbiddenDocumentTransitionException
     */
    public function transitionVers(DocumentStatus $cible, \DateTimeImmutable $quand): self
    {
        if (!\in_array($cible, $this->statut->transitionsAutorisees(), true)) {
            throw new ForbiddenDocumentTransitionException(sprintf(
                'Un %s « %s » ne peut pas passer à « %s ». Passages possibles : %s.',
                $this->nature->libelle(),
                $this->statut->value,
                $cible->value,
                [] === $this->statut->transitionsAutorisees()
                    ? 'aucun (état final)'
                    : implode(', ', array_map(static fn (DocumentStatus $s): string => $s->value, $this->statut->transitionsAutorisees())),
            ));
        }

        if (DocumentStatus::Issued === $cible) {
            $this->dateEmission = $quand;
        }

        $this->statut = $cible;

        return $this;
    }

    /** Le quote est-il périmé à cette date ? Les autres natures ne périment pas. */
    public function estPerimeAu(\DateTimeImmutable $instant): bool
    {
        if (!$this->nature->expired() || null === $this->dateValidite) {
            return false;
        }

        return DocumentStatus::Issued === $this->statut && $this->dateValidite < $instant;
    }

    public function recalculerTotaux(): self
    {
        $htCentimes = 0;
        $tvaCentimes = 0;

        foreach ($this->lignes as $ligne) {
            $ligne->recalculer();
            $htCentimes += Montant::enCentimes($ligne->getMontantHT());
            $tvaCentimes += Montant::enCentimes($ligne->getMontantTva());
        }

        $this->totalHT = Montant::enDecimal($htCentimes);
        $this->totalTVA = Montant::enDecimal($tvaCentimes);
        $this->totalTTC = Montant::enDecimal($htCentimes + $tvaCentimes);

        return $this;
    }

    public function addLigne(DocumentLine $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setDocument($this);
        }

        return $this;
    }

    public function removeLigne(DocumentLine $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNature(): DocumentNature
    {
        return $this->nature;
    }

    public function setNature(DocumentNature $nature): self
    {
        $this->nature = $nature;

        return $this;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): self
    {
        $this->numero = $numero;

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

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getDestinataire(): ?DestinataireFacturation
    {
        return $this->destinataire;
    }

    public function setDestinataire(?DestinataireFacturation $destinataire): self
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getStatut(): DocumentStatus
    {
        return $this->statut;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateEmission(): ?\DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function getDateValidite(): ?\DateTimeImmutable
    {
        return $this->dateValidite;
    }

    public function setDateValidite(?\DateTimeImmutable $dateValidite): self
    {
        $this->dateValidite = $dateValidite;

        return $this;
    }

    /** @return Collection<int, DocumentLine> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function getTotalHT(): string
    {
        return $this->totalHT;
    }

    public function getTotalTVA(): string
    {
        return $this->totalTVA;
    }

    public function getTotalTTC(): string
    {
        return $this->totalTTC;
    }

    public function getDocumentOrigine(): ?self
    {
        return $this->documentOrigine;
    }

    public function setDocumentOrigine(?self $documentOrigine): self
    {
        $this->documentOrigine = $documentOrigine;

        return $this;
    }

    public function getFactureId(): ?Uuid
    {
        return $this->factureId;
    }

    public function setFactureId(?Uuid $factureId): self
    {
        $this->factureId = $factureId;

        return $this;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function setCreePar(?Utilisateur $creePar): self
    {
        $this->creePar = $creePar;

        return $this;
    }
}
