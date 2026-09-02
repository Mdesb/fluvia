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
use App\Facturation\State\BillingSettingsStampProcessor;
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
            processor: BillingSettingsStampProcessor::class,
            uriTemplate: '/parametres-facturation',
            security: "is_granted('PERM', 'facturation.gerer')",
            denormalizationContext: ['groups' => ['parametre_facturation:write']],
        ),
        new Patch(
            processor: BillingSettingsStampProcessor::class,
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

    /**
     * ⚠ SORTI DU GROUPE D'ECRITURE LE 31/08 : le serveur le pose, le client ne le choisit pas.
     *
     * Il portait `parametre_facturation:write` et `nullable: false`, sans que rien ne le pose cote
     * serveur. Deux consequences, mesurees sur une base jetable :
     *
     *   — un POST sans ce champ rendait un 500 « Column profil_exploitant_id cannot be null », pas
     *     un refus propre. La ressource etait donc inutilisable, ce qui explique qu'aucun ecran ne
     *     l'ait jamais appelee ;
     *   — un POST AVEC un profil etranger aurait ecrit le parametrage de facturation du voisin —
     *     ses conditions de reglement, son taux de penalites. Le symptome aurait ete un reglage qui
     *     change tout seul chez quelqu'un d'autre, ce qu'on n'impute jamais a une requete etrangere.
     *
     * `BillingSettingsStampProcessor` le resout depuis l'etablissement actif.
     */
    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['parametre_facturation:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    /**
     * ⚠ **OBSOLETE DEPUIS LE 01/09 — CETTE COLONNE NE FAIT PLUS FOI.**
     *
     * L'identite legale du vendeur vit desormais sur `ProfilExploitant` : `raisonSociale`,
     * `tvaIntracommunautaire`, `adresse`. Champs structures, parce qu'EN 16931 exige des termes
     * distincts (BT-27, BT-31, BT-35/37/38/40) qu'une plateforme controle un par un -- ce qu'un
     * tableau JSON libre ne permet pas.
     *
     * Les valeurs ont ete recopiees vers le profil (`Version20260901000000`), et
     * `FactureRenduProvider` lit le profil. Cette colonne est conservee **le temps d'un
     * deploiement** : retirer une colonne se fait en deux temps -- le code cesse de l'ecrire, on
     * deploie, puis on la supprime.
     *
     * ⚠ **NE PAS LA RENSEIGNER.** Deux sources pour le meme fait sur un document opposable, c'est
     * exactement le defaut qu'on vient de refermer : pendant quelques heures, le rendu affichait
     * une identite et le controle de completude en reclamait une autre.
     *
     * @var array<string, mixed> {denomination, adresse, siret, tvaIntra}
     *
     * @deprecated Lire `ProfilExploitant` — voir Version20260901000000.
     */
    #[ORM\Column]
    #[Groups(['parametre_facturation:read'])]
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
     * BT-22 / PMT — la mention relative aux frais de recouvrement, imposee par le profil francais.
     *
     * L'indemnite forfaitaire pour frais de recouvrement. `indemniteForfaitaireRecouvrement` en porte le MONTANT ; ce champ en porte la PHRASE.
     *
     * ⚠ LE TEXTE N'EST PAS COMPOSE PAR LE PRODUIT, ET C'EST UN CHOIX. Une clause de ce type ENGAGE :
     * elle se relit, elle se negocie, et elle differe d'une regie municipale a une salle privee.
     * Composer la phrase depuis un taux configure mettrait des mots dans la bouche de l'exploitant
     * sur un document opposable.
     *
     * Vide = la mention manque, et le rapport de validation le dit. C'est preferable a une phrase
     * plausible que personne n'a approuvee.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?string $mentionRecouvrement = null;

    /**
     * BT-22 / PMD — la mention relative aux penalites de retard, imposee par le profil francais.
     *
     * Les penalites applicables en cas de retard. `tauxPenaliteRetard` en porte le TAUX ; ce champ en porte la PHRASE.
     *
     * ⚠ LE TEXTE N'EST PAS COMPOSE PAR LE PRODUIT, ET C'EST UN CHOIX. Une clause de ce type ENGAGE :
     * elle se relit, elle se negocie, et elle differe d'une regie municipale a une salle privee.
     * Composer la phrase depuis un taux configure mettrait des mots dans la bouche de l'exploitant
     * sur un document opposable.
     *
     * Vide = la mention manque, et le rapport de validation le dit. C'est preferable a une phrase
     * plausible que personne n'a approuvee.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?string $mentionPenalitesRetard = null;

    /**
     * BT-22 / AAB — la mention relative aux escompte, imposee par le profil francais.
     *
     * L'escompte pour paiement anticipe, OU son absence. Aucun autre champ ne le porte : ne rien ecrire ici laisse la mention manquante, ce que le validateur signale.
     *
     * ⚠ LE TEXTE N'EST PAS COMPOSE PAR LE PRODUIT, ET C'EST UN CHOIX. Une clause de ce type ENGAGE :
     * elle se relit, elle se negocie, et elle differe d'une regie municipale a une salle privee.
     * Composer la phrase depuis un taux configure mettrait des mots dans la bouche de l'exploitant
     * sur un document opposable.
     *
     * Vide = la mention manque, et le rapport de validation le dit. C'est preferable a une phrase
     * plausible que personne n'a approuvee.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parametre_facturation:read', 'parametre_facturation:write'])]
    private ?string $mentionEscompte = null;

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

    public function getMentionRecouvrement(): ?string
    {
        return $this->mentionRecouvrement;
    }

    public function setMentionRecouvrement(?string $valeur): self
    {
        $this->mentionRecouvrement = $valeur;

        return $this;
    }

    public function getMentionPenalitesRetard(): ?string
    {
        return $this->mentionPenalitesRetard;
    }

    public function setMentionPenalitesRetard(?string $valeur): self
    {
        $this->mentionPenalitesRetard = $valeur;

        return $this;
    }

    public function getMentionEscompte(): ?string
    {
        return $this->mentionEscompte;
    }

    public function setMentionEscompte(?string $valeur): self
    {
        $this->mentionEscompte = $valeur;

        return $this;
    }

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
