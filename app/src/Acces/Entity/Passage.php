<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Platform\Filter\UuidReferenceFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\State\PassageExportProvider;
use App\Acces\State\PassageIngestionProcessor;
use App\Acces\State\PassageManuelProcessor;
use App\Acces\State\PassageNonNominatifProcessor;
use App\Acces\State\TerminalPassageProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Source unique des passages (RG-ACC-06, écran A-05). Append-only (garde `PassageInalterableListener`,
 * CA-14) : aucune modification/suppression via l'API. Alimente M6 (compta) et M7 (reporting) sans
 * valoriser ni analyser. `cleIdempotence` évite le doublon au rejeu hors-ligne (CA-9/12).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_passage')]
#[ORM\UniqueConstraint(name: 'uniq_passage_cle_idempotence', columns: ['cle_idempotence'])]
#[ApiResource(
    shortName: 'Passage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new GetCollection(
            uriTemplate: '/acces/passages/export',
            security: "is_granted('PERM', 'acces.lire')",
            provider: PassageExportProvider::class,
        ),
        new Post(
            uriTemplate: '/acces/passages',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ingestion')",
            processor: PassageIngestionProcessor::class,
        ),
        // Nouvelle opération terminal (US-TERM-02, plan-acces-terminal.md §2.2) : facade authentifiée-
        // terminal (`PERM_TERMINAL`, firewall dédié `terminal`), délègue au même moteur
        // `ValidationPassageHandler` via `TerminalPassageProcessor`. `POST /acces/passages` ci-dessus
        // reste strictement inchangé (opération/security/processor/réponse).
        new Post(
            uriTemplate: '/terminal/passages',
            read: false,
            input: false,
            security: "is_granted('PERM_TERMINAL', 'acces.ingestion')",
            processor: TerminalPassageProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/passages/manuel',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ouvrir_manuel')",
            processor: PassageManuelProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/passages/non-nominatif',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.superviser') or is_granted('PERM', 'acces.controler')",
            processor: PassageNonNominatifProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['passage:read']],
)]
// ⚠ `espace`, `controleur` et `equipement` ONT QUITTE LE `SearchFilter` : ils rendaient TOUJOURS
// une liste vide. Mesure sur une collection d'une ligne, chacun des trois filtres a rendu zero.
//
// Leur identifiant est un `Uuid`, stocke en `BINARY(16)` : le `SearchFilter` compare la colonne a
// une chaine de 36 caracteres, ne trouve rien, et ne leve rien. Voir `UuidReferenceFilter`, qui
// explique la mesure et l'hypothese ecartee.
//
// `resultat` reste au `SearchFilter` -- c'est une enumeration stockee en chaine, il la gere bien.
// `support.identifiant` aussi : il traverse une association pour comparer un CHAMP, pas un
// identifiant, et c'est precisement ce que le filtre standard sait faire.
#[ApiFilter(SearchFilter::class, properties: [
    'resultat' => 'exact', 'support.identifiant' => 'exact',
])]
#[ApiFilter(UuidReferenceFilter::class, properties: ['espace', 'controleur', 'equipement'])]
#[ApiFilter(DateFilter::class, properties: ['horodatage'])]
/**
 * ⚠ SANS CE FILTRE, `order[horodatage]=desc` ETAIT IGNORE EN SILENCE.
 *
 * La collection sortait dans l'ordre d'insertion — du plus ANCIEN au plus recent — et le plafond de
 * trente lignes par page donnait alors le contraire exact de ce qu'on demande partout : les trente
 * PREMIERS passages de l'histoire du site, jamais les trente derniers.
 *
 * Constate a l'usage : une carte « Derniers passages » qui montrait les premiers, et un bandeau de
 * caisse qui reannoncait comme neufs des scans deja vus, parce qu'il prenait la premiere ligne pour
 * repere. Un tri cote ecran n'y pouvait rien : il reordonne les trente lignes recues, pas le CHOIX
 * de ces trente-la.
 */
