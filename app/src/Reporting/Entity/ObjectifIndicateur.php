<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\State\ObjectifIndicateurProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Valeur cible d'un indicateur sur une période/un périmètre (§1.5 plan-reporting.md) — ajouté par
 * le plan (absent du §5 « Objets de données » de la spec) pour rendre testable CA-3 (« écart vs
 * objectifs »). CRUD `reporting.configurer` en écriture, filtré périmètre en lecture.
 */
/**
 * OBJECTIF SUR UN INDICATEUR.
 *
 * ⚠ CINQ `Assert\NotNull` ONT ÉTÉ RETIRÉS ICI LE 05/09, ET IL FAUT SAVOIR POURQUOI AVANT D'EN
 * REPOSER. `Post` et `Patch` sont déclarées `input: false` : plus rien n'est désérialisé, et
 * API Platform valide ENTRE la désérialisation et le processeur. Ces contraintes ne voyaient
 * donc plus jamais la charge du client — elles ne gardaient rien, tout en donnant à lire
 * qu'elles gardaient quelque chose. C'est exactement la faute que décrit le garde-fou n°34 :
 * « croire la contrainte appliquée à la valeur fabriquée ».
 *
 * L'exigence n'a pas disparu, elle a changé de place, et elle tient maintenant à deux endroits
 * qui, eux, s'exécutent :
 *
 *   ObjectifIndicateurProcessor   un 422 qui nomme le champ, avant toute écriture
 *   les colonnes                  toutes `nullable: false`, la jointure comprise
 *
 * ⚠ SI QUELQU'UN REND UN JOUR CES OPÉRATIONS DÉSÉRIALISANTES en retirant `input: false`, il
 * faut REPOSER ces contraintes dans le même geste : sans elles, un champ omis ne serait plus
 * arrêté par le processeur mais par la base, et un 422 deviendrait un 500.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_objectif_indicateur')]
#[ApiResource(
    shortName: 'ObjectifIndicateur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        // ⚠ `input: false` ET UN PROCESSEUR, PARCE QUE LA VOIE STANDARD N'ECRIVAIT PAS LE
        // RATTACHEMENT. `RattachementNiveauTrait` n'expose aucun setter -- seulement
        // `definirRattachement*()`, qui pose le niveau et la cle ensemble. Le deserialiseur
        // ignorait donc `niveau`, `etablissement`, `region` et `groupe` en silence : un POST
        // rendait 201 et creait une ligne sans perimetre, invisible et non supprimable (mesure :
        // DELETE -> 404 sur une ligne qu'on venait de creer).
        new Post(
            security: "is_granted('PERM', 'reporting.configurer')",
            input: false,
            processor: ObjectifIndicateurProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'reporting.configurer')",
            input: false,
            processor: ObjectifIndicateurProcessor::class,
        ),
        new Delete(security: "is_granted('PERM', 'reporting.configurer')"),
    ],
    normalizationContext: ['groups' => ['objectif:read']],
    denormalizationContext: ['groups' => ['objectif:write']],
)]
class ObjectifIndicateur implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['objectif:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Indicateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['objectif:read', 'objectif:write'])]
    private ?Indicateur $indicateur = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['objectif:read', 'objectif:write'])]
    private \DateTimeImmutable $periodeDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['objectif:read', 'objectif:write'])]
    private \DateTimeImmutable $periodeFin;

    #[ORM\Column(length: 8, enumType: GranulariteMesure::class)]
    #[Groups(['objectif:read', 'objectif:write'])]
    private GranulariteMesure $granularite = GranulariteMesure::Jour;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[Groups(['objectif:read', 'objectif:write'])]
    private string $valeurCible = '0.00';

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->periodeDebut = new \DateTimeImmutable('today');
        $this->periodeFin = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicateur(): ?Indicateur
    {
        return $this->indicateur;
    }

    public function setIndicateur(?Indicateur $indicateur): self
    {
        $this->indicateur = $indicateur;

        return $this;
    }

    public function getPeriodeDebut(): \DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(\DateTimeImmutable $periodeDebut): self
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): \DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(\DateTimeImmutable $periodeFin): self
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getGranularite(): GranulariteMesure
    {
        return $this->granularite;
    }

    public function setGranularite(GranulariteMesure $granularite): self
    {
        $this->granularite = $granularite;

        return $this;
    }

    public function getValeurCible(): string
    {
        return $this->valeurCible;
    }

    public function setValeurCible(string $valeurCible): self
    {
        $this->valeurCible = $valeurCible;

        return $this;
    }
}
