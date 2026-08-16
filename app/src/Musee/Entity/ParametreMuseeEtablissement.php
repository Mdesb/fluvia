<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\ModeSeuil;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres établissement de la verticale musée (décision structurante n°7 du plan) : centralise les
 * valeurs non chiffrées par les sources (seuil pastille « tendu », taux de remise bascule audioguide,
 * délai d'option dossier groupe/scolaire) — même patron que `ParametragePadel`/`ParametrePiscineEtablissement`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_parametre_etablissement')]
#[ORM\UniqueConstraint(name: 'uniq_parametre_musee_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'MuseeParametreEtablissement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer')"),
        new Patch(security: "is_granted('PERM', 'musee.gerer')"),
    ],
    normalizationContext: ['groups' => ['musee_parametre:read']],
    denormalizationContext: ['groups' => ['musee_parametre:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact'])]
class ParametreMuseeEtablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['musee_parametre:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['musee_parametre:read', 'musee_parametre:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 20])]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['musee_parametre:read', 'musee_parametre:write'])]
    private int $seuilPastilleTendu = 20;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => '20.00'])]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['musee_parametre:read', 'musee_parametre:write'])]
    private string $tauxRemiseAudioguideDefaut = '20.00';

    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    #[Assert\Positive]
    #[Groups(['musee_parametre:read', 'musee_parametre:write'])]
    private int $delaiOptionDossierGroupeJours = 15;

    #[ORM\Column(length: 8, enumType: ModeSeuil::class, options: ['default' => 'alerte'])]
    #[Groups(['musee_parametre:read', 'musee_parametre:write'])]
    private ModeSeuil $modeSousQuotaSalleDefaut = ModeSeuil::Alerte;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getSeuilPastilleTendu(): int
    {
        return $this->seuilPastilleTendu;
    }

    public function setSeuilPastilleTendu(int $seuilPastilleTendu): self
    {
        $this->seuilPastilleTendu = $seuilPastilleTendu;

        return $this;
    }

    public function getTauxRemiseAudioguideDefaut(): string
    {
        return $this->tauxRemiseAudioguideDefaut;
    }

    public function setTauxRemiseAudioguideDefaut(string $tauxRemiseAudioguideDefaut): self
    {
        $this->tauxRemiseAudioguideDefaut = $tauxRemiseAudioguideDefaut;

        return $this;
    }

    public function getDelaiOptionDossierGroupeJours(): int
    {
        return $this->delaiOptionDossierGroupeJours;
    }

    public function setDelaiOptionDossierGroupeJours(int $delaiOptionDossierGroupeJours): self
    {
        $this->delaiOptionDossierGroupeJours = $delaiOptionDossierGroupeJours;

        return $this;
    }

    public function getModeSousQuotaSalleDefaut(): ModeSeuil
    {
        return $this->modeSousQuotaSalleDefaut;
    }

    public function setModeSousQuotaSalleDefaut(ModeSeuil $modeSousQuotaSalleDefaut): self
    {
        $this->modeSousQuotaSalleDefaut = $modeSousQuotaSalleDefaut;

        return $this;
    }
}
