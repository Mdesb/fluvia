<?php

declare(strict_types=1);

namespace App\Organisation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\State\StampCreatorAffectationProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Établissement : pivot du cloisonnement multi-entités (RG-SOCLE-05). Rattaché à une Région.
 * Les lectures sont bornées au périmètre affecté à l'utilisateur courant (extension Doctrine).
 */
#[ORM\Entity]
#[ORM\Table(name: 'org_etablissement')]
#[ApiResource(
    shortName: 'Etablissement',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        // L'auteur est rattache au site qu'il vient de creer. Sans cela, la creation reussit (201)
        // et l'etablissement n'apparait NULLE PART : la liste est filtree sur les affectations du
        // lecteur, et creer un site n'en cree pas. L'exploitant reclique, et fabrique des doublons.
        new Post(
            security: "is_granted('PERM', 'organisation.gerer')",
            processor: StampCreatorAffectationProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'organisation.gerer')"),
        // PAS DE `Delete`, ET C'EST DELIBERE.
        //
        // Un etablissement n'est pas une ligne de referentiel : c'est **la frontiere de cloisonnement
        // a laquelle tout est rattache**. 119 entites du depot portent une relation vers lui, et
        // **deux** declarent un comportement de suppression. Les 117 autres retombent donc sur le
        // refus de la base : une suppression echouerait par une violation de cle etrangere brute --
        // ou, sur un etablissement encore vide, **reussirait**, en detruisant un perimetre reel.
        //
        // Aucun des deux comportements n'est acceptable, et le second est le pire : il ne se produit
        // que sur un etablissement recemment cree, c'est-a-dire au moment ou l'on tatonne encore.
        //
        // Ce qu'un exploitant veut reellement, c'est **desactiver** : le site cesse d'etre propose,
        // l'historique reste consultable, et c'est reversible. Le champ `actif` existe pour ca.
        //
        // Trouve par claude-H, qui avait deja refuse d'exposer le bouton cote ecran -- « un bouton qui
        // echoue une fois sur deux enseigne surtout qu'on peut reessayer ». Elle avait raison sur
        // l'ecran ; le trou etait dans l'API, ou n'importe qui portant `organisation.gerer` pouvait
        // appeler l'operation directement, bouton ou pas. Cacher un bouton ne ferme pas une porte.
        //
        // Si une fermeture definitive doit exister un jour, elle merite un geste dedie, ses propres
        // avertissements et une reprise des 119 relations -- pas la meme croix qu'un taux de TVA.
    ],
    normalizationContext: ['groups' => ['etablissement:read']],
    denormalizationContext: ['groups' => ['etablissement:write']],
)]
class Etablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['etablissement:read', 'espace:read', 'affectation:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['etablissement:read', 'etablissement:write', 'espace:read', 'affectation:read'])]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: Region::class, inversedBy: 'etablissements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private ?Region $region = null;

    #[ORM\Column]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private bool $actif = true;

    /**
     * Le fuseau dans lequel cet établissement vit sa journée.
     *
     * **Pourquoi ça n'est pas un détail de confort.** La clôture journalière NF525 arrête une journée
     * et la **scelle**. Une clôture planifiée à 3 h de Paris tombe à **21 h la veille aux Antilles** :
     * elle arrêterait une journée **en cours**, avec des ventes encore à venir dessus. Ces ventes
     * basculeraient dans la journée suivante, et le refus « journée sautée » ne les rattraperait pas —
     * puisque la journée aurait bien été close.
     *
     * Autrement dit : sur un établissement à l'ouest de Paris, l'automatisme produirait **exactement le
     * défaut que la clôture existe pour empêcher**, et le produirait scellé, donc indiscutable.
     *
     * Relevé par `claude-G` en écrivant la commande de clôture : j'avais exigé « le fuseau de
     * l'établissement, pas du serveur » sans vérifier que l'établissement en portait un. Il n'en portait
     * pas. Elle a refusé de l'inventer, refusé d'attendre, et proposé les trois options en chiffrant
     * chacune — La Réunion à 5 h du matin, acceptable ; les Antilles la veille, non.
     *
     * Le défaut vaut `Europe/Paris` : c'est le cas de tous les établissements existants, et une valeur
     * fausse pour personne aujourd'hui vaut mieux qu'une colonne nulle que chaque appelant interprète.
     */
    #[ORM\Column(length: 64, options: ['default' => 'Europe/Paris'])]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private string $fuseauHoraire = 'Europe/Paris';

    /**
     * LES MOTS DU METIER, PAR ETABLISSEMENT.
     *
     * Maxime, le 27/08 : << il manque les verticales salon de massage, salon de coiffure >>. En
     * regardant, le metier etait deja ecrit -- `Activite` porte une duree, `Ressource` une capacite,
     * et il existe des regles d'annulation et une facturation des non-presentations. Ce qui manquait
     * n'etait pas le modele : c'etaient LES MOTS.
     *
     * **Et ils ne peuvent pas etre globaux.** << Ressource >> veut dire *praticien* dans un salon,
     * *ligne d'eau* dans une piscine, *court* au padel. Traduire une fois pour tout le monde
     * rendrait le logiciel faux partout sauf a un endroit.
     *
     * Table de remplacements posee PAR-DESSUS `vocabulaire.js`, jamais a la place : un code absent
     * d'ici garde sa traduction par defaut. Un etablissement qui ne renseigne rien continue de voir
     * exactement ce qu'il voyait.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['etablissement:read', 'etablissement:write'])]
    private ?array $vocabulaire = null;

    /** @var Collection<int, Espace> */
    #[ORM\OneToMany(targetEntity: Espace::class, mappedBy: 'etablissement')]
    private Collection $espaces;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->espaces = new ArrayCollection();
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

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): self
    {
        $this->region = $region;

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

    /** @return Collection<int, Espace> */
    public function getEspaces(): Collection
    {
        return $this->espaces;
    }

    /** @return array<string, string>|null */
    public function getVocabulaire(): ?array
    {
        return $this->vocabulaire;
    }

    /** @param array<string, string>|null $vocabulaire */
    public function setVocabulaire(?array $vocabulaire): self
    {
        if ($vocabulaire === null) {
            $this->vocabulaire = null;

            return $this;
        }

        // On ne garde que des paires de chaines non vides. Une valeur vide effacerait le mot par
        // defaut sans en proposer d'autre : l'ecran afficherait un blanc a la place d'un terme.
        $propre = [];
        foreach ($vocabulaire as $code => $mot) {
            if (!\is_string($code) || !\is_string($mot) || trim($code) === '' || trim($mot) === '') {
                continue;
            }
            $propre[trim($code)] = trim($mot);
        }

        $this->vocabulaire = $propre === [] ? null : $propre;

        return $this;
    }

    public function getFuseauHoraire(): string
    {
        return $this->fuseauHoraire;
    }

    /**
     * @throws \InvalidArgumentException si l'identifiant n'est pas un fuseau connu
     */
    public function setFuseauHoraire(string $fuseauHoraire): self
    {
        // Un fuseau invalide ne se découvre pas à l'écriture mais à la première clôture, c'est-à-dire
        // au moment où l'on scelle. On refuse ici, où c'est encore réparable.
        if (!in_array($fuseauHoraire, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Fuseau horaire inconnu : « %s ». Attendu un identifiant IANA, par exemple Europe/Paris '
                . 'ou America/Martinique.',
                $fuseauHoraire,
            ));
        }

        $this->fuseauHoraire = $fuseauHoraire;

        return $this;
    }
}
