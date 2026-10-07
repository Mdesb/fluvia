<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Offre\State\SuppressionReferentielProcessor;
use App\Offre\Validator as OffreAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Offre\State\TenantReferenceProcessor;
use App\Organisation\Entity\Etablissement;

/**
 * Saison : période de validité d'un prix (RG-M1-01). En cas de chevauchement pour une date,
 * la saison de priorité supérieure l'emporte (RG-M1-06 / CA-12). Deux saisons de MÊME priorité
 * ne peuvent se chevaucher (CA-9). Non supprimable si utilisée (désactivation seule, CA-9).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_saison')]
#[UniqueEntity(fields: ['nom'], message: 'Une saison porte déjà ce nom.')]
#[OffreAssert\SaisonSansChevauchement]
#[ApiResource(
    shortName: 'Saison',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Get(security: "is_granted('PERM', 'offre.lire')"),
        // D51 — entierement cloisonne : l etablissement est estampille depuis le CONTEXTE
        // serveur, jamais lu du corps de la requete (D3/D8). C est la seule raison pour laquelle un
        // appelant ne peut pas deposer sa saison ou sa grille de quotient chez le voisin.
        new Post(security: "is_granted('PERM', 'offre.gerer')", processor: TenantReferenceProcessor::class),
        new Patch(security: "is_granted('PERM', 'offre.gerer')", processor: TenantReferenceProcessor::class),
        new Delete(
            security: "is_granted('PERM', 'offre.gerer')",
            processor: SuppressionReferentielProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['ref:read']],
    denormalizationContext: ['groups' => ['ref:write']],
)]
class Saison
{
    /**
     * L'établissement propriétaire. **Nul uniquement pour les lignes antérieures au cloisonnement**
     * (D51) — et une ligne nulle n'est visible de personne.
     *
     * C'est voulu : la migration les laisse orphelines plutôt que de leur inventer un propriétaire.
     * Une saison rattachée au hasard produirait des tarifs calculés sur la saison d'un autre
     * établissement, et pour les tranches de quotient familial, D51 rappelle qu'une erreur de grille
     * est une **erreur de facturation opposable**. Une donnée manquante reste visiblement manquante ;
     * une donnée fausse ne se voit pas.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ref:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ref:read', 'grille:read', 'produit:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['ref:read', 'ref:write', 'grille:read', 'produit:read'])]
    private string $nom = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Assert\Expression(
        'this.getDateFin() === null or this.getDateDebut() === null or this.getDateFin() >= this.getDateDebut()',
        message: 'La date de fin doit être postérieure ou égale à la date de début.'
    )]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['ref:read', 'ref:write', 'grille:read'])]
    private int $priorite = 0;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ref:read', 'ref:write'])]
    private bool $recurrenceAnnuelle = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['ref:read', 'ref:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getPriorite(): int
    {
        return $this->priorite;
    }

    public function setPriorite(int $priorite): self
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function isRecurrenceAnnuelle(): bool
    {
        return $this->recurrenceAnnuelle;
    }

    public function setRecurrenceAnnuelle(bool $recurrenceAnnuelle): self
    {
        $this->recurrenceAnnuelle = $recurrenceAnnuelle;

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

    /**
     * Vrai si le JOUR de la date, à l'heure de l'établissement, tombe dans [dateDebut, dateFin]
     * (bornes incluses) : le premier et le dernier jour se vendent en entier.
     */
    public function contient(\DateTimeImmutable $date): bool
    {
        if ($this->dateDebut === null || $this->dateFin === null) {
            return false;
        }

        // ⚠ UNE SAISON SE COMPTE EN JOURS DE L'ETABLISSEMENT, PAS EN INSTANTS DU SERVEUR.
        //
        // Les bornes sont des colonnes `date` : Doctrine les rend a 00:00, dans le fuseau du conteneur
        // (UTC). Comparer l'instant de la vente a ces minuits arretait la vente du dernier jour a
        // 00:00 UTC (01:00 ou 02:00 a Paris) et ouvrait le premier une ou deux heures en retard.
        // Mesure le 07/10/2026 (`SeasonLastDayTest`). Sans etablissement (lignes anterieures a D51),
        // le defaut d'`Etablissement::$fuseauHoraire`.
        $date = $date->setTimezone(new \DateTimeZone($this->etablissement?->getFuseauHoraire() ?? 'Europe/Paris'));

        // ⚠ « CHAQUE ANNEE » ETAIT COCHABLE, S'ENREGISTRAIT, ET PERSONNE NE LA LISAIT.
        //
        // L'ecran de parametres propose la case depuis toujours, avec son aide : « Evite de recreer
        // la meme saison chaque annee ». Le champ partait bien en base. Et AUCUN code ne le lisait —
        // `contient()` comparait deux dates completes, un point c'est tout.
        //
        // Une promesse creuse, longtemps sans consequence. Puis la reconduction s'est mise a relire
        // le prix dans les saisons (03/09) : un exploitant qui FAIT CE QUE L'ECRAN LUI DIT — cocher
        // et ne rien recreer — voit desormais ses reconductions s'arreter au premier jour hors de
        // l'intervalle. La phrase d'aide lui a dit de ne rien faire.
        //
        // Arbitrage de Maxime le 03/09 : « prolonger la derniere saison ». Signale par
        // `allaccess-c0`, qui a mesure la promesse creuse avant que quiconque s'y fie.
        if ($this->recurrenceAnnuelle) {
            return self::dansSegments(self::moisJour($date), self::segmentsMoisJour($this->dateDebut, $this->dateFin));
        }

        $jour = $date->format('Y-m-d');

        return $jour >= $this->dateDebut->format('Y-m-d') && $jour <= $this->dateFin->format('Y-m-d');
    }

    /** Vrai si les intervalles de dates se recouvrent (bornes incluses). */
    public function chevauche(self $autre): bool
    {
        if ($this->dateDebut === null || $this->dateFin === null
            || $autre->dateDebut === null || $autre->dateFin === null) {
            return false;
        }

        // ⚠ SI `contient()` HONORE LA RECURRENCE ET PAS `chevauche()`, LE VALIDATEUR DEVIENT AVEUGLE.
        //
        // `SaisonSansChevauchementValidator` empeche deux saisons de meme priorite de se recouvrir.
        // Tant qu'aucune des deux methodes ne lisait `recurrenceAnnuelle`, elles etaient d'accord
        // entre elles — deux defauts qui s'annulent. Corriger la premiere seule aurait laisse le
        // validateur passer VERT sur un vrai conflit : une saison recurrente cesserait d'etre vue
        // comme chevauchant une saison fixe de l'annee suivante, alors qu'elle la recouvre bel et
        // bien.
        //
        // Signale par `allaccess-c0` avant que la premiere ligne soit ecrite. C'est le genre de
        // paire qu'on ne voit qu'en cherchant ce que l'absence du geste manquant protegeait.
        if ($this->recurrenceAnnuelle || $autre->recurrenceAnnuelle) {
            foreach (self::segmentsMoisJour($this->dateDebut, $this->dateFin, !$this->recurrenceAnnuelle) as $a) {
                foreach (self::segmentsMoisJour($autre->dateDebut, $autre->dateFin, !$autre->recurrenceAnnuelle) as $b) {
                    if ($a[0] <= $b[1] && $b[0] <= $a[1]) {
                        return true;
                    }
                }
            }

            return false;
        }

        return $this->dateDebut <= $autre->dateFin && $autre->dateDebut <= $this->dateFin;
    }

    /**
     * Le couple mois-jour d'une date, comme entier comparable : 15 decembre -> 1215.
     *
     * ⚠ ON NE RECONSTRUIT PAS DE DATE. Rapporter le 29 fevrier d'une saison recurrente sur une annee
     * non bissextile donnerait le 1er mars, ou leverait, selon la methode. Un entier mois*100+jour
     * se compare sans jamais avoir besoin d'une annee, donc sans jamais rencontrer ce cas.
     */
    private static function moisJour(\DateTimeImmutable $date): int
    {
        return ((int) $date->format('n')) * 100 + (int) $date->format('j');
    }

    /**
     * Les segments mois-jour couverts, en 1 ou 2 morceaux.
     *
     * ⚠ UNE SAISON QUI ENJAMBE LE NOUVEL AN SE COUPE EN DEUX, ET C'EST LE PIEGE PRINCIPAL.
     *
     * Des vacances du 15 decembre au 15 janvier donnent `debut = 1215` et `fin = 0115`. La
     * comparaison naturelle `debut <= date <= fin` est alors FAUSSE TOUTE L'ANNEE : elle exige
     * d'etre a la fois apres decembre et avant janvier. Le cas s'inverse — il faut lire
     * `date >= debut OU date <= fin` — ce qu'on obtient en rendant deux segments.
     *
     * @return list<array{int, int}>
     */
    private static function segmentsMoisJour(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin, bool $fixe = false): array
    {
        if ($debut === null || $fin === null) {
            return [];
        }

        // ⚠ UNE SAISON FIXE DE PLUS D'UN AN COUVRE TOUS LES MOIS-JOURS. Sans ce cas, elle serait
        // reduite a son mois-jour de depart et cesserait de chevaucher une recurrente qu'elle
        // contient pourtant en entier.
        if ($fixe && $debut->diff($fin)->days >= 366) {
            return [[101, 1231]];
        }

        $d = self::moisJour($debut);
        $f = self::moisJour($fin);

        return $d <= $f ? [[$d, $f]] : [[$d, 1231], [101, $f]];
    }

    /**
     * @param list<array{int, int}> $segments
     */
    private static function dansSegments(int $moisJour, array $segments): bool
    {
        foreach ($segments as [$debut, $fin]) {
            if ($moisJour >= $debut && $moisJour <= $fin) {
                return true;
            }
        }

        return false;
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
