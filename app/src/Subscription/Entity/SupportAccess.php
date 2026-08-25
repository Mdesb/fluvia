<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'autorisation, nominative et datée, pour un agent de l'éditeur de lire l'établissement d'un client
 * (ED-4, RG-ED-07).
 *
 * **Il n'existe aucun rôle qui voit tous les établissements.** C'est la phrase de RG-ED-07, et cette
 * entité est ce qui la rend vraie : l'accès d'assistance n'est pas un privilège attaché à une
 * personne, c'est une ouverture ponctuelle attachée à un **couple** agent-établissement, avec une fin.
 * Un éditeur qui se donnerait un rôle « support » global aurait, de fait, un accès permanent aux
 * données de tous ses clients — et personne ne saurait dire qui a regardé quoi.
 *
 * **La fin est obligatoire, pas optionnelle.** `expiresAt` n'est pas nullable. Un accès sans échéance
 * est un accès permanent qui s'ignore : il survit à l'incident qui l'a justifié, au départ de l'agent,
 * et à la mémoire de celui qui l'a ouvert.
 *
 * **Le motif est obligatoire aussi.** Sans lui, la trace d'audit dit « quelqu'un a regardé » sans dire
 * pourquoi, ce qui ne permet ni de justifier l'accès au client, ni de repérer celui qui ne se justifie
 * pas.
 *
 * **Révoquer n'efface pas.** Un accès révoqué reste en base, avec sa date : c'est l'historique de qui
 * a pu voir quoi, et il n'aurait aucune valeur si on pouvait le faire disparaître.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_support_access')]
#[ORM\Index(name: 'idx_support_access_grantee_target', columns: ['grantee_id', 'establishment_id'])]
class SupportAccess
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** La personne nommée. Jamais un rôle, jamais une équipe (RG-ED-07). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $grantee = null;

    /** L'établissement client dont la lecture est ouverte — un seul. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $establishment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $grantedAt;

    /** Non nullable : voir le commentaire de classe. Un accès sans fin n'est pas un accès borné. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $reason = '';

    /** Qui a ouvert l'accès. Chaîne et non relation : la trace doit survivre à la suppression du compte. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $grantedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->grantedAt = new \DateTimeImmutable();
        $this->expiresAt = $this->grantedAt;
    }

    /**
     * L'accès est-il utilisable à cet instant ?
     *
     * Trois conditions, et l'ordre ne compte pas parce qu'elles doivent **toutes** être vraies : pas
     * révoqué, déjà commencé, pas encore expiré. C'est la seule méthode qui décide, et elle vit sur
     * l'entité pour qu'aucun appelant ne puisse en écrire une quatrième version un peu différente.
     */
    public function isUsableAt(\DateTimeImmutable $instant): bool
    {
        if (null !== $this->revokedAt && $this->revokedAt <= $instant) {
            return false;
        }

        return $instant >= $this->grantedAt && $instant < $this->expiresAt;
    }

    public function revoke(\DateTimeImmutable $instant): self
    {
        // Une seconde révocation ne repousse pas la première : c'est la date où l'accès a cessé qui
        // compte, pas celle où on l'a redit.
        $this->revokedAt ??= $instant;

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGrantee(): ?Utilisateur
    {
        return $this->grantee;
    }

    public function setGrantee(?Utilisateur $grantee): self
    {
        $this->grantee = $grantee;

        return $this;
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

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function setGrantedAt(\DateTimeImmutable $grantedAt): self
    {
        $this->grantedAt = $grantedAt;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getGrantedBy(): ?string
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?string $grantedBy): self
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }
}
