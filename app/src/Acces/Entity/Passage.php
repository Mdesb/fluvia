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
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
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
            // T21 (plan-acces-terminal.md §7 Lot E) : la réponse est un `JsonResponse` construit à la
            // main (`TerminalPassageProcessor::reponse()`) — API Platform ne peut pas l'inférer. On
            // documente donc explicitement le corps de requête et le schéma `message`/`affichage`, sans
            // quoi l'OpenAPI généré expose l'opération sans réponse typée. Les listes d'enum reflètent
            // `ResultatPassage`, `CodeMotifRefus` et `CodeMessageAffichage` (tenues à jour à la main :
            // les arguments d'attribut n'autorisent pas d'appel de fonction).
            openapi: new OpenApiOperation(
                summary: 'Valider un passage en ligne depuis une borne (ITBOX).',
                description: 'Façade authentifiée-terminal de la validation en ligne : délègue au même moteur que '
                    . '`POST /acces/passages`, puis enrichit la réponse technique de `message` (catalogue fermé, '
                    . 'destiné à l\'écran de la borne) et `affichage` (données porteur, `null` sur refus signature/droit '
                    . 'invalide). `POST /acces/passages` reste strictement inchangé.',
                requestBody: new OpenApiRequestBody(
                    description: 'Événement de passage lu par la borne.',
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['equipementId', 'cleIdempotence'],
                                'properties' => [
                                    'equipementId' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Équipement scanné ; doit appartenir à la portée du terminal authentifié (sinon 403).'],
                                    'cleIdempotence' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Clé stable engendrée par la borne au scan et rejouée à l\'identique en cas de retransmission réseau (anti-doublon).'],
                                    'identifiantSupport' => ['type' => 'string', 'nullable' => true, 'description' => 'Identifiant lu sur le support (QR, badge…).'],
                                    'sens' => ['type' => 'string', 'enum' => ['entree', 'sortie'], 'nullable' => true, 'description' => 'App\\Acces\\Enum\\SensPassage.'],
                                    'horodatageBorne' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'Horloge de la borne ; un écart > 5 min est signalé (`ecartHorlogeSuspect`) sans jamais bloquer.'],
                                ],
                            ],
                        ],
                    ]),
                    required: true,
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Décision de passage, enrichie pour l\'affichage borne (rendue aussi au rejeu d\'une clé d\'idempotence déjà vue).',
                        content: new \ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['resultat', 'horodatageServeur', 'message'],
                                    'properties' => [
                                        'resultat' => ['type' => 'string', 'enum' => ['valide', 'refuse', 'compte'], 'description' => 'App\\Acces\\Enum\\ResultatPassage.'],
                                        'codeMotif' => ['type' => 'string', 'nullable' => true, 'description' => 'Motif du refus, `null` si `resultat=valide`. App\\Acces\\Enum\\CodeMotifRefus.', 'enum' => [
                                            'hors_marge', 'anti_passback', 'credit_epuise', 'support_bloque', 'seuil_fmi',
                                            'droit_invalide', 'sens_interdit', 'non_nominatif', 'ouverture_manuelle',
                                            'federation_inactive', 'signature_invalide', 'hors_portee', 'cle_idempotence_invalide',
                                            'zone_non_autorisee', 'credit_epuise_hors_ligne_litige', 'hors_horaires_ouverture', 'deja_consomme',
                                        ]],
                                        'horodatageServeur' => ['type' => 'string', 'format' => 'date-time'],
                                        'message' => [
                                            'type' => 'object',
                                            'description' => 'Message à afficher sur la borne (catalogue fermé). App\\Acces\\Enum\\CodeMessageAffichage.',
                                            'required' => ['codeMessage', 'libelle'],
                                            'properties' => [
                                                'codeMessage' => ['type' => 'string', 'enum' => [
                                                    'BONNE_SEANCE', 'PASSAGE_COMPTE', 'HORS_MARGE', 'DEJA_PASSE', 'CARTE_EPUISEE',
                                                    'SUPPORT_BLOQUE', 'JAUGE_ATTEINTE', 'DROIT_INVALIDE', 'SENS_INTERDIT', 'FEDERATION_INACTIVE', 'CODE_INVALIDE',
                                                ]],
                                                'libelle' => ['type' => 'string', 'description' => 'Libellé prêt à afficher (ex. « Bonne séance ! »).'],
                                            ],
                                        ],
                                        'affichage' => [
                                            'type' => 'object',
                                            'nullable' => true,
                                            'description' => 'Données porteur pour l\'écran de la borne ; `null` sur un refus « signature invalide » ou « droit invalide » (anti-fuite, §4.2 spec).',
                                            'properties' => [
                                                'nomPorteur' => ['type' => 'string', 'nullable' => true, 'description' => 'Systématiquement `null` en v1 (le modèle ne porte pas encore le nom, R-3).'],
                                                'numeroBillet' => ['type' => 'string', 'nullable' => true],
                                                'typeSupport' => ['type' => 'string', 'nullable' => true, 'description' => 'Type du support d\'accès (ex. QR).'],
                                                'compostagesRestants' => ['type' => 'integer', 'nullable' => true, 'description' => 'Renseigné pour une carte à quota, `null` sinon.'],
                                                'validiteAbonnement' => [
                                                    'type' => 'object',
                                                    'nullable' => true,
                                                    'description' => 'Renseigné pour un droit de type abonnement, `null` sinon.',
                                                    'properties' => [
                                                        'valide' => ['type' => 'boolean'],
                                                        'debut' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                                        'fin' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '401' => new OpenApiResponse(description: 'Jeton terminal invalide, inconnu, expiré ou révoqué (message générique, aucune distinction — anti-énumération).'),
                    '403' => new OpenApiResponse(description: 'L\'équipement visé n\'appartient pas à la portée du terminal authentifié (refus avant tout appel moteur).'),
                    '422' => new OpenApiResponse(description: 'Requête invalide : `equipementId` ou `cleIdempotence` manquant ou mal formé (UUID attendu).'),
                ],
            ),
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
