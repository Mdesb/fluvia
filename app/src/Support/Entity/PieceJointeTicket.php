<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Support\State\PieceJointeTicketCollectionProvider;
use App\Support\State\PieceJointeTicketProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pièce jointe d'un `MessageTicket` (§4.12 spec) — écriture réservée à l'auteur du message parent
 * (demandeur sur son ticket, ou agent support), lecture héritée de la visibilité du message parent
 * (vérifiée par `PieceJointeTicketProcessor`/permissions ticket).
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_piece_jointe_ticket')]
#[ApiResource(
    shortName: 'PieceJointeTicket',
    operations: [
        // ⚠ LA GARDE NE SUFFIT PAS, ET ELLE NE L'A JAMAIS FAIT. Elle demande cinq permissions
        // « ou » dont `support.ouvrir_ticket`, la plus basique du cote client : elle dit QUI
        // peut lire des pieces jointes, jamais LESQUELLES. Sans fournisseur, la collection
        // rendait celles de tous les tickets de tous les clients — avec leur `url`.
        // `MessageTicket`, la ressource voisine portant la meme donnee, avait deja son
        // fournisseur ; celle-ci non. Mesure du 31/08 : `CloisonnementPiecesJointesTest`.
        new GetCollection(
            security: "is_granted('PERM', 'support.ouvrir_ticket') or is_granted('PERM', 'support.lire_ticket_etablissement') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')",
            provider: PieceJointeTicketCollectionProvider::class,
        ),
        new Get(security: "is_granted('PERM', 'support.ouvrir_ticket') or is_granted('PERM', 'support.lire_ticket_etablissement') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2') or is_granted('PERM', 'support.administrer')"),
        new Post(security: "is_granted('PERM', 'support.ouvrir_ticket') or is_granted('PERM', 'support.traiter_ticket_n1') or is_granted('PERM', 'support.traiter_ticket_n2')", processor: PieceJointeTicketProcessor::class),
    ],
    normalizationContext: ['groups' => ['piece_jointe_ticket:read']],
    denormalizationContext: ['groups' => ['piece_jointe_ticket:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['message' => 'exact'])]
class PieceJointeTicket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['piece_jointe_ticket:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MessageTicket::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['piece_jointe_ticket:read', 'piece_jointe_ticket:write'])]
    private ?MessageTicket $message = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_ticket:read', 'piece_jointe_ticket:write'])]
    private string $nomFichier = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_ticket:read', 'piece_jointe_ticket:write'])]
    private string $typeMime = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    #[Groups(['piece_jointe_ticket:read', 'piece_jointe_ticket:write'])]
    private int $taille = 0;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_ticket:read', 'piece_jointe_ticket:write'])]
    private string $url = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['piece_jointe_ticket:read'])]
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

    public function getMessage(): ?MessageTicket
    {
        return $this->message;
    }

    public function setMessage(?MessageTicket $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): self
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getTypeMime(): string
    {
        return $this->typeMime;
    }

    public function setTypeMime(string $typeMime): self
    {
        $this->typeMime = $typeMime;

        return $this;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function setTaille(int $taille): self
    {
        $this->taille = $taille;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
