<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Boutique\State\VitrinePubliqueProvider;
use App\Boutique\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Vitrine white-label par établissement (US-L8-01, RG-M3-01/08). Porte l'identité visuelle, les
 * langues, les canaux actifs et le délai d'expiration du panier (RG-M3-03, décision actée « X
 * minutes »). Lecture publique (CA-1) ; gestion réservée à `boutique.gerer_vitrine`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_vitrine')]
#[ORM\UniqueConstraint(name: 'uniq_vitrine_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'BoutiqueVitrine',
    operations: [
        new GetCollection(uriTemplate: '/boutique/vitrines', security: "is_granted('PERM', 'boutique.lire')"),
        new Get(
            uriTemplate: '/boutique/vitrines/{id}',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: VitrinePubliqueProvider::class,
        ),
        new Post(
            uriTemplate: '/boutique/vitrines',
            security: "is_granted('PERM', 'boutique.gerer_vitrine')",
            processor: EstablishmentStampProcessor::class,
        ),
        new Patch(uriTemplate: '/boutique/vitrines/{id}', security: "is_granted('PERM', 'boutique.gerer_vitrine')"),
    ],
    normalizationContext: ['groups' => ['vitrine:read']],
    denormalizationContext: ['groups' => ['vitrine:write']],
)]
class Vitrine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vitrine:read', 'panier:read'])]
    private Uuid $id;

    // D41 — hors groupe d'ecriture : l'etablissement vient de la session serveur, pose par
    // `EstablishmentStampProcessor`, jamais du corps de la requete. La vitrine est un point d'entree
    // PUBLIC en lecture ; laisser l'appelant choisir son rattachement etait d'autant moins tenable.
    //
    // Plus d'`Assert\NotNull` non plus : la validation s'execute AVANT l'ecriture, donc avant
    // l'estampillage, et echouait en 422 sur une valeur que le serveur allait poser lui-meme
    // (constat de `claude-G`, paye de deux essais, que je ne refais pas). L'invariant tient par
    // l'estampilleur qui refuse plutot que de deviner, la colonne NOT NULL, et le garde global D41.
    #[ORM\OneToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['vitrine:read'])]
    private ?Etablissement $etablissement = null;

    /**
     * LE NOM DE LA BOUTIQUE DANS L'URL : `/b/piscine-municipale`.
     *
     * Nullable, et c'est un choix de compatibilite : les vitrines creees avant le 27/08 n'en ont pas,
     * et leur URL par identifiant continue de marcher. Un lien deja envoye dans un courriel de
     * confirmation ne se casse pas parce qu'on a trouve mieux.
     *
     * Modifiable par l'exploitant (`vitrine:write`) : c'est SON adresse, elle porte son nom, et le
     * defaut fabrique depuis le nom de l'etablissement n'est qu'une proposition.
     */
    #[ORM\Column(length: 80, unique: true, nullable: true)]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?string $logo = null;

    /** @var array<string, string>|null palette {primaire, secondaire...} */
    #[ORM\Column(nullable: true)]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private ?array $couleurs = null;

    /** @var list<string> ISO, non vide, défaut ['fr'] */
    #[ORM\Column]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private array $langues = ['fr'];

    /** @var list<string> ⊂ {en_ligne, app} */
    #[ORM\Column]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private array $canauxActifs = ['en_ligne'];

    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    #[Assert\Positive]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private int $delaiExpirationPanierMinutes = 15;

    /**
     * LES SITES QUI ONT LE DROIT D'ENCADRER CETTE BOUTIQUE.
     *
     * Sans en-tete `frame-ancestors`, n'importe quel site peut afficher cette boutique dans une
     * iframe, sous son propre nom. Le visiteur paie sur une page qu'il croit etre celle du site
     * encadrant. Rien ne casse et rien n'alerte -- c'est pour ca que personne ne le remarque.
     *
     * **Vide = encadrable nulle part**, et c'est le sens sur de l'erreur : une integration qui ne
     * marche pas se signale et se corrige en une ligne ; une boutique encadrable par tout le monde ne
     * se signale jamais.
     *
     * On stocke des ORIGINES (`https://exemple.fr`), pas des noms d'hote : c'est ce que la directive
     * CSP attend, et un `http://` accepte par erreur ouvrirait l'encadrement a un intermediaire.
     * `https://*.exemple.fr` est accepte pour un client qui a plusieurs sous-domaines.
     *
     * La valeur ne sert pas ici : elle alimente `php bin/console app:integration:csp`, qui ecrit la
     * carte nginx. C'est nginx qui sert le front statique, donc lui seul peut poser l'en-tete.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['vitrine:read', 'vitrine:write'])]
    private array $domainesIntegration = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getCouleurs(): ?array
    {
        return $this->couleurs;
    }

    /** @param array<string, string>|null $couleurs */
    public function setCouleurs(?array $couleurs): self
    {
        $this->couleurs = $couleurs;

        return $this;
    }

    /** @return list<string> */
    public function getLangues(): array
    {
        return $this->langues;
    }

    /** @param list<string> $langues */
    public function setLangues(array $langues): self
    {
        $this->langues = $langues === [] ? ['fr'] : array_values($langues);

        return $this;
    }

    /** @return list<string> */
    public function getCanauxActifs(): array
    {
        return $this->canauxActifs;
    }

    /** @param list<string> $canauxActifs */
    public function setCanauxActifs(array $canauxActifs): self
    {
        $this->canauxActifs = array_values($canauxActifs);

        return $this;
    }

    public function getDelaiExpirationPanierMinutes(): int
    {
        return $this->delaiExpirationPanierMinutes;
    }

    public function setDelaiExpirationPanierMinutes(int $delaiExpirationPanierMinutes): self
    {
        $this->delaiExpirationPanierMinutes = $delaiExpirationPanierMinutes;

        return $this;
    }

    /** @return list<string> */
    public function getDomainesIntegration(): array
    {
        return $this->domainesIntegration;
    }

    /** @param list<string> $domainesIntegration */
    public function setDomainesIntegration(array $domainesIntegration): self
    {
        // Normalise : un espace ou une barre finale collee par un copier-coller ferait echouer la
        // comparaison d'origine cote navigateur, sans aucun message.
        $this->domainesIntegration = array_values(array_filter(array_map(
            static fn (mixed $d): string => rtrim(trim((string) $d), '/'),
            $domainesIntegration,
        ), static fn (string $d): bool => $d !== ''));

        return $this;
    }

    /**
     * UN DOMAINE MAL ECRIT N'OUVRE RIEN ET NE DIT RIEN.
     *
     * Le navigateur compare l'origine caractere par caractere. `exemple.fr` sans schema, ou une barre
     * finale, ne correspond a rien -- l'iframe reste blanche et l'exploitant conclut que la
     * fonctionnalite ne marche pas. On refuse a la saisie, la ou la faute se corrige.
     */
    #[Assert\Callback]
    public function validerDomainesIntegration(ExecutionContextInterface $context): void
    {
        foreach ($this->domainesIntegration as $i => $domaine) {
            if (preg_match('#^https://(\\*\\.)?[a-z0-9-]+(\\.[a-z0-9-]+)+(:[0-9]{1,5})?$#i', $domaine) !== 1) {
                $context->buildViolation('« {{ valeur }} » n’est pas une origine valide. Attendu : https://exemple.fr (ou https://*.exemple.fr).')
                    ->setParameter('{{ valeur }}', $domaine)
                    ->atPath(sprintf('domainesIntegration[%d]', $i))
                    ->addViolation();
            }
        }
    }
}
