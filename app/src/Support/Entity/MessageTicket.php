<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Securite\Entity\Utilisateur;
use App\Support\Enum\AuteurTypeMessage;
use App\Support\State\MessageTicketProcessor;
use App\Support\State\MessageTicketProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message du fil d'un `TicketSupport` (US-SUP-11, RG-SUP-12) : demandeur ↔ agent, note interne
 * (`noteInterne=true`) jamais visible du demandeur — filtrée par `MessageTicketProvider`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_message_ticket')]
#[ApiResource(
    shortName: 'MessageTicket',
    operations: [
        new GetCollection(
            uriTemplate: '/support/tickets/{ticketId}/messages',
            security: "is_granted('PERM', 'support.ouvrir_ticket') or is_granted('PERM', 'support.lire_ticket_etablissement') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
            provider: MessageTicketProvider::class,
        ),
        new Post(
            uriTemplate: '/support/tickets/{ticketId}/messages',
            security: "is_granted('PERM', 'support.ouvrir_ticket') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2')",
            processor: MessageTicketProcessor::class,
            denormalizationContext: ['groups' => ['message:write']],
        ),
    ],
    normalizationContext: ['groups' => ['message:read']],
)]
class MessageTicket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['message:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TicketSupport::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['message:read'])]
    private ?TicketSupport $ticket = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['message:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(length: 10, enumType: AuteurTypeMessage::class)]
    #[Groups(['message:read'])]
    private AuteurTypeMessage $auteurType = AuteurTypeMessage::Demandeur;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['message:read', 'message:write'])]
    private string $contenu = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['message:read', 'message:write'])]
    private bool $noteInterne = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['message:read'])]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTicket(): ?TicketSupport
    {
        return $this->ticket;
    }

    public function setTicket(?TicketSupport $ticket): self
    {
        $this->ticket = $ticket;

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

    public function getAuteurType(): AuteurTypeMessage
    {
        return $this->auteurType;
    }

    public function setAuteurType(AuteurTypeMessage $auteurType): self
    {
        $this->auteurType = $auteurType;

        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function isNoteInterne(): bool
    {
        return $this->noteInterne;
    }

    public function setNoteInterne(bool $noteInterne): self
    {
        $this->noteInterne = $noteInterne;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
