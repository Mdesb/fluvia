<?php

declare(strict_types=1);

namespace App\PublicApi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une application tierce : la societe qui s'integre, pas l'etablissement qui l'autorise.
 *
 * ⚠ **ELLE N'APPARTIENT A AUCUN ETABLISSEMENT, ET C'EST LA DIFFERENCE AVEC UN JETON DE BORNE.** Un
 * `JetonTerminal` est frappe par un exploitant pour SON materiel : il porte donc un etablissement.
 * Une application tierce sert plusieurs clients — c'est meme tout l'interet d'un agregateur. Lui
 * coller un etablissement obligerait a en creer une par client, et l'effet de reseau qu'on cherche
 * s'evapore.
 *
 * Ce qui rattache une application a des donnees, c'est {@see ApiGrant}, jamais cette entite.
 *
 * Aucune operation API Platform ne l'expose : elle se gere depuis l'administration editeur, comme
 * `JetonTerminal` se gere depuis les operations `Terminal`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'public_api_application')]
class PartnerApplication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** A qui on ecrit quand une cle fuit ou qu'une version se retire. */
    #[ORM\Column(length: 180)]
    private string $contactEmail = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }
}
