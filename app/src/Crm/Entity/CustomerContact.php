<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE PERSONNE CHEZ UN CLIENT PROFESSIONNEL — avec sa fonction.
 *
 * **Ce que `Beneficiaire` ne pouvait pas dire.** Il porte `RoleBeneficiaire` = *payeur* / *bénéficiaire*
 * / *les deux*. C'est une sémantique de **famille** : qui paie l'abonnement du gamin, qui entre à la
 * piscine. Elle ne sait pas dire « Mme X, directrice », « M. Y, comptabilité », « celui qui signe ».
 *
 * **Ce que ça coûtait.** Une société avec un seul interlocuteur nommé dans un champ texte est ce qui
 * fait perdre un client le jour où cette personne part : le devis attend une signature de quelqu'un
 * qui n'est plus là, la facture arrive dans une boîte fermée, et personne ne sait à qui écrire.
 *
 * > **Une entreprise n'est pas une personne : c'est plusieurs personnes qui n'ont pas le même rôle,
 * > et se tromper de rôle coûte une facture impayée.**
 *
 * ---
 *
 * **UN SEUL CONTACT PRINCIPAL PAR CLIENT, ET C'EST UNE CONTRAINTE DE BASE.**
 *
 * L'index unique partiel n'existe pas en MySQL : on ne peut pas déclarer « unique là où `principal`
 * est vrai ». La colonne `principal_actif` porte donc l'identifiant du client **quand** le contact est
 * principal, et `null` sinon — un index unique dessus rend la règle indéfectible, parce que MySQL
 * ignore les `null` dans un index unique.
 *
 * L'astuce mérite d'être expliquée plutôt que découverte : tenir cette règle en PHP la laisserait
 * tomber au premier import, au premier script, à la première requête concurrente. **Deux contacts
 * principaux ne se voient pas — on écrit simplement au mauvais.**
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_customer_contact')]
#[ORM\UniqueConstraint(name: 'uniq_customer_contact_primary', columns: ['primary_for_customer_id'])]
#[ORM\Index(name: 'idx_customer_contact_customer', columns: ['customer_id'])]
#[ApiResource(
    shortName: 'CustomerContact',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
        new Post(
            security: "is_granted('PERM', 'crm.creer') or is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['customer_contact:write']],
        ),
        new Patch(
            security: "is_granted('PERM', 'crm.modifier')",
            denormalizationContext: ['groups' => ['customer_contact:write']],
        ),
        new Delete(security: "is_granted('PERM', 'crm.modifier')"),
    ],
    normalizationContext: ['groups' => ['customer_contact:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['customer' => 'exact'])]
class CustomerContact
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['customer_contact:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?Client $customer = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private string $lastName = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?string $firstName = null;

    /**
     * La fonction, en clair et non dans une liste fermée.
     *
     * C'est l'inverse du choix fait pour les motifs de perte, et pour la raison inverse : un motif se
     * **compte**, une fonction se **lit**. « Responsable des sorties scolaires » n'entre dans aucune
     * nomenclature, et l'y forcer produirait « Autre » sur la moitié des fiches.
     */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private ?string $note = null;

    /**
     * `true` quand ce contact est l'interlocuteur principal — champ **de commodité**, exposé à
     * l'écriture. La contrainte, elle, est portée par `primaryForCustomer`.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['customer_contact:read', 'customer_contact:write'])]
    private bool $primaryContact = false;

    /**
     * L'identifiant du client **quand ce contact est principal**, `null` sinon.
     *
     * Jamais écrit directement : c'est `setPrimaryContact()` qui le tient. Il n'existe que pour porter
     * l'index unique — MySQL ignorant les `null`, la règle « un seul principal par client » devient
     * une contrainte de base et non une intention.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'primary_for_customer_id', nullable: true)]
    private ?Client $primaryForCustomer = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['customer_contact:read'])]
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

    public function getCustomer(): ?Client
    {
        return $this->customer;
    }

    public function setCustomer(?Client $customer): self
    {
        $this->customer = $customer;
        // Le miroir suit le client : sans ça, déplacer un contact principal d'une société à une autre
        // laisserait la contrainte pointer sur l'ancienne.
        if ($this->primaryContact) {
            $this->primaryForCustomer = $customer;
        }

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): self
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function isPrimaryContact(): bool
    {
        return $this->primaryContact;
    }

    /**
     * Les deux champs bougent ensemble, toujours.
     *
     * Un appelant qui poserait `primaryContact` sans le miroir créerait un principal que la base
     * n'empêche plus de dupliquer — c'est-à-dire exactement la situation qu'on veut rendre impossible.
     */
    public function setPrimaryContact(bool $primaryContact): self
    {
        $this->primaryContact = $primaryContact;
        $this->primaryForCustomer = $primaryContact ? $this->customer : null;

        return $this;
    }

    public function getPrimaryForCustomer(): ?Client
    {
        return $this->primaryForCustomer;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Le nom affichable, prénom d'abord — c'est ainsi qu'on s'adresse à quelqu'un. */
    #[Groups(['customer_contact:read'])]
    public function getDisplayName(): string
    {
        $nom = trim(($this->firstName ?? '') . ' ' . $this->lastName);

        return $nom !== '' ? $nom : $this->lastName;
    }
}
