<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Marketing\State\ReferralCodeProvider;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * LE CODE D'UN PARRAIN — la seule partie du parrainage qui circule hors de l'application.
 *
 * Il est **tiré au sort**, jamais dérivé du client. Un code calculé depuis l'identifiant ou le nom
 * laisserait deviner les codes des autres, et un code deviné crédite quelqu'un qui n'a rien fait.
 *
 * Deux caractères sont exclus de l'alphabet : `I` et `O`, qu'on lit `1` et `0` sur un ticket
 * imprimé. Un code qu'on ne peut pas dicter au téléphone ne sert à rien.
 *
 * ── CE QUI MANQUE ENCORE, ET QU'IL FAUT DIRE ────────────────────────────────────────────────────
 *
 * Ce code n'est **distribué par aucun canal automatique** : ni courriel, ni tunnel d'inscription en
 * boutique. Il s'affiche sur la fiche client, et l'agent le donne. C'est utilisable au comptoir, et
 * insuffisant pour un parrainage en ligne — le jour où la boutique saura le porter, c'est elle qui
 * appellera cette même route.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_referral_code')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_referral_code', columns: ['establishment_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_marketing_referral_code_parrain', columns: ['establishment_id', 'sponsor_ref'])]
#[ApiResource(
    shortName: 'ReferralCode',
    operations: [
        // `{id}` désigne le CLIENT. Le code est créé au premier appel : demander son code EST le
        // geste qui l'attribue, et un écran qui afficherait « aucun code » sans bouton serait une
        // impasse.
        new Get(
            uriTemplate: '/marketing/parrainage/code/{id}',
            security: "is_granted('PERM', 'fidelite.lire')",
            provider: ReferralCodeProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['referral_code:read']],
)]
class ReferralCode
{
    /** Sans `I` ni `O` : illisibles sur un ticket, et indictables au téléphone. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LONGUEUR = 8;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['referral_code:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['referral_code:read'])]
    private ?Etablissement $establishment = null;

    /** Référence libre vers `App\Crm\Entity\Client` (D2). */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['referral_code:read'])]
    private ?Uuid $sponsorRef = null;

    #[ORM\Column(length: 16)]
    #[Groups(['referral_code:read'])]
    private string $code = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['referral_code:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Un tirage, pas un calcul : un code dérivé du client laisserait deviner celui des autres. */
    public static function tirer(): string
    {
        $code = '';
        for ($i = 0; $i < self::LONGUEUR; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getSponsorRef(): ?Uuid
    {
        return $this->sponsorRef;
    }

    public function setSponsorRef(?Uuid $sponsorRef): self
    {
        $this->sponsorRef = $sponsorRef;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
