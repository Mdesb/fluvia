<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\SensCompte;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Regime\Dto\EcritureADto;
use App\Compta\Regime\Dto\LigneVenteProjectionDto;
use App\Compta\Regime\Dto\SettlementProjectionDto;
use App\Compta\Regime\Dto\VenteProjectionDto;
use App\Compta\Regime\MappingResolver;
use App\Compta\Regime\RegimeBase;
use App\Compta\ValueObject\ParametresRegime;
use App\Offre\Enum\ReglePca;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LA VENTILATION DE L'ENCAISSEMENT PAR MOYEN DE PAIEMENT.
 *
 * Une vente produisait UNE ligne de débit, sur un compte unique, quel que soit le mode de règlement :
 * espèces, carte et chèque confondus. `RegimeComptableInterface` le disait lui-même — « frontière
 * simple, hors ventilation par moyen ». Un rapprochement bancaire ne peut rien en faire, puisque les
 * espèces vivent en 53 et la banque en 512.
 *
 * ⚠ LE PREMIER TEST EST LE PLUS IMPORTANT DES QUATRE, ET CE N'EST PAS CELUI QUI VENTILE.
 *
 * Le débit ne vaut pas le total de la VENTE : il vaut la somme des CRÉDITS RETENUS. Les lignes dont
 * la catégorie n'a pas de mapping valide sont écartées en amont (CA-2), et le débit suivait
 * jusqu'ici automatiquement. Ventiler d'après les montants réels des règlements — qui somment au
 * total de la vente — casserait l'équilibre dès qu'une ligne est écartée : une donnée manquante
 * deviendrait une écriture comptable fausse.
 *
 * D'où la répartition AU PRORATA de la somme des crédits, et d'où ce test en tête.
 */
final class VentilationEncaissementTest extends TestCase
{
    private const CATEGORIE_MAPPEE = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    /**
     * L'ÉQUILIBRE TIENT MÊME QUAND UNE LIGNE DE VENTE EST ÉCARTÉE.
     *
     * La vente vaut 100 € réglés en deux fois, mais l'une de ses deux lignes n'a pas de mapping :
     * seuls 60 € sont crédités. Les débits doivent totaliser 60 €, jamais 100.
     */
    public function testLEquilibreTientQuandUneLigneEstEcartee(): void
    {
        $ecriture = $this->generer(
            ventilation: true,
            lignes: [$this->ligneMappee(6000), $this->ligneSansCategorie(4000)],
            reglements: [$this->reglement('ESP', 3000), $this->reglement('CB', 7000)],
        );

        $credits = $this->total($ecriture, credit: true);
        $debits = $this->debits($ecriture);

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Sans crédit, l'égalité des deux sommes serait vraie et ne dirait rien : 0 == 0.
        self::assertSame(6000, $credits, 'témoin : seule la ligne mappée est créditée');

        self::assertCount(2, $debits, 'deux moyens, deux lignes de débit');
        self::assertSame($credits, array_sum($debits), 'les débits suivent les crédits, pas le total de la vente');
    }

    /**
     * CHAQUE MOYEN PORTE SON PROPRE COMPTE, AU PRORATA DE SA PART.
     */
    public function testChaqueMoyenPorteSonPropreCompte(): void
    {
        $ecriture = $this->generer(
            ventilation: true,
            lignes: [$this->ligneMappee(10000)],
            reglements: [$this->reglement('ESP', 4000), $this->reglement('CB', 6000)],
        );

        self::assertSame(
            ['512000' => 6000, '530000' => 4000],
            $this->debitsParCompte($ecriture),
            'la carte sur le compte banque, les espèces sur le compte caisse',
        );
    }