#[ApiFilter(OrderFilter::class, properties: ['horodatage'], arguments: ['orderParameterName' => 'order'])]
class Passage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['passage:read'])]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['passage:read'])]
    private \DateTimeImmutable $horodatage;

    /**
     * Le lieu franchi — ABSENT quand il n'y en a pas eu (D86, 30/08/2026).
     *
     * ⚠ NULLABLE DEPUIS QUE LE CONTRÔLE MANUEL EXISTE, et l'absence n'est pas une donnée manquante.
     * Un agent qui scanne un billet sur un site sans matériel ne fait franchir aucune porte : il n'a
     * pas un lieu inconnu, il n'a PAS de lieu. Les deux se ressemblent en base et ne se disent pas
     * pareil à l'écran — c'est pourquoi les écrans écrivent « contrôlé à la main » et non « — », qui
     * se lirait comme une panne.
     *
     * Les deux autres issues envisagées ont été écartées : ne rien écrire aurait creusé un trou dans
     * l'historique exactement là où il n'y a pas de matériel, donc là où on a le plus besoin de
     * savoir qui est entré ; et inscrire une zone quelconque aurait écrit qu'un porteur a franchi
     * une porte qu'il n'a pas franchie — un historique qui invente est pire qu'un historique
     * incomplet.
     *
     * Coût mesuré avant de trancher : une seule lecture de `$passage->getEspace()` existe dans
     * `app/src` (`SynchroPassageHandler`), et elle gardait déjà le cas nul — le getter rend
     * `?EspaceAcces` depuis toujours, seule la colonne l'interdisait.
     */
    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?EspaceAcces $espace = null;

    #[ORM\ManyToOne(targetEntity: Controleur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Controleur $controleur = null;

    #[ORM\ManyToOne(targetEntity: Equipement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Equipement $equipement = null;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Support $support = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?DroitAcces $droit = null;

    #[ORM\Column(length: 12, enumType: SensPassage::class)]
    #[Groups(['passage:read'])]
    private SensPassage $sens = SensPassage::Entree;

    #[ORM\Column(length: 12, enumType: ResultatPassage::class)]
    #[Groups(['passage:read'])]
    private ResultatPassage $resultat = ResultatPassage::Refuse;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['passage:read'])]
    private ?string $motif = null;

    #[ORM\Column(length: 32, enumType: CodeMotifRefus::class, nullable: true)]
    #[Groups(['passage:read'])]
    private ?CodeMotifRefus $codeMotif = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['passage:read'])]
    private bool $origineHorsLigne = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['passage:read'])]
    private bool $enConflit = false;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['passage:read'])]
    private Uuid $cleIdempotence;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['passage:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->horodatage = new \DateTimeImmutable();
        $this->cleIdempotence = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getEspace(): ?EspaceAcces
    {
        return $this->espace;
    }

    public function setEspace(?EspaceAcces $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getControleur(): ?Controleur
    {
        return $this->controleur;
    }

    public function setControleur(?Controleur $controleur): self
    {
        $this->controleur = $controleur;

        return $this;
    }

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

        return $this;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

        return $this;
    }

    public function getDroit(): ?DroitAcces
    {
        return $this->droit;
    }

    public function setDroit(?DroitAcces $droit): self
    {
        $this->droit = $droit;

        return $this;
    }

    public function getSens(): SensPassage
    {
        return $this->sens;
    }

    public function setSens(SensPassage $sens): self
    {
        $this->sens = $sens;

        return $this;
    }

    public function getResultat(): ResultatPassage
    {
        return $this->resultat;
    }

    public function setResultat(ResultatPassage $resultat): self
    {
        $this->resultat = $resultat;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getCodeMotif(): ?CodeMotifRefus
    {
        return $this->codeMotif;
    }

    public function setCodeMotif(?CodeMotifRefus $codeMotif): self
    {
        $this->codeMotif = $codeMotif;

        return $this;
    }

    public function isOrigineHorsLigne(): bool
    {
        return $this->origineHorsLigne;
    }

    public function setOrigineHorsLigne(bool $origineHorsLigne): self
    {
        $this->origineHorsLigne = $origineHorsLigne;

        return $this;
    }

    public function isEnConflit(): bool
    {
        return $this->enConflit;
    }

    public function setEnConflit(bool $enConflit): self
    {
        $this->enConflit = $enConflit;

        return $this;
    }

    public function getCleIdempotence(): Uuid
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(Uuid $cleIdempotence): self
    {
        $this->cleIdempotence = $cleIdempotence;

        return $this;
    }

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): self
    {
        $this->agent = $agent;

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
