<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\StatutListeAttentePointure;
use App\Patinoire\State\AnnulerListeAttenteProcessor;
use App\Patinoire\State\InscrireListeAttenteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Inscription en liste d'attente pointure (décision actée « pointure en rupture », US-PATIN-05, §4.5).
 * `pointureVoisineProposee` est calculée à l'inscription par `ProposeurPointureVoisineHandler` (±1
 * puis ±2, paramétrable — hypothèse spec §4.5/§7). Promue automatiquement (`PromotionListeAttenteHandler`)
 * dès qu'une unité de la pointure redevient disponible (retour bon, remise en service après affûtage).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_liste_attente_pointure')]
#[ORM\UniqueConstraint(name: 'uniq_liste_attente_parc_rang', columns: ['parc_patins_id', 'rang'])]
#[ApiResource(
    shortName: 'PatinoireListeAttentePointure',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        // Corps : { "parcPatins": iri|uuid, "beneficiaire": iri|uuid }
        new Post(
            uriTemplate: '/patinoire/liste-attente',
            read: false,
            // D41 : le processeur lit le corps brut et ignore l'objet deserialise ; sans
            // `input: false`, API Platform denormalise quand meme le corps dans l'entite, et
            // cette entite n'ayant aucun `denormalizationContext`, toute propriete munie d'un
            // mutateur devient ecrivable — l'etablissement compris. Meme forme que
            // `/padel/niveaux/declarer` et `/sport/abonnements/souscrire`, qui la portent deja.
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_liste_attente')",
            processor: InscrireListeAttenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/patinoire/liste-attente/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_liste_attente')",
            processor: AnnulerListeAttenteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['liste_attente:read']],
    // Fermeture **declaree** de la denormalisation (D41). Aucune propriete ne porte
    // `liste_attente:write` : rien n'est ecrivable depuis le corps. Les operations de creation portent
    // deja `input: false`, qui suffit techniquement — mais le garde-fou n12 ne sait pas le
    // lire, et compterait cette entite comme exposee indefiniment. Une fermeture qu'aucun
    // outil ne voit finit par etre "corrigee" une seconde fois par quelqu'un d'autre.
    denormalizationContext: ['groups' => ['liste_attente:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['parcPatins' => 'exact', 'statut' => 'exact', 'etablissement' => 'exact'])]
class ListeAttentePointure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['liste_attente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ParcPatins::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['liste_attente:read'])]
    private ?ParcPatins $parcPatins = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['liste_attente:read'])]
    private ?Beneficiaire $beneficiaire = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['liste_attente:read'])]
    private int $rang = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['liste_attente:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(length: 10, enumType: StatutListeAttentePointure::class, options: ['default' => 'en_attente'])]
    #[Groups(['liste_attente:read'])]
    private StatutListeAttentePointure $statut = StatutListeAttentePointure::EnAttente;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['liste_attente:read'])]
    private ?int $pointureVoisineProposee = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['liste_attente:read'])]
    private ?\DateTimeImmutable $dateExpirationProposition = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['liste_attente:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParcPatins(): ?ParcPatins
    {
        return $this->parcPatins;
    }

    public function setParcPatins(?ParcPatins $parcPatins): self
    {
        $this->parcPatins = $parcPatins;
        if ($parcPatins !== null) {
            $this->etablissement = $parcPatins->getEtablissement();
        }

        return $this;
    }

    public function getBeneficiaire(): ?Beneficiaire
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?Beneficiaire $beneficiaire): self
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getRang(): int
    {
        return $this->rang;
    }

    public function setRang(int $rang): self
    {
        $this->rang = $rang;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeImmutable $dateDemande): self
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }

    public function getStatut(): StatutListeAttentePointure
    {
        return $this->statut;
    }

    public function setStatut(StatutListeAttentePointure $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getPointureVoisineProposee(): ?int
    {
        return $this->pointureVoisineProposee;
    }

    public function setPointureVoisineProposee(?int $pointureVoisineProposee): self
    {
        $this->pointureVoisineProposee = $pointureVoisineProposee;

        return $this;
    }

    public function getDateExpirationProposition(): ?\DateTimeImmutable
    {
        return $this->dateExpirationProposition;
    }

    public function setDateExpirationProposition(?\DateTimeImmutable $dateExpirationProposition): self
    {
        $this->dateExpirationProposition = $dateExpirationProposition;

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
