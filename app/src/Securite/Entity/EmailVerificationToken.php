<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Preuve qu'une adresse appartient bien a la personne qui vient de creer le compte.
 *
 * ⚠ POURQUOI CET OBJET EXISTE, ET CE QU'IL PROTEGE. Un achat en invite laisse une fiche `Client`
 * portant son e-mail, et ses commandes vivent avec `compteClient = null` : invisibles de tout
 * compte. Rattacher ces commandes au compte cree ensuite avec la meme adresse est le geste utile
 * -- mais rattacher sur la SEULE saisie de l'adresse donnerait l'historique d'achat de n'importe
 * qui a quiconque devine son e-mail. Le rattachement n'a donc lieu qu'apres cette preuve.
 *
 * ⚠ ON NE STOCKE JAMAIS LE JETON EN CLAIR. `jeton` porte un SHA-256 ; le clair ne vit que dans le
 * courriel. Une fuite de la table ne permet donc de verifier aucune adresse. C'est exactement le
 * patron de `JetonReinitialisation`, deliberement recopie plutot que reinvente -- deux mecanismes
 * de jeton divergeraient au premier correctif applique a un seul des deux.
 *
 * ⚠ ET C'EST UN OBJET SEPARE DE `JetonReinitialisation`, pas une colonne « usage » ajoutee dessus :
 * les confondre laisserait un jeton de reinitialisation de mot de passe verifier une adresse, et
 * inversement. Deux pouvoirs distincts ne partagent pas un support.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_email_verification_token')]
class EmailVerificationToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /** SHA-256 du jeton remis par courriel. Jamais le clair. */
    #[ORM\Column(length: 255, unique: true)]
    private string $jeton = '';

    /**
     * L'adresse dont ce jeton prouve la possession.
     *
     * ⚠ ON FIGE L'ADRESSE ICI, ET CE N'EST PAS UNE REDONDANCE. Sans elle, un compte qui change
     * d'e-mail entre l'envoi et le clic verrait la preuve porter sur l'adresse NOUVELLE, jamais
     * confirmee. Le rattachement se fait sur cette valeur-la, pas sur l'e-mail courant du compte.
     */
    #[ORM\Column(length: 180)]
    private string $adresse = '';

    #[ORM\Column]
    private \DateTimeImmutable $dateExpiration;

    #[ORM\Column(options: ['default' => false])]
    private bool $utilise = false;

    #[ORM\Column]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateExpiration = new \DateTimeImmutable('+48 hour');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function setJeton(string $jeton): self
    {
        $this->jeton = $jeton;

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): self
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getDateExpiration(): \DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    public function setDateExpiration(\DateTimeImmutable $dateExpiration): self
    {
        $this->dateExpiration = $dateExpiration;

        return $this;
    }

    public function estUtilise(): bool
    {
        return $this->utilise;
    }

    public function marquerUtilise(): self
    {
        $this->utilise = true;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /**
     * ⚠ UN SEUL POINT DE VERITE SUR LA VALIDITE. Le processeur ne rejoue pas ces deux conditions :
     * les recopier ailleurs, c'est accepter qu'un correctif n'en touche qu'une.
     */
    public function estValide(\DateTimeImmutable $maintenant): bool
    {
        return !$this->utilise && $this->dateExpiration > $maintenant;
    }
}
