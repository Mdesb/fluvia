<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Compta\Entity\TauxTva;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Service\Montant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Paramétrage de facturation par exploitant (US-FACT-08, `plan-facturation.md` §1.6) : mentions
 * légales de l'**émetteur** (RG-FACT-02), conditions de règlement par défaut, activation du canal
 * Chorus Pro, compte de produit de repli.
 *
 * ⚠ EXPERT #4 (`spec-facturation.md` §9) — le taux de pénalité de retard et l'indemnité forfaitaire de
 * recouvrement (40 € en droit français B2B) sont **paramétrables**, pas codés en dur, et restent à
 * confirmer par un fiscaliste avant figement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_parametre')]
#[ORM\UniqueConstraint(name: 'uniq_facturation_parametre_profil', columns: ['profil_exploitant_id'])]
#[ApiResource(
    shortName: 'ParametreFacturation',
    operations: [
        new GetCollection(
            uriTemplate: '/parametres-facturation',
            security: "is_granted('PERM', 'facturation.lire')",
        ),
        new Get(
            uriTemplate: '/parametres-facturation/{id}',
            security: "is_granted('PERM', 'facturation.lire')",
        ),
        new Post(
            uriTemplate: '/parametres-facturation',
            security: "is_granted('PERM', 'facturation.gerer')",
            denormalizationContext: ['groups' => ['parametre_facturation:write']],
        ),
        new Patch(
            uriTemplate: '/parametres-facturation/{id}',
            security: "is_granted('PERM', 'facturation.gerer')",
            denormalizationContext: ['groups' => ['parametre_facturation:write']],
        ),
    ],
    normalizationContext: ['groups' => ['parametre_facturation:read']],
)]
class ParametreFacturationEtablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['parametre_facturation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    /** @var array<string, mixed> {denomination, adresse, siret, tvaIntra} */
    #[ORM\Column]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private array $mentionsLegalesEmetteur = [];

    #[ORM\Column(type: 'text')]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private string $conditionsReglementDefaut = 'Paiement à 30 jours date de facture.';

    #[ORM\Column(options: ['default' => 30])]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private int $delaiPaiementDefautJours = 30;

    /** ⚠ EXPERT #4 — taux annuel des pénalités de retard, à confirmer par un fiscaliste. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?string $tauxPenaliteRetard = null;

    /** ⚠ EXPERT #4 — 40 € par défaut en droit français B2B, paramétrable. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '40.00'])]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private string $indemniteForfaitaireRecouvrement = '40.00';

    /**
     * Le taux de TVA applicable aux abonnements de la plateforme.
     *
     * **Volontairement `null` par défaut, et ce n'est pas un oubli.** Un exploitant français porte
     * couramment quatre taux actifs — 20 %, 10 %, 5,5 % et hors champ — constaté par `claude-D` sur les
     * données de démonstration, qui reflètent ici la réalité et non un artefact de test. Poser un taux
     * par défaut reviendrait donc à en choisir un à la place de l'exploitant, une fois sur quatre au
     * hasard. **Facturer au mauvais taux se corrige par un avoir et se voit sur une déclaration.**
     *
     * **Ce que ce champ débloque.** Tant que le taux devait être choisi à chaque émission, la
     * facturation mensuelle ne pouvait pas être automatisée : une tâche périodique qui exige un
     * arbitrage humain n'en est pas une. Renseigné une fois, il rend la tâche exécutable ; laissé vide,
     * l'émission échoue explicitement en demandant de le préciser — jamais en devinant.
     *
     * `null` signifie donc « non décidé », pas « exonéré ». L'exonération, elle, se dit par un taux à
     * zéro et se justifie par `mentionTvaSpecifique`.
     */
    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?TauxTva $tauxTvaAbonnement = null;

    /** Franchise en base (art. 293 B du CGI) — optionnel, ⚠ hypothèse §4.2 de la spec. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?string $mentionTvaSpecifique = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private bool $chorusProActif = false;

    /** Repli si `LigneFacture::$categorieComptable` absent ou non mappé (§0.2 du plan). */
    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?CompteComptable $compteProduitDefaut = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getMentionsLegalesEmetteur(): array
    {
        return $this->mentionsLegalesEmetteur;
    }

    /** @param array<string, mixed> $mentionsLegalesEmetteur */
    public function setMentionsLegalesEmetteur(array $mentionsLegalesEmetteur): self
    {
        $this->mentionsLegalesEmetteur = $mentionsLegalesEmetteur;

        return $this;
    }

    public function getConditionsReglementDefaut(): string
    {
        return $this->conditionsReglementDefaut;
    }

    public function setConditionsReglementDefaut(string $conditionsReglementDefaut): self
    {
        $this->conditionsReglementDefaut = $conditionsReglementDefaut;

        return $this;
    }

    public function getDelaiPaiementDefautJours(): int
    {
        return $this->delaiPaiementDefautJours;
    }

    public function setDelaiPaiementDefautJours(int $delaiPaiementDefautJours): self
    {
        $this->delaiPaiementDefautJours = $delaiPaiementDefautJours;

        return $this;
    }

    public function getTauxPenaliteRetard(): ?string
    {
        return $this->tauxPenaliteRetard;
    }

    public function setTauxPenaliteRetard(?string $tauxPenaliteRetard): self
    {
        $this->tauxPenaliteRetard = $tauxPenaliteRetard;

        return $this;
    }

    public function getIndemniteForfaitaireRecouvrement(): string
    {
        return $this->indemniteForfaitaireRecouvrement;
    }

    public function setIndemniteForfaitaireRecouvrement(string $indemniteForfaitaireRecouvrement): self
    {
        $this->indemniteForfaitaireRecouvrement = Montant::normaliser($indemniteForfaitaireRecouvrement, '40.00');

        return $this;
    }

    public function getMentionTvaSpecifique(): ?string
    {
        return $this->mentionTvaSpecifique;
    }

    public function setMentionTvaSpecifique(?string $mentionTvaSpecifique): self
    {
        $this->mentionTvaSpecifique = $mentionTvaSpecifique;

        return $this;
    }

    public function isChorusProActif(): bool
    {
        return $this->chorusProActif;
    }

    public function setChorusProActif(bool $chorusProActif): self
    {
        $this->chorusProActif = $chorusProActif;

        return $this;
    }

    public function getCompteProduitDefaut(): ?CompteComptable
    {
        return $this->compteProduitDefaut;
    }

    public function setCompteProduitDefaut(?CompteComptable $compteProduitDefaut): self
    {
        $this->compteProduitDefaut = $compteProduitDefaut;

        return $this;
    }

    /** Texte des conditions de règlement effectivement figé sur une facture émise (RG-FACT-02). */
    public function conditionsCompletes(): string
    {
        $texte = $this->conditionsReglementDefaut;
        if ($this->tauxPenaliteRetard !== null) {
            $texte .= sprintf(' Pénalités de retard : %s %% par an.', $this->tauxPenaliteRetard);
        }
        $texte .= sprintf(' Indemnité forfaitaire de recouvrement : %s EUR.', $this->indemniteForfaitaireRecouvrement);
        if ($this->mentionTvaSpecifique !== null && $this->mentionTvaSpecifique !== '') {
            $texte .= ' ' . $this->mentionTvaSpecifique;
        }

        return $texte;
    }

    public function getTauxTvaAbonnement(): ?TauxTva
    {
        return $this->tauxTvaAbonnement;
    }

    public function setTauxTvaAbonnement(?TauxTva $tauxTvaAbonnement): self
    {
        $this->tauxTvaAbonnement = $tauxTvaAbonnement;

        return $this;
    }
}