    /**
     * SANS COMPTE DÉCLARÉ POUR UN MOYEN, CE MOYEN RETOMBE SUR L'ENCAISSEMENT UNIQUE.
     *
     * C'est ce qui permet d'activer la ventilation sans l'avoir remplie jusqu'au bout : un moyen
     * oublié ne fait pas échouer la génération d'écritures, il garde l'ancien comportement.
     */
    public function testUnMoyenNonDeclareRetombeSurLeCompteUnique(): void
    {
        $ecriture = $this->generer(
            ventilation: true,
            lignes: [$this->ligneMappee(10000)],
            reglements: [$this->reglement('ESP', 5000), $this->reglement('INCONNU', 5000)],
        );

        self::assertSame(
            ['511000' => 5000, '530000' => 5000],
            $this->debitsParCompte($ecriture),
            'le moyen non déclaré prend le compte d’encaissement unique, sans erreur',
        );
    }

    /**
     * DRAPEAU ÉTEINT : UNE SEULE LIGNE, EXACTEMENT COMME AVANT CE LOT.
     *
     * Non-régression du déploiement. Les règlements sont pourtant fournis : si la ventilation se
     * déclenchait sans que l'exploitant l'ait demandée, ce test le verrait.
     */
    public function testSansLeDrapeauRienNeChange(): void
    {
        $ecriture = $this->generer(
            ventilation: false,
            lignes: [$this->ligneMappee(10000)],
            reglements: [$this->reglement('ESP', 4000), $this->reglement('CB', 6000)],
        );

        self::assertSame(
            ['511000' => 10000],
            $this->debitsParCompte($ecriture),
            'écriture inchangée tant que l’exploitant n’a pas tranché',
        );
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<LigneVenteProjectionDto>  $lignes
     * @param list<SettlementProjectionDto>   $reglements
     */
    private function generer(bool $ventilation, array $lignes, array $reglements): EcritureADto
    {
        $profil = (new ProfilExploitant())
            ->setParametresRegime(new ParametresRegime(ventilationEncaissementParMoyen: $ventilation));

        $mapping = new MappingComptable();
        $mapping->setProfilExploitant($profil)
            ->setCategorie(Uuid::fromString(self::CATEGORIE_MAPPEE))
            ->setCompteProduit($this->compte($profil, '706000', 'Prestations', SensCompte::Credit))
            ->setTauxTva($this->taux($profil));

        $vente = new VenteProjectionDto(
            id: Uuid::v4(),
            numero: 'V-1',
            etablissement: Uuid::v4(),
            date: new \DateTimeImmutable('2026-08-29 10:00:00'),
            lignes: $lignes,
            totalTtcCentimes: array_sum(array_map(
                static fn (LigneVenteProjectionDto $l): int => $l->montantTtcCentimes,
                $lignes,
            )),
            reglements: $reglements,
        );

        $regime = new RegimeEprouveVentilation($profil, $this->taux($profil));

        return $regime->genererEcritureVente($vente, $profil, new MappingResolver([self::CATEGORIE_MAPPEE => $mapping]));
    }

    private function ligneMappee(int $ttcCentimes): LigneVenteProjectionDto
    {
        return $this->ligne(Uuid::fromString(self::CATEGORIE_MAPPEE), $ttcCentimes);
    }

    private function ligneSansCategorie(int $ttcCentimes): LigneVenteProjectionDto
    {
        return $this->ligne(null, $ttcCentimes);
    }

    private function ligne(?Uuid $categorie, int $ttcCentimes): LigneVenteProjectionDto
    {
        return new LigneVenteProjectionDto(
            produit: Uuid::v4(),
            categorieComptable: $categorie,
            montantTtcCentimes: $ttcCentimes,
            reglePca: ReglePca::Aucune,
            dureeValidite: null,
            nbCrediteCarte: null,
            identifiantSupport: null,
        );
    }

    private function reglement(string $code, int $net): SettlementProjectionDto
    {
        return new SettlementProjectionDto(paymentMethodCode: $code, netAmountCents: $net);
    }

    private function compte(ProfilExploitant $profil, string $numero, string $libelle, SensCompte $sens): CompteComptable
    {
        return (new CompteComptable())
            ->setProfilExploitant($profil)
            ->setNumero($numero)
            ->setLibelle($libelle)
            ->setSens($sens);
    }

    private function taux(ProfilExploitant $profil): TauxTva
    {
        return (new TauxTva())
            ->setProfilExploitant($profil)
            ->setTaux('20.00')
            ->setLibelle('Taux normal 20 %');
    }

    /** @return list<int> */
    private function debits(EcritureADto $ecriture): array
    {
        $debits = [];
        foreach ($ecriture->lignes as $ligne) {
            if ($ligne->debitCentimes > 0) {
                $debits[] = $ligne->debitCentimes;
            }
        }

        return $debits;
    }

    /** @return array<string, int> ordonné par numéro de compte, pour que l'assertion soit stable */
    private function debitsParCompte(EcritureADto $ecriture): array
    {
        $parCompte = [];
        foreach ($ecriture->lignes as $ligne) {
            if ($ligne->debitCentimes > 0) {
                $numero = $ligne->compte->getNumero();
                $parCompte[$numero] = ($parCompte[$numero] ?? 0) + $ligne->debitCentimes;
            }
        }
        ksort($parCompte);

        return $parCompte;
    }

    private function total(EcritureADto $ecriture, bool $credit): int
    {
        $total = 0;
        foreach ($ecriture->lignes as $ligne) {
            $total += $credit ? $ligne->creditCentimes : $ligne->debitCentimes;
        }

        return $total;
    }
}

/**
 * Régime d'épreuve : tout le comportement testé vit dans `RegimeBase`, seules les résolutions de
 * comptes sont fournies en dur.
 *
 * ⚠ Le constructeur N'APPELLE PAS `parent::__construct()`, délibérément. `RegimeBase` reçoit un
 * `CompteLookupService` qui est `final` — donc impossible à doubler — et dont ce test n'a aucun
 * besoin : les quatre méthodes qui l'utilisent sont toutes redéfinies ici. La propriété reste
 * simplement non initialisée, et y toucher lèverait bruyamment plutôt que de rendre un compte faux.
 *
 * C'est ce que la couture `compteEncaissementPourMoyen()` rend possible : l'arithmétique de
 * répartition — la partie qui doit être juste au centime — s'éprouve sans base de données.
 */
final class RegimeEprouveVentilation extends RegimeBase
{
    /** @var array<string, string> code de moyen → numéro de compte déclaré */
    private const VENTILATION = [
        'ESP' => '530000',
        'CB' => '512000',
        // « INCONNU » est volontairement absent : c'est le cas du moyen non déclaré.
    ];

