<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\StatutAffutage;
use App\Patinoire\Enum\TypeAffutage;
use App\Patinoire\State\DemarrerAffutageProcessor;
use App\Patinoire\State\TerminerAffutageProcessor;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Affûtage à double casquette (RG-PAT-06, décision actée « affûtage », US-PATIN-06/07, §4.6) :
 * `prestation_client` (patins personnels du client, ligne de vente dédiée, **aucun impact** sur le
 * parc, CA-6) ou `maintenance_parc` (article du parc de location, immobilisation temporaire
 * `ParcPatins.quantiteEnAffutage`, **aucune vente**, CA-7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_affutage')]
#[ApiResource(
    shortName: 'PatinoireAffutage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        // Corps : { "type": "prestation_client"|"maintenance_parc", "technicien": iri|uuid,
        //           "ligneVente"?: iri|uuid, "parcPatins"?: iri|uuid }
        new Post(
            uriTemplate: '/patinoire/affutages',
            read: false,
            // D41 : le processeur lit le corps brut et ignore l'objet deserialise ; sans
            // `input: false`, API Platform denormalise quand meme le corps dans l'entite, et
            // cette entite n'ayant aucun `denormalizationContext`, toute propriete munie d'un
            // mutateur devient ecrivable — l'etablissement compris. Meme forme que
            // `/padel/niveaux/declarer` et `/sport/abonnements/souscrire`, qui la portent deja.
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_affutage')",
            processor: DemarrerAffutageProcessor::class,
        ),
        new Post(
            uriTemplate: '/patinoire/affutages/{id}/terminer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_affutage')",
            processor: TerminerAffutageProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['affutage:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'statut' => 'exact', 'parcPatins' => 'exact', 'etablissement' => 'exact'])]
class Affutage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['affutage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 18, enumType: TypeAffutage::class)]
    #[Groups(['affutage:read'])]
    private ?TypeAffutage $type = null;

    #[ORM\ManyToOne(targetEntity: LigneVente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['affutage:read'])]
    private ?LigneVente $ligneVente = null;

    #[ORM\ManyToOne(targetEntity: ParcPatins::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['affutage:read'])]
    private ?ParcPatins $parcPatins = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['affutage:read'])]
    private ?Utilisateur $technicien = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['affutage:read'])]
    private \DateTimeImmutable $dateEntreeAtelier;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['affutage:read'])]
    private ?\DateTimeImmutable $dateSortieAtelier = null;

    #[ORM\Column(length: 10, enumType: StatutAffutage::class, options: ['default' => 'en_attente'])]
    #[Groups(['affutage:read'])]
    private StatutAffutage $statut = StatutAffutage::EnAttente;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['affutage:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEntreeAtelier = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): ?TypeAffutage
    {
        return $this->type;
    }

    public function setType(?TypeAffutage $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getLigneVente(): ?LigneVente
    {
        return $this->ligneVente;
    }

    public function setLigneVente(?LigneVente $ligneVente): self
    {
        $this->ligneVente = $ligneVente;

        return $this;
    }

    public function getParcPatins(): ?ParcPatins
    {
        return $this->parcPatins;
    }

    public function setParcPatins(?ParcPatins $parcPatins): self
    {
        $this->parcPatins = $parcPatins;

        return $this;
    }

    public function getTechnicien(): ?Utilisateur
    {
        return $this->technicien;
    }

    public function setTechnicien(?Utilisateur $technicien): self
    {
        $this->technicien = $technicien;

        return $this;
    }

    public function getDateEntreeAtelier(): \DateTimeImmutable
    {
        return $this->dateEntreeAtelier;
    }

    public function setDateEntreeAtelier(\DateTimeImmutable $dateEntreeAtelier): self
    {
        $this->dateEntreeAtelier = $dateEntreeAtelier;

        return $this;
    }

    public function getDateSortieAtelier(): ?\DateTimeImmutable
    {
        return $this->dateSortieAtelier;
    }

    public function setDateSortieAtelier(?\DateTimeImmutable $dateSortieAtelier): self
    {
        $this->dateSortieAtelier = $dateSortieAtelier;

        return $this;
    }

    public function getStatut(): StatutAffutage
    {
        return $this->statut;
    }

    public function setStatut(StatutAffutage $statut): self
    {
        $this->statut = $statut;

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
}
