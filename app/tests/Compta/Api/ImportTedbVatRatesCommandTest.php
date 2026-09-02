<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Command\ImportTedbVatRatesCommand;
use App\Compta\Entity\LegalVatRate;
use App\Compta\Enum\VatRateCategory;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `vat:import-tedb` — L'IMPORT QUI DOIT REFUSER D'ÊTRE COMPLET.
 *
 * TEDB (Commission européenne, DG TAXUD) est la seule source officielle et exhaustive des taux de
 * TVA européens qui soit lisible par une machine. Elle ne se plie pas à la forme de notre
 * référentiel, et chacun des trois écarts ci-dessous a été découvert par une **violation de
 * contrainte en base**, pas par une lecture de la documentation.
 *
 * Ce qui rend ces tests nécessaires : les trois pannes qu'ils gardent sont **silencieuses**. Aucune
 * ne lève d'exception, aucune ne fait rougir un autre test, et toutes les trois produisent un
 * référentiel qui a l'air plus complet qu'avant. Un taux faux ne se voit qu'une fois imprimé sur une
 * facture, chez un client, dans un autre pays.
 */
final class ImportTedbVatRatesCommandTest extends ComptaApiTestCase
{
    /**
     * **LE TEST QUI COMPTE : une ligne déjà sourcée à la main n'est jamais doublée.**
     *
     * La France porte `standard 20,00 au 2014-01-01, source « CGI art. 278 »`. TEDB rend la **même
     * valeur** à une **date différente** — `20,00 au 2026-07-01`. Un test d'idempotence portant sur
     * (pays, catégorie, date) ne trouve donc rien, et pose une seconde ligne.
     *
     * Rien ne casse. Le référentiel résout par date : c'est désormais la ligne TEDB qui s'applique,
     * et la citation du CGI ne sert plus. Une référence légale opposable remplacée par une référence
     * à une base de données — même chiffre, même écran, et plus personne pour dire d'où il sort.
     */
    public function testElleNeDoublePasUnTauxDejaSourceALaMain(): void
    {
        $this->semer('FR', VatRateCategory::Standard, '20.00', 'CGI art. 278');

        $affichage = $this->importer([
            $this->bloc('FR', 'STANDARD', [20.0]),
            $this->bloc('DE', 'STANDARD', [19.0]),
        ]);

        $fr = $this->lire('FR', VatRateCategory::Standard);

        // ⚠ TÉMOIN POSITIF, SANS QUOI CE TEST PASSE POUR LA MAUVAISE RAISON. Si l'import échouait
        // pour n'importe quelle cause — fichier illisible, plancher, exception avalée — la France
        // resterait seule et intacte, et les assertions ci-dessous seraient vertes. On exige donc la
        // preuve que l'import a bien tourné et a bien écrit *ailleurs*.
        self::assertNotEmpty(
            $this->lire('DE', VatRateCategory::Standard),
            "L'import n'a rien écrit du tout : le reste de ce test ne mesure rien.",
        );

        self::assertCount(1, $fr, 'La France a été doublée : la ligne TEDB va supplanter le CGI.');
        self::assertStringContainsString('CGI', (string) $fr[0]->getSource());
        self::assertSame('2014-01-01', $fr[0]->getValidFrom()->format('Y-m-d'));
        self::assertStringContainsString('1 deja presents', $affichage);
    }

    /**
     * **Deux taux standard pour un seul code pays : on n'en choisit aucun.**
     *
     * L'Espagne rend `7,00` et `21,00` à la même date — les Canaries et la péninsule, sous un seul
     * `ES`. Notre référentiel n'accepte qu'une valeur par (pays, catégorie, date) ; en retenir une
     * serait un arbitrage territorial rendu par un import.
     *
     * Prendre « la plus grande » donnerait 21 % : plausible, et faux pour Santa Cruz de Tenerife.
     */
    public function testElleNImportePasUneCleQuiRendPlusieursTaux(): void
    {
        $affichage = $this->importer([
            $this->bloc('ES', 'STANDARD', [7.0, 21.0]),
            $this->bloc('DE', 'STANDARD', [19.0]),
        ]);

        self::assertNotEmpty(
            $this->lire('DE', VatRateCategory::Standard),
            "L'import n'a rien écrit du tout : l'absence de l'Espagne ne prouve rien.",
        );

        self::assertSame([], $this->lire('ES', VatRateCategory::Standard));

        // Écarter en silence donnerait un référentiel où l'Espagne manque sans que personne
        // ne l'apprenne — vingt-huit pays au lieu de vingt-neuf, et rien pour le dire.
        self::assertStringContainsString('ES standard : 7.00 / 21.00', $affichage);
    }

    /**
     * **Un taux qui contredit une ligne en place n'est pas écrit — il est dit.**
     *
     * C'est soit un changement de taux réel, soit une erreur de l'une des deux sources. Les deux
     * méritent d'être vues, aucune ne se tranche par un import.
     */
    public function testUnTauxDivergentEstSignaleEtNonEcrit(): void
    {
        $this->semer('DE', VatRateCategory::Standard, '19.00', 'Jeu de test');

        $affichage = $this->importer([
            $this->bloc('DE', 'STANDARD', [21.0]),
            $this->bloc('IT', 'STANDARD', [22.0]),
        ]);

        self::assertNotEmpty(
            $this->lire('IT', VatRateCategory::Standard),
            "L'import n'a rien écrit du tout : le reste de ce test ne mesure rien.",
        );

        $de = $this->lire('DE', VatRateCategory::Standard);
        self::assertCount(1, $de);
        self::assertSame('19.00', number_format((float) $de[0]->getRate(), 2, '.', ''));
        self::assertStringContainsString('DE standard : en base 19.00, TEDB 21.00', $affichage);
    }

