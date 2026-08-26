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
use App\Patinoire\Enum\EtatRetourPatins;
use App\Patinoire\Enum\StatutLocationPatins;
use App\Patinoire\State\RetournerPatinsProcessor;
use App\Patinoire\State\SortirPatinsProcessor;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Location de patins (« LocationMatériel » du cahier §4), RG-PAT-01/05, US-PATIN-02/03. `ligneVente`
 * est **optionnel** (⚠ divergence documentée vs plan §1 qui la voulait requise/unique) : même
 * pragmatisme que `App\Padel\Entity\LocationMateriel.venteRattachee` (nullable) — la location de
 * patins reste valide même si le rattachement complet au panier M2 (session de caisse, encaissement)
 * n'est pas encore bouclé au moment de la sortie ; la caution, elle, reste toujours obligatoire (CA-2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_location')]
#[ApiResource(
    shortName: 'PatinoireLocationPatins',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        // Corps : { "parcPatins": iri|uuid, "beneficiaire": iri|uuid, "ligneVente"?: iri|uuid, "caution"?: decimal }
        new Post(
            uriTemplate: '/patinoire/locations',
            read: false,
            // D41 : le processeur lit le corps brut et ignore l'objet deserialise ; sans
            // `input: false`, API Platform denormalise quand meme le corps dans l'entite, et
            // cette entite n'ayant aucun `denormalizationContext`, toute propriete munie d'un
            // mutateur devient ecrivable — l'etablissement compris. Meme forme que
            // `/padel/niveaux/declarer` et `/sport/abonnements/souscrire`, qui la portent deja.
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_location')",
            processor: SortirPatinsProcessor::class,
        ),
        // Corps : { "etatRetour": "bon"|"casse"|"non_rendu", "motif"?: string }
        new Post(
            uriTemplate: '/patinoire/locations/{id}/retour',
            read: true,
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_location')",
            processor: RetournerPatinsProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['location:read']],
    // Fermeture **declaree** de la denormalisation (D41). Aucune propriete ne porte
    // `location:write` : rien n'est ecrivable depuis le corps. Les operations de creation portent
    // deja `input: false`, qui suffit techniquement — mais le garde-fou n12 ne sait pas le
    // lire, et compterait cette entite comme exposee indefiniment. Une fermeture qu'aucun
    // outil ne voit finit par etre "corrigee" une seconde fois par quelqu'un d'autre.
    denormalizationContext: ['groups' => ['location:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'beneficiaire' => 'exact', 'parcPatins' => 'exact', 'etablissement' => 'exact'])]
class LocationPatins
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['location:read', 'caution_location:read', 'retenue:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ParcPatins::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['location:read'])]
    private ?ParcPatins $parcPatins = null;

    #[ORM\ManyToOne(targetEntity: LigneVente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['location:read'])]
    private ?LigneVente $ligneVente = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['location:read'])]
    private ?Beneficiaire $beneficiaire = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['location:read'])]
    private \DateTimeImmutable $dateSortie;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['location:read'])]
    private ?\DateTimeImmutable $dateRetour = null;

    #[ORM\Column(length: 10, enumType: EtatRetourPatins::class, nullable: true)]
    #[Groups(['location:read'])]
    private ?EtatRetourPatins $etatRetour = null;

    #[ORM\Column(length: 12, enumType: StatutLocationPatins::class, options: ['default' => 'en_cours'])]
    #[Groups(['location:read'])]
    private StatutLocationPatins $statut = StatutLocationPatins::EnCours;

    /** Dénormalisation de `parcPatins.etablissement` (cloisonnement, patron `Poss`/`CreneauBassin`). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['location:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateSortie = new \DateTimeImmutable();
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

    public function getLigneVente(): ?LigneVente
    {
        return $this->ligneVente;
    }

    public function setLigneVente(?LigneVente $ligneVente): self
    {
        $this->ligneVente = $ligneVente;

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

    public function getDateSortie(): \DateTimeImmutable
    {
        return $this->dateSortie;
    }

    public function setDateSortie(\DateTimeImmutable $dateSortie): self
    {
        $this->dateSortie = $dateSortie;

        return $this;
    }

    public function getDateRetour(): ?\DateTimeImmutable
    {
        return $this->dateRetour;
    }

    public function setDateRetour(?\DateTimeImmutable $dateRetour): self
    {
        $this->dateRetour = $dateRetour;

        return $this;
    }

    public function getEtatRetour(): ?EtatRetourPatins
    {
        return $this->etatRetour;
    }

    public function setEtatRetour(?EtatRetourPatins $etatRetour): self
    {
        $this->etatRetour = $etatRetour;

        return $this;
    }

    public function getStatut(): StatutLocationPatins
    {
        return $this->statut;
    }

    public function setStatut(StatutLocationPatins $statut): self
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
