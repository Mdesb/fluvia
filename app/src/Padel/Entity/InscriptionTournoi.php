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
use App\Padel\Enum\StatutPaiementInscriptionTournoi;
use App\Padel\State\InscrireTournoiProcessor;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Inscription d'une paire à un tournoi (US-PADEL-05). */
#[ORM\Entity]
#[ORM\Table(name: 'padel_inscription_tournoi')]
#[ApiResource(
    shortName: 'PadelInscriptionTournoi',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        // Corps : { "tournoi": iri|uuid, "joueur1": iri|uuid, "joueur2": iri|uuid, "paye"?: bool }
        // (flat plutôt que nested {tournoiId} : `Tournoi` n'expose pas de sous-ressource dédiée).
        new Post(
            uriTemplate: '/padel/inscriptions-tournoi',
            read: false,
            security: "is_granted('PERM', 'padel.tournoi_inscrire_soi') or is_granted('PERM', 'padel.tournoi_gerer')",
            processor: InscrireTournoiProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['inscription_tournoi:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['tournoi' => 'exact', 'poule' => 'exact', 'statutPaiement' => 'exact'])]
class InscriptionTournoi
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['inscription_tournoi:read', 'match_tournoi:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tournoi::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['inscription_tournoi:read'])]
    private ?Tournoi $tournoi = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['inscription_tournoi:read'])]
    private ?Beneficiaire $joueur1 = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['inscription_tournoi:read'])]
    private ?Beneficiaire $joueur2 = null;

    #[ORM\ManyToOne(targetEntity: Poule::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['inscription_tournoi:read'])]
    private ?Poule $poule = null;

    #[ORM\Column(length: 11, enumType: StatutPaiementInscriptionTournoi::class, options: ['default' => 'en_attente'])]
    #[Groups(['inscription_tournoi:read'])]
    private StatutPaiementInscriptionTournoi $statutPaiement = StatutPaiementInscriptionTournoi::EnAttente;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['inscription_tournoi:read'])]
    private ?Vente $venteRattachee = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTournoi(): ?Tournoi
    {
        return $this->tournoi;
    }

    public function setTournoi(?Tournoi $tournoi): self
    {
        $this->tournoi = $tournoi;

        return $this;
    }

    public function getJoueur1(): ?Beneficiaire
    {
        return $this->joueur1;
    }

    public function setJoueur1(?Beneficiaire $joueur1): self
    {
        $this->joueur1 = $joueur1;

        return $this;
    }

    public function getJoueur2(): ?Beneficiaire
    {
        return $this->joueur2;
    }

    public function setJoueur2(?Beneficiaire $joueur2): self
    {
        $this->joueur2 = $joueur2;

        return $this;
    }

    public function getPoule(): ?Poule
    {
        return $this->poule;
    }

    public function setPoule(?Poule $poule): self
    {
        $this->poule = $poule;

        return $this;
    }

    public function getStatutPaiement(): StatutPaiementInscriptionTournoi
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(StatutPaiementInscriptionTournoi $statutPaiement): self
    {
        $this->statutPaiement = $statutPaiement;

        return $this;
    }

    public function getVenteRattachee(): ?Vente
    {
        return $this->venteRattachee;
    }

    public function setVenteRattachee(?Vente $venteRattachee): self
    {
        $this->venteRattachee = $venteRattachee;

        return $this;
    }
}
