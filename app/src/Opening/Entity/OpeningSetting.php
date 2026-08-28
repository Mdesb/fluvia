<?php

declare(strict_types=1);

namespace App\Opening\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Organisation\Entity\Etablissement;
use App\Opening\State\OpeningSettingProcessor;
use App\Opening\State\OpeningSettingProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * LE SEUL INTERRUPTEUR DU MODULE : est-ce que le planning FAIT LOI au contrôle d'accès ?
 *
 * ── POURQUOI IL EXISTE, ET POURQUOI IL EST À « NON » PAR DÉFAUT ─────────────────────────────────
 *
 * Maxime a tranché le 28/08 : le refus hors horaires est **activable par site, jamais appliqué par
 * défaut**. La raison tient en une image — un contrôle d'accès qui se met à refuser du monde sans
 * qu'on l'ait voulu, c'est une file à l'entrée un samedi matin et un exploitant qui n'a aucune idée
 * d'où ça vient.
 *
 * Le sens sûr de l'erreur est d'ordinaire celui qui restreint. Ici il s'inverse : restreindre, c'est
 * refuser des clients qui ont payé. Un site qui saisit ses horaires « pour information » — pour les
 * afficher sur sa boutique, pour remplir son agenda — ne doit pas découvrir le lendemain qu'il a
 * fermé sa porte.
 *
 * ── UN RÉGLAGE PAR ÉTABLISSEMENT, CRÉÉ À LA DEMANDE ─────────────────────────────────────────────
 *
 * L'absence de ligne vaut « non appliqué ». On ne provisionne donc rien à l'ouverture d'un client :
 * `OpeningCalendar` lit l'absence comme un refus d'appliquer, ce qui est exactement le défaut
 * voulu, et le `Patch` crée la ligne le jour où quelqu'un coche la case.
 */
#[ORM\Entity]
#[ORM\Table(name: 'opening_setting')]
#[ORM\UniqueConstraint(name: 'uniq_opening_setting_establishment', fields: ['establishment'])]
#[ApiResource(
    shortName: 'OpeningSetting',
    operations: [
        // Le fournisseur CRÉE la ligne si elle n'existe pas : sans elle, `Patch` n'a pas
        // d'identifiant à viser et la case « faire appliquer » resterait morte.
        new GetCollection(
            security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')",
            provider: OpeningSettingProvider::class,
        ),
        new Get(security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')"),
        new Patch(
            security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'acces.gerer')",
            processor: OpeningSettingProcessor::class,
        ),
    ],
    routePrefix: '/opening',
    normalizationContext: ['groups' => ['opening_setting:read']],
    denormalizationContext: ['groups' => ['opening_setting:write']],
)]
class OpeningSetting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['opening_setting:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['opening_setting:read'])]
    private ?Etablissement $establishment = null;

    /**
     * `false` par défaut, et c'est la décision du 28/08 en une ligne de code. Voir l'en-tête.
     */
    #[ORM\Column]
    #[Groups(['opening_setting:read', 'opening_setting:write'])]
    private bool $enforced = false;

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

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    public function setEnforced(bool $enforced): self
    {
        $this->enforced = $enforced;

        return $this;
    }
}
