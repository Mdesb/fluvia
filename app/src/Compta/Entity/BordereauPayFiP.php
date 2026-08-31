<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\StatutPayFiP;
use App\Compta\State\PayFipRejouerProcessor;
use App\Compta\State\PayFipRetourProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Transaction PayFiP (US-L4-03, RG-PAYFIP-03, CA-6). ⚠ HYPOTHÈSE — protocole exact non détaillé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_bordereau_payfip')]
#[ApiResource(
    shortName: 'BordereauPayFiP',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/payfip/retour',
            read: false,
            input: false,
            // n°8 (D8, 23/08) — cette route etait ouverte a **tout compte authentifie**, sans aucune
            // permission, et elle **ecrit un fait financier** : elle marque un paiement recu, et cree
            // meme le bordereau s'il n'existe pas. N'importe quel titulaire de compte pouvait donc
            // declarer encaissee n'importe quelle vente, dans n'importe quel etablissement.
            //
            // On aligne sur la permission de la route soeur `/rejouer`. C'est une mesure de
            // repli assumee : un rappel de prestataire n'est pas un utilisateur connecte, et la
            // vraie garde est une preuve d'authenticite du rappel (signature DGFiP). Elle est
            // inconnue de nous et consignee au registre des bloqueurs externes (E-7) — voir la note
            // de ce fichier. Maxime a confirme le 23/08 que **rien n'appelle cette route aujourd'hui**,
            // ce qui rend ce durcissement sans risque de rupture.
            security: "is_granted('PERM', 'compta.valider')",
            processor: PayFipRetourProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/payfip/{id}/rejouer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.valider')",
            processor: PayFipRejouerProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['payfip:read']],
)]
class BordereauPayFiP
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['payfip:read'])]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['payfip:read'])]
    private Uuid $venteOrigine;

    #[ORM\Column(length: 64)]
    #[Groups(['payfip:read'])]
    private string $referenceTransaction = '';

    #[ORM\Column(length: 12, enumType: StatutPayFiP::class, options: ['default' => 'en_attente'])]
    #[Groups(['payfip:read'])]
    private StatutPayFiP $statutRetour = StatutPayFiP::EnAttente;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['payfip:read'])]
    private bool $venteRapprochee = false;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['payfip:read'])]
    private int $nbTentativesRejeu = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['payfip:read'])]
    private \DateTimeImmutable $dateHeure;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->venteOrigine = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVenteOrigine(): Uuid
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(Uuid $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getReferenceTransaction(): string
    {
        return $this->referenceTransaction;
    }

    public function setReferenceTransaction(string $referenceTransaction): self
    {
        $this->referenceTransaction = $referenceTransaction;

        return $this;
    }

    public function getStatutRetour(): StatutPayFiP
    {
        return $this->statutRetour;
    }

    public function setStatutRetour(StatutPayFiP $statutRetour): self
    {
        $this->statutRetour = $statutRetour;

        return $this;
    }

    public function isVenteRapprochee(): bool
    {
        return $this->venteRapprochee;
    }

    public function setVenteRapprochee(bool $venteRapprochee): self
    {
        $this->venteRapprochee = $venteRapprochee;

        return $this;
    }

    public function getNbTentativesRejeu(): int
    {
        return $this->nbTentativesRejeu;
    }

    public function setNbTentativesRejeu(int $nbTentativesRejeu): self
    {
        $this->nbTentativesRejeu = $nbTentativesRejeu;

        return $this;
    }

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }
}