    public function __construct(
        private readonly ProfilExploitant $profil,
        private readonly TauxTva $taux,
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::GroupePrive;
    }

    public function formatsExportDisponibles(): array
    {
        return [FormatExport::Fec];
    }

    public function journalPour(ProfilExploitant $profil, NatureOperation $nature): Journal
    {
        return (new Journal())->setProfilExploitant($profil)->setCode('VTE')->setLibelle('Ventes');
    }

    public function compteAttente487(ProfilExploitant $profil, ?QualificationEquipement $qualif): CompteComptable
    {
        return $this->compte('487000', 'Comptes de répartition', SensCompte::Credit);
    }

    public function compteEncaissement(ProfilExploitant $profil): CompteComptable
    {
        return $this->compte('511000', 'Encaissements à ventiler', SensCompte::Debit);
    }

    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable
    {
        return $this->compte('445710', 'TVA collectée', SensCompte::Credit);
    }

    protected function compteEncaissementPourMoyen(ProfilExploitant $profil, string $moyenCode): ?CompteComptable
    {
        $numero = self::VENTILATION[$moyenCode] ?? null;

        return $numero === null ? null : $this->compte($numero, 'Compte ' . $moyenCode, SensCompte::Debit);
    }

    private function compte(string $numero, string $libelle, SensCompte $sens): CompteComptable
    {
        return (new CompteComptable())
            ->setProfilExploitant($this->profil)
            ->setNumero($numero)
            ->setLibelle($libelle)
            ->setSens($sens);
    }
}
