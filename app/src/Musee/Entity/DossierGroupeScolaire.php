<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use App\Musee\Enum\StatutPaiementDossier;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Vente\Entity\Vente;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dossier groupe/scolaire (US-MUSEE-06, RG-MUS-03) : réservation anticipée avec **paiement différé**
 * (bon de commande / mandat), indépendant de la confirmation du créneau/de la salle.
 *
 * ⚠ DÉPRÉCIÉ — ABSORBÉ PAR `App\Group` (absorption musée, Phase B, arbitrage Maxime « absorption
 * complète », 08/09). Les groupes scolaires se gèrent désormais dans le module transverse Groupes
 * (`ParticipantGroup` + `GroupBooking`, grain visiteur, gratuités transverses). Cette entité n'est
 * plus exposée en API ni écrite par un écran ; la table est **conservée le temps de valider** (les
 * dossiers historiques sont miroités par `MigrateGroupDossiersCommand`). À retirer en B4.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_dossier_groupe_scolaire')]
class DossierGroupeScolaire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['dossier:read', 'gratuite:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['dossier:read', 'dossier:write'])]
    private string $etablissementScolaire = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    #[Groups(['dossier:read', 'dossier:write'])]
    private int $effectif = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['dossier:read', 'dossier:write'])]
    private int $accompagnateurs = 0;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['dossier:read', 'dossier:write'])]
    private ?Creneau $creneauEntree = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['dossier:read'])]
    private ?\DateTimeImmutable $dateOption = null;

    #[ORM\Column(length: 18, enumType: StatutPaiementDossier::class, options: ['default' => 'en_option'])]
    #[Groups(['dossier:read', 'dossier:write'])]
    private StatutPaiementDossier $statutPaiement = StatutPaiementDossier::EnOption;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['dossier:read'])]
    private ?Vente $venteRattachee = null;

    /** @var Collection<int, Guide> */
    #[ORM\ManyToMany(targetEntity: Guide::class)]
    #[ORM\JoinTable(name: 'musee_dossier_guide')]
    #[Groups(['dossier:read', 'dossier:write'])]
    private Collection $guidesAffectes;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['dossier:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->guidesAffectes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEtablissementScolaire(): string
    {
        return $this->etablissementScolaire;
    }

    public function setEtablissementScolaire(string $etablissementScolaire): self
    {
        $this->etablissementScolaire = $etablissementScolaire;

        return $this;
    }

    public function getEffectif(): int
    {
        return $this->effectif;
    }

    public function setEffectif(int $effectif): self
    {
        $this->effectif = $effectif;

        return $this;
    }

    public function getAccompagnateurs(): int
    {
        return $this->accompagnateurs;
    }

    public function setAccompagnateurs(int $accompagnateurs): self
    {
        $this->accompagnateurs = $accompagnateurs;

        return $this;
    }

    public function getCreneauEntree(): ?Creneau
    {
        return $this->creneauEntree;
    }

    public function setCreneauEntree(?Creneau $creneauEntree): self
    {
        $this->creneauEntree = $creneauEntree;

        return $this;
    }

    public function getDateOption(): ?\DateTimeImmutable
    {
        return $this->dateOption;
    }

    public function setDateOption(?\DateTimeImmutable $dateOption): self
    {
        $this->dateOption = $dateOption;

        return $this;
    }

    public function getStatutPaiement(): StatutPaiementDossier
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(StatutPaiementDossier $statutPaiement): self
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

    /** @return Collection<int, Guide> */
    public function getGuidesAffectes(): Collection
    {
        return $this->guidesAffectes;
    }

    /**
     * Remplace la liste des guides affectés.
     *
     * ⚠ MÊME DÉFAUT QUE `ProfilExploitant::$etablissementsRattaches`, et même remède : l'accesseur
     * cherchait `addGuidesAffecte` (singulier anglais de la chaîne entière) quand l'entité offre
     * `addGuideAffecte`, sans remover. Le champ était dans `dossier:write` et n'écrivait rien.
     *
     * @param iterable<Guide> $guides
     */
    public function setGuidesAffectes(iterable $guides): self
    {
        $voulus = [];
        foreach ($guides as $guide) {
            $voulus[(string) $guide->getId()] = $guide;
        }

        foreach ($this->guidesAffectes->toArray() as $present) {
            if (!isset($voulus[(string) $present->getId()])) {
                $this->guidesAffectes->removeElement($present);
            }
        }

        foreach ($voulus as $guide) {
            if (!$this->guidesAffectes->contains($guide)) {
                $this->guidesAffectes->add($guide);
            }
        }

        return $this;
    }

    public function addGuideAffecte(Guide $guide): self
    {
        if (!$this->guidesAffectes->contains($guide)) {
            $this->guidesAffectes->add($guide);
        }

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

    /** Total de personnes du dossier consommant la jauge d'entrée (RG-MUS-03). */
    public function totalPersonnes(): int
    {
        return $this->effectif + $this->accompagnateurs;
    }
}
