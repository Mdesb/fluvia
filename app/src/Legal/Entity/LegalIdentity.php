<?php

declare(strict_types=1);

namespace App\Legal\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Legal\Enum\SalesActivity;
use App\Legal\State\EstablishmentStampProcessor;
use App\Legal\State\GenerateLegalDocumentsProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CE QUE L'EXPLOITANT DOIT RENSEIGNER UNE FOIS, ET QUI ALIMENTE LES SIX DOCUMENTS.
 *
 * **Pourquoi une entité séparée du texte.** La raison sociale, le SIRET, le siège et l'hébergeur
 * apparaissent dans plusieurs documents à la fois. Saisis dans chaque texte, ils divergent — et le jour
 * où l'entreprise déménage, l'exploitant corrige les mentions légales, oublie les CGV, et publie deux
 * adresses contradictoires sur le même site.
 *
 * *Un seul calcul, deux appelants* : ici, une seule saisie, six documents.
 *
 * **Rien n'est obligatoire au sens du schéma, et tout l'est au sens de la loi.** C'est délibéré :
 * refuser l'enregistrement d'une fiche incomplète empêcherait l'exploitant de la remplir en plusieurs
 * fois, et il n'y reviendrait pas. C'est le générateur qui **nomme ce qui manque**, document par
 * document, plutôt qu'un formulaire qui refuse en bloc.
 *
 * > **Une contrainte qui bloque une saisie en cours ne protège rien : elle produit un brouillon dans un
 * > fichier à côté.**
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_identity')]
#[ORM\UniqueConstraint(name: 'uniq_legal_identity_establishment', columns: ['establishment_id'])]
#[ApiResource(
    shortName: 'LegalIdentity',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'boutique.gerer_vitrine')"),
        new Get(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'boutique.gerer_vitrine')"),
        new Post(
            security: "is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['legal_identity:write']],
            processor: EstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['legal_identity:write']],
        ),
        // Compose les six brouillons depuis la fiche. Ne touche jamais un document publie : un geste
        // de confort ne doit pas pouvoir remettre un avertissement de brouillon sur la boutique.
        new Post(
            uriTemplate: '/legal/identites/{id}/generer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'organisation.gerer')",
            processor: GenerateLegalDocumentsProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['legal_identity:read']],
)]
class LegalIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['legal_identity:read'])]
    private Uuid $id;

    /**
     * ABSENT DU GROUPE D'ECRITURE, ET C'EST LE POINT (D41).
     *
     * Expose en ecriture, l'appelant choisirait **de qui sont les mentions legales** : n'importe
     * quel gestionnaire publierait, sous le nom d'un autre exploitant, le document qui l'engage
     * envers ses clients. `EstablishmentStampProcessor` le pose depuis la session serveur.
     *
     * Pas d'assertion `NotNull` non plus : la validation tourne AVANT le processeur, et elle ferait
     * echouer en 422 une creation parfaitement legitime. La colonne `NOT NULL` et le processeur --
     * qui refuse plutot que de deviner -- tiennent l'invariant.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['legal_identity:read'])]
    private ?Etablissement $establishment = null;

    // --- L'éditeur du site -----------------------------------------------------------------------

    #[ORM\Column(length: 200)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private string $legalName = '';

    /** SARL, SAS, régie, EPIC, commune… La forme change les mentions exigées ensuite. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $legalForm = null;

    /** Capital social : exigé des sociétés commerciales, sans objet pour une régie ou une commune. */
    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $shareCapital = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $siret = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $tradeRegister = null;

    /** TVA intracommunautaire — obligatoire dès qu'on facture en ligne. */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $vatNumber = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $registeredAddress = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $contactPhone = null;

    /** Directeur de la publication : une personne physique nommée, pas un service. */
    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $publicationDirector = null;

    // --- L'hébergeur, que tout le monde oublie ----------------------------------------------------

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $hostName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $hostAddress = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $hostPhone = null;

    // --- Le médiateur de la consommation ----------------------------------------------------------

    /**
     * Tout professionnel vendant à des consommateurs doit **adhérer à un dispositif de médiation** et
     * en communiquer les coordonnées. Ce n'est pas une clause de style : son absence est une infraction
     * autonome, indépendamment de tout litige.
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $mediatorName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $mediatorUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $mediatorAddress = null;

    // --- Données personnelles ---------------------------------------------------------------------

    /** Délégué à la protection des données : nommément obligatoire pour un organisme public. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private ?string $dataProtectionOfficer = null;

    // --- Ce qui est vendu, donc ce que diront les CGV ----------------------------------------------

    /**
     * @var list<string> valeurs de `SalesActivity`
     *
     * Le champ qui décide des clauses de rétractation. Vide, le générateur refuse d'écrire des CGV
     * plutôt que d'en produire de fausses.
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['legal_identity:read', 'legal_identity:write'])]
    private array $activities = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function setLegalName(string $legalName): self
    {
        $this->legalName = $legalName;

        return $this;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function setLegalForm(?string $legalForm): self
    {
        $this->legalForm = $legalForm;

        return $this;
    }

    public function getShareCapital(): ?string
    {
        return $this->shareCapital;
    }

    public function setShareCapital(?string $shareCapital): self
    {
        $this->shareCapital = $shareCapital;

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

    public function getTradeRegister(): ?string
    {
        return $this->tradeRegister;
    }

    public function setTradeRegister(?string $tradeRegister): self
    {
        $this->tradeRegister = $tradeRegister;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): self
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getRegisteredAddress(): ?string
    {
        return $this->registeredAddress;
    }

    public function setRegisteredAddress(?string $registeredAddress): self
    {
        $this->registeredAddress = $registeredAddress;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): self
    {
        $this->contactPhone = $contactPhone;

        return $this;
    }

    public function getPublicationDirector(): ?string
    {
        return $this->publicationDirector;
    }

    public function setPublicationDirector(?string $publicationDirector): self
    {
        $this->publicationDirector = $publicationDirector;

        return $this;
    }

    public function getHostName(): ?string
    {
        return $this->hostName;
    }

    public function setHostName(?string $hostName): self
    {
        $this->hostName = $hostName;

        return $this;
    }

    public function getHostAddress(): ?string
    {
        return $this->hostAddress;
    }

    public function setHostAddress(?string $hostAddress): self
    {
        $this->hostAddress = $hostAddress;

        return $this;
    }

    public function getHostPhone(): ?string
    {
        return $this->hostPhone;
    }

    public function setHostPhone(?string $hostPhone): self
    {
        $this->hostPhone = $hostPhone;

        return $this;
    }

    public function getMediatorName(): ?string
    {
        return $this->mediatorName;
    }

    public function setMediatorName(?string $mediatorName): self
    {
        $this->mediatorName = $mediatorName;

        return $this;
    }

    public function getMediatorUrl(): ?string
    {
        return $this->mediatorUrl;
    }

    public function setMediatorUrl(?string $mediatorUrl): self
    {
        $this->mediatorUrl = $mediatorUrl;

        return $this;
    }

    public function getMediatorAddress(): ?string
    {
        return $this->mediatorAddress;
    }

    public function setMediatorAddress(?string $mediatorAddress): self
    {
        $this->mediatorAddress = $mediatorAddress;

        return $this;
    }

    public function getDataProtectionOfficer(): ?string
    {
        return $this->dataProtectionOfficer;
    }

    public function setDataProtectionOfficer(?string $dataProtectionOfficer): self
    {
        $this->dataProtectionOfficer = $dataProtectionOfficer;

        return $this;
    }

    /** @return list<string> */
    public function getActivities(): array
    {
        return $this->activities;
    }

    /** @param list<string> $activities */
    public function setActivities(array $activities): self
    {
        $this->activities = array_values(array_filter(
            $activities,
            static fn (mixed $v): bool => \is_string($v) && SalesActivity::tryFrom($v) !== null,
        ));

        return $this;
    }

    /**
     * Les activités comme objets, les valeurs inconnues écartées.
     *
     * @return list<SalesActivity>
     */
    public function activityCases(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $v): ?SalesActivity => SalesActivity::tryFrom($v),
            $this->activities,
        )));
    }
}