    /**
     * **Les taux réduits ne sont pas importés, et ce silence est compté.**
     *
     * TEDB donne jusqu'à six taux réduits pour un pays sans dire lequel est le second réduit, le
     * super réduit ou le taux parking. Notre énumération distingue ces cases ; l'export, non. Les
     * ranger par ordre décroissant appliquerait la convention **française** (10 %, 5,5 %, 2,1 %) à
     * l'Autriche.
     *
     * Le risque ici n'est pas de mal ranger : c'est de livrer un référentiel de vingt-neuf pays,
     * d'apparence complète, dont personne ne sait qu'il n'a aucun taux réduit.
     */
    public function testLesTauxReduitsSontEcartesEtComptes(): void
    {
        $affichage = $this->importer([
            $this->bloc('AT', 'STANDARD', [20.0]),
            $this->bloc('AT', 'REDUCED', [13.0, 10.0, 5.0]),
        ]);

        self::assertNotEmpty(
            $this->lire('AT', VatRateCategory::Standard),
            "L'import n'a rien écrit du tout : l'absence des taux réduits ne prouve rien.",
        );

        self::assertSame([], $this->lire('AT', VatRateCategory::Reduced));
        self::assertStringContainsString('3 taux REDUITS ecartes', $affichage);
    }

    /**
     * **Un export tronqué est refusé, il n'est pas importé à moitié.**
     *
     * Sans ce plancher, une réponse partielle rendrait « 3 taux importés » — un succès, en vert, sur
     * un référentiel amputé de vingt-six pays.
     */
    public function testUnExportTropCourtEstRefuse(): void
    {
        $blocs = [$this->bloc('DE', 'STANDARD', [19.0])];
        $chemin = $this->ecrire($blocs);

        $testeur = new CommandTester($this->commande());
        $code = $testeur->execute(['fichier' => $chemin]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('inexploitable', $testeur->getDisplay());
        self::assertSame([], $this->lire('DE', VatRateCategory::Standard));
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /**
     * Exécute l'import sur les blocs donnés, complétés jusqu'au plancher de vingt entrées.
     *
     * Le remplissage utilise des codes pays hors Union (`Z1`…) pour qu'il ne puisse jamais entrer en
     * collision avec ce qu'un test observe.
     *
     * @param list<array<string, mixed>> $blocs
     */
    private function importer(array $blocs): string
    {
        $remplissage = ['W0', 'W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'V0', 'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8', 'V9', 'U0', 'U1'];

        // ⚠ DEUX CARACTERES, PAS TROIS. La colonne `country` est un VARCHAR(2) : mon premier
        // remplissage tenait sur trois signes et l'import entier est mort en SQLSTATE[22001], au
        // milieu du flush, sans qu'aucune assertion n'ait pu s'exprimer.
        foreach ($remplissage as $i => $code) {
            if (\count($blocs) >= 22) {
                break;
            }
            $blocs[] = $this->bloc($code, 'STANDARD', [10.0 + $i]);
        }

        $testeur = new CommandTester($this->commande());
        $testeur->execute(['fichier' => $this->ecrire($blocs)]);

        return $testeur->getDisplay();
    }

    private function commande(): ImportTedbVatRatesCommand
    {
        $commande = new ImportTedbVatRatesCommand($this->em());
        $commande->setName('vat:import-tedb');

        return $commande;
    }

    /** @param list<array<string, mixed>> $blocs */
    private function ecrire(array $blocs): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'tedb') . '.json';
        file_put_contents($chemin, json_encode(['result' => $blocs], \JSON_THROW_ON_ERROR));

        return $chemin;
    }

    /**
     * Reproduit la forme réelle de l'export : un bloc par (pays, type), une entrée par taux.
     *
     * @param list<float> $valeurs
     *
     * @return array<string, mixed>
     */
    private function bloc(string $pays, string $type, array $valeurs): array
    {
        return [
            'isoCode' => $pays,
            'countryName' => $pays,
            'type' => $type,
            'rates' => array_map(
                static fn (float $v): array => ['value' => $v, 'situationOn' => '2026/07/01'],
                $valeurs,
            ),
        ];
    }

    private function semer(string $pays, VatRateCategory $categorie, string $taux, string $source): void
    {
        $em = $this->em();
        $em->persist(
            (new LegalVatRate())
                ->setCountry($pays)
                ->setCategory($categorie)
                ->setRate($taux)
                ->setLabel('Taux d epreuve')
                ->setValidFrom(new \DateTimeImmutable('2014-01-01'))
                ->setSource($source)
        );
        $em->flush();
    }

    /** @return list<LegalVatRate> */
    private function lire(string $pays, VatRateCategory $categorie): array
    {
        $em = $this->em();
        $em->clear();

        return array_values($em->getRepository(LegalVatRate::class)->findBy([
            'country' => $pays,
            'category' => $categorie,
        ]));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
