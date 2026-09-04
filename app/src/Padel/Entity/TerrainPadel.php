<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Padel\Enum\CourtSport;
use App\Padel\Enum\CourtSurface;
use App\Padel\Enum\TypeTerrain;
use App\Padel\State\CreerTerrainProcessor;
use App\Padel\State\ForcerEclairageManuelProcessor;
use App\Padel\State\ReserverTerrainProcessor;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Terrain de padel : overlay 1:1 sur une `Ressource` du socle Réservation (`codeType='terrain_padel'`,
 * décision structurante n°1 du plan). Ne porte que les champs absents du modèle générique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_terrain')]
#[ApiResource(
    shortName: 'PadelTerrain',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(
            uriTemplate: '/padel/terrains',
            security: "is_granted('PERM', 'padel.gerer_terrain')",
            processor: CreerTerrainProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'padel.gerer_terrain')"),
        // Réserve un créneau sur ce terrain (US-PADEL-01, CA-1/CA-2/CA-10) : {id} = identifiant du
        // terrain, output = l'overlay ReservationPadel créé (avec la Reservation socle sous-jacente).
        new Post(
            uriTemplate: '/padel/terrains/{id}/reservations',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.reserver') or is_granted('PERM', 'padel.reserver_soi')",
            processor: ReserverTerrainProcessor::class,
            output: ReservationPadel::class,
            normalizationContext: ['groups' => ['reservation_padel:read']],
        ),
        // Repli manuel de l'éclairage (RG-PADEL-05, CA-11) : motif requis, tracé.
        new Post(
            uriTemplate: '/padel/terrains/{id}/eclairage/repli-manuel',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.acces_forcer')",
            processor: ForcerEclairageManuelProcessor::class,
            output: EvenementEclairage::class,
            normalizationContext: ['groups' => ['evenement_eclairage:read']],
        ),
    ],
    normalizationContext: ['groups' => ['terrain:read']],
    denormalizationContext: ['groups' => ['terrain:write']],
)]
class TerrainPadel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['terrain:read', 'reservation_padel:read', 'match_tournoi:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['terrain:read'])]
    private ?Ressource $ressource = null;

    #[ORM\Column(length: 10, enumType: TypeTerrain::class)]
    #[Groups(['terrain:read', 'terrain:write'])]
    private TypeTerrain $type = TypeTerrain::Indoor;

    /**
     * LE SPORT PRATIQUÉ ICI (R13) — la déclinaison, plutôt qu'un module jumeau.
     *
     * ⚠ `options: ['default' => …]` EST OBLIGATOIRE, ET PAS DÉCORATIF. Le garde-fou n°10 (D32)
     * refuse un `DEFAULT` posé en migration qui ne serait pas déclaré au mapping : sans lui, un
     * `migrations:diff` ultérieur croirait la colonne dérivée et proposerait de la « corriger ».
     */
    #[ORM\Column(length: 20, enumType: CourtSport::class, options: ['default' => 'padel'])]
    #[Groups(['terrain:read', 'terrain:write'])]
    private CourtSport $sport = CourtSport::Padel;

    /**
     * LA SURFACE DE JEU (R14) — distincte de `type`, qui dit couvert ou découvert.
     *
     * ⚠ NULLABLE, ET C'EST LE FOND DE LA CHOSE. Personne n'a jamais relevé la surface des terrains
     * existants. `null` dit « on ne sait pas » ; poser « résine » par défaut inventerait une donnée
     * métier qu'aucun relevé n'a constatée (D66-ter).
     */
    #[ORM\Column(length: 20, nullable: true, enumType: CourtSurface::class)]
    #[Groups(['terrain:read', 'terrain:write'])]
    private ?CourtSurface $surface = null;

    /** @var list<int> ⊆ {60, 90} */
    #[ORM\Column]
    #[Groups(['terrain:read', 'terrain:write'])]
    private array $dureesAutoriseesMinutes = [60, 90];

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['terrain:read', 'terrain:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRessource(): ?Ressource
    {
        return $this->ressource;
    }

    public function setRessource(?Ressource $ressource): self
    {
        $this->ressource = $ressource;

        return $this;
    }

    public function getType(): TypeTerrain
    {
        return $this->type;
    }

    public function setType(TypeTerrain $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return list<int> */
    public function getDureesAutoriseesMinutes(): array
    {
        return $this->dureesAutoriseesMinutes;
    }

    /** @param list<int> $dureesAutoriseesMinutes */
    public function setDureesAutoriseesMinutes(array $dureesAutoriseesMinutes): self
    {
        $this->dureesAutoriseesMinutes = $dureesAutoriseesMinutes;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    #[Groups(['terrain:read'])]
    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->ressource?->getEtablissement();
    }

    /**
     * LE NOM DU TERRAIN, LU SUR SA RESSOURCE — parce que l'ecran n'en avait aucun.
     *
     * ⚠ UN TERRAIN CREE S'AFFICHAIT SANS NOM, ET LA CREATION PASSAIT POUR CASSEE.
     *
     * `TerrainPadel` ne porte pas de libelle : il vit sur la `Ressource` du socle, que
     * `CreerTerrainProcessor` cree en cascade. Mais l'API rend `ressource` comme une IRI, pas comme
     * un objet — et l'ecran lisait `t.ressource?.libelle || t.libelle || ''`, donc la chaine vide
     * dans tous les cas.
     *
     * Mesure du 03/09 : un terrain cree par POST est bien en base, l'API le rend, et sa ligne
     * s'affiche vide. Maxime a signale « creer un terrain ne marche pas » — la creation marchait,
     * c'est son resultat qui etait invisible. Les deux se ressemblent beaucoup vu de l'ecran.
     *
     * ⚠ EN LECTURE SEULE, ET DELIBEREMENT. Le nom appartient a la ressource : l'exposer en ecriture
     * ici donnerait deux chemins pour renommer un terrain, dont un qui contourne le socle. Le
     * renommage passe par la ressource, ou pas du tout.
     */
    #[Groups(['terrain:read'])]
    public function getLibelle(): ?string
    {
        return $this->ressource?->getLibelle();
    }

    public function getSport(): CourtSport
    {
        return $this->sport;
    }

    public function setSport(CourtSport $sport): self
    {
        $this->sport = $sport;

        return $this;
    }

    public function getSurface(): ?CourtSurface
    {
        return $this->surface;
    }

    public function setSurface(?CourtSurface $surface): self
    {
        $this->surface = $surface;

        return $this;
    }
}
