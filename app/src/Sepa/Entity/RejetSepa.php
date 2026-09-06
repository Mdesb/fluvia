<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Sepa\State\DeclarerRejetSepaProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Retour SEPA (rejet) générique, rattaché à une `LigneRemiseSepa` (plan §2/§4/§6). Aucun parser
 * pain.002 réel n'existe (§9 du plan) : alimenté pour l'instant par une saisie/simulation manuelle
 * (`POST /sepa/rejets`) en attendant le retour bancaire réel (`RetourSepaInterface`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_rejet')]
#[ApiResource(
    shortName: 'RejetSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            security: "is_granted('PERM', 'sepa.declarer_rejet')",
            processor: DeclarerRejetSepaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['rejet_sepa:read']],
)]
class RejetSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rejet_sepa:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LigneRemiseSepa::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rejet_sepa:read'])]
    private ?LigneRemiseSepa $ligne = null;

    #[ORM\Column(length: 35)]
    #[Groups(['rejet_sepa:read'])]
    private string $endToEndId = '';

    #[ORM\Column(length: 35)]
    #[Groups(['rejet_sepa:read'])]
    private string $mndtId = '';

    /** Code retour SEPA (« R-code »), ex. AM04, MD01, MS03. */
    #[ORM\Column(length: 4)]
    #[Groups(['rejet_sepa:read'])]
    private string $codeMotif = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['rejet_sepa:read'])]
    private ?string $libelleMotif = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['rejet_sepa:read'])]
    private \DateTimeImmutable $dateRejet;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['rejet_sepa:read'])]
    private \DateTimeImmutable $dateSaisie;

    /**
     * CE QUE LE REJET A DÉCLENCHÉ, DIT PAR CELUI QUI VIENT DE LE FAIRE.
     *
     * ⚠ CE CHAMP EXISTE PARCE QUE DEUX ÉCRANS SE CONTREDISAIENT DESSUS. L'un annonçait qu'un
     * impayé s'ouvrait, l'autre affirmait en gras que non — et le troisième message, affiché
     * après le geste, contredisait le premier dans le même écran. Les trois étaient des phrases
     * figées, écrites de part et d'autre du commit qui a branché le moteur de recouvrement.
     * Chacune a été vraie. Aucune ne pouvait le rester : rien ne reliait une phrase au code.
     *
     * ⚠ ET LA RÉPONSE N'EST PAS BINAIRE, C'EST TOUT LE PROBLÈME. `ouvrirIncident()` renonce si
     * la remise n'a pas d'établissement ou le mandat pas de client, et renonce aussi si un
     * impayé non soldé existe déjà pour cette échéance. Une phrase absolue, dans un sens comme
     * dans l'autre, est fausse une fois sur trois.
     *
     * ⚠ NON PERSISTÉ, À DESSEIN. Il ne vaut que pour la réponse au POST qui vient de l'écrire.
     * Relu plus tard (GET), il rend `null` — « on ne sait pas », ce qui est exact : le lien
     * durable est porté par `IncidentImpaye::$rejetOrigine`, dans l'autre sens.
     *
     * @var 'impaye_ouvert'|'impaye_deja_ouvert'|'redevable_non_resolu'|null
     */
    #[Groups(['rejet_sepa:read'])]
    private ?string $suiteRecouvrement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateSaisie = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSuiteRecouvrement(): ?string
    {
        return $this->suiteRecouvrement;
    }

    public function setSuiteRecouvrement(?string $suiteRecouvrement): self
    {
        $this->suiteRecouvrement = $suiteRecouvrement;

        return $this;
    }

    public function getLigne(): ?LigneRemiseSepa
    {
        return $this->ligne;
    }

    public function setLigne(?LigneRemiseSepa $ligne): self
    {
        $this->ligne = $ligne;

        return $this;
    }

    public function getEndToEndId(): string
    {
        return $this->endToEndId;
    }

    public function setEndToEndId(string $endToEndId): self
    {
        $this->endToEndId = $endToEndId;

        return $this;
    }

    public function getMndtId(): string
    {
        return $this->mndtId;
    }

    public function setMndtId(string $mndtId): self
    {
        $this->mndtId = $mndtId;

        return $this;
    }

    public function getCodeMotif(): string
    {
        return $this->codeMotif;
    }

    public function setCodeMotif(string $codeMotif): self
    {
        $this->codeMotif = $codeMotif;

        return $this;
    }

    public function getLibelleMotif(): ?string
    {
        return $this->libelleMotif;
    }

    public function setLibelleMotif(?string $libelleMotif): self
    {
        $this->libelleMotif = $libelleMotif;

        return $this;
    }

    public function getDateRejet(): \DateTimeImmutable
    {
        return $this->dateRejet;
    }

    public function setDateRejet(\DateTimeImmutable $dateRejet): self
    {
        $this->dateRejet = $dateRejet;

        return $this;
    }

    public function getDateSaisie(): \DateTimeImmutable
    {
        return $this->dateSaisie;
    }
}
