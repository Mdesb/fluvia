<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Padel\Enum\StatutNiveauJoueur;
use App\Padel\Security\JoueurLieVoter;
use App\Padel\State\DeclarerNiveauProcessor;
use App\Padel\State\ValiderNiveauProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Niveau de jeu d'un joueur, scopé par établissement (décision structurante n°7 du plan, écart
 * documenté vs. la spec §5 qui annonce un 1:1 strict). Le niveau `proposé` (auto-déclaré) n'est
 * éligible au filtrage des parties ouvertes/tournois qu'une fois `validé` par le club (US-PADEL-04).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_niveau_joueur')]
#[ORM\UniqueConstraint(name: 'uniq_niveau_joueur_etablissement', columns: ['joueur_id', 'etablissement_id'])]
#[ApiResource(
    shortName: 'PadelNiveauJoueur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire') or is_granted('PERM', 'padel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'padel.lire') or (is_granted('PERM', 'padel.lire_soi') and is_granted('" . JoueurLieVoter::ATTRIBUTE . "', object))"),
        new Post(
            uriTemplate: '/padel/niveaux/declarer',
            read: false,
            input: false,
            security: "is_granted('PERM', 'padel.niveau_declarer_soi')",
            processor: DeclarerNiveauProcessor::class,
        ),
        new Post(
            uriTemplate: '/padel/niveaux/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.niveau_valider')",
            processor: ValiderNiveauProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['niveau:read']],
    // Fermeture **declaree** de la denormalisation (D41). Aucune propriete ne porte
    // `niveau:write` : rien n'est ecrivable depuis le corps. Les creations portent deja
    // `input: false`, qui suffit techniquement — mais le garde-fou n12 ne sait pas le lire
    // et compterait l'entite comme exposee indefiniment. Une fermeture qu'aucun outil ne
    // voit finit par etre "corrigee" une seconde fois par quelqu'un d'autre.
    denormalizationContext: ['groups' => ['niveau:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['joueur' => 'exact', 'etablissement' => 'exact', 'statut' => 'exact'])]
class NiveauJoueur
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['niveau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['niveau:read'])]
    private ?Beneficiaire $joueur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['niveau:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['niveau:read'])]
    private int $niveau = 1;

    #[ORM\Column(length: 8, enumType: StatutNiveauJoueur::class, options: ['default' => 'propose'])]
    #[Groups(['niveau:read'])]
    private StatutNiveauJoueur $statut = StatutNiveauJoueur::Propose;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['niveau:read'])]
    private ?Utilisateur $valideParUtilisateur = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['niveau:read'])]
    private ?\DateTimeImmutable $dateValidation = null;

    /** @var Collection<int, HistoriqueNiveauJoueur> */
    #[ORM\OneToMany(targetEntity: HistoriqueNiveauJoueur::class, mappedBy: 'niveauJoueur', cascade: ['persist'])]
    private Collection $historique;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->historique = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getJoueur(): ?Beneficiaire
    {
        return $this->joueur;
    }

    public function setJoueur(?Beneficiaire $joueur): self
    {
        $this->joueur = $joueur;

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

    public function getNiveau(): int
    {
        return $this->niveau;
    }

    public function setNiveau(int $niveau): self
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getStatut(): StatutNiveauJoueur
    {
        return $this->statut;
    }

    public function setStatut(StatutNiveauJoueur $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getValideParUtilisateur(): ?Utilisateur
    {
        return $this->valideParUtilisateur;
    }

    public function setValideParUtilisateur(?Utilisateur $valideParUtilisateur): self
    {
        $this->valideParUtilisateur = $valideParUtilisateur;

        return $this;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function setDateValidation(?\DateTimeImmutable $dateValidation): self
    {
        $this->dateValidation = $dateValidation;

        return $this;
    }

    /** @return Collection<int, HistoriqueNiveauJoueur> */
    public function getHistorique(): Collection
    {
        return $this->historique;
    }

    public function addHistorique(HistoriqueNiveauJoueur $entree): self
    {
        if (!$this->historique->contains($entree)) {
            $this->historique->add($entree);
            $entree->setNiveauJoueur($this);
        }

        return $this;
    }

    /** Vrai si le niveau est validé et applicable au filtrage (CA-5). */
    public function estEligibleFiltrage(): bool
    {
        return $this->statut === StatutNiveauJoueur::Valide;
    }
}
