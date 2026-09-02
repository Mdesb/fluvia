<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\LegalVatRate;
use App\Compta\Enum\VatRateCategory;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE TERRITOIRE FISCAL — un code pays ne suffit pas à désigner un régime.
 *
 * Révélé par une violation de contrainte à l'import TEDB du 02/09 : l'Espagne rend `7,00` ET
 * `21,00` à la même date. Ce ne sont pas deux versions du même taux — ce sont les Canaries et la
 * péninsule, deux régimes sous un seul `ES`. Le référentiel n'ayant qu'une place par (pays,
 * catégorie, date), **l'Espagne n'a pas pu être importée du tout** : son catalogue était vide.
 *
 * Et le cas nous concerne directement : un exploitant français en Guadeloupe est en `FR`, et il ne
 * facture pas à 20 %.
 */
final class TerritoireFiscalTest extends ComptaApiTestCase
{
    /**
     * ⚠ LE CAS QUI DÉMASQUE TOUT LE RESTE : le droit commun n'a pas bougé.
     *
     * Les six autres tests portent sur des territoires. Si l'ajout du territoire avait cassé la
     * lecture ordinaire — celle de 99 % des établissements — aucun d'eux ne le dirait.
     */
    public function testUnEtablissementDeDroitCommunVoitLeBaremeDuPays(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->semer('FR', '', VatRateCategory::Standard, '20.00');
        $this->semer('FR', '', VatRateCategory::SecondReduced, '5.50');

        $taux = $this->catalogue($client, $entete);

        self::assertSame('', $this->territoireRendu($client));
        self::assertContains('20.00', $taux);
        self::assertContains('5.50', $taux);
    }

    /**
     * **LE TEST QUI COMPTE — et il garde un défaut que j'avais réellement écrit.**
     *
     * Ma première règle fusionnait catégorie par catégorie : le territoire écrasait ce qu'il
     * redéfinit, le droit commun tenait le reste. Ça paraissait plus fin. Appliqué aux DOM, où la
     * TVA ne connaît que DEUX taux — 8,5 % et 2,1 % (CGI art. 296) — ça faisait apparaître le
     * 5,5 % métropolitain dans un catalogue guadeloupéen, sous le libellé « produits alimentaires,
     * livres ». Un taux qui n'existe pas là-bas, proposé à la saisie, dans une liste d'apparence
     * complète.
     *
     * L'assertion d'ABSENCE est donc le cœur de ce test, et elle est encadrée par les deux
     * présences — sans quoi un catalogue vide la satisferait aussi.
     */
    public function testUnEtablissementOutreMerNeVoitPasLesTauxMetropolitains(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->semer('FR', '', VatRateCategory::Standard, '20.00');
        $this->semer('FR', '', VatRateCategory::SecondReduced, '5.50');
        $this->semer('FR', 'DOM', VatRateCategory::Standard, '8.50');
        $this->semer('FR', 'DOM', VatRateCategory::Reduced, '2.10');

        $this->etablissementActif()->setFiscalTerritory('DOM');
        $this->em()->flush();

        $taux = $this->catalogue($client, $entete);

        self::assertContains('8.50', $taux, 'Le taux ultramarin est absent : le territoire n est pas lu.');
        self::assertContains('2.10', $taux);
        self::assertNotContains('5.50', $taux, 'Le 5,5 % métropolitain n existe pas dans les DOM.');
        self::assertNotContains('20.00', $taux);
    }

    /**
     * L'Espagne enfin servie — et l'IGIC nommé pour ce qu'il est.
     *
     * Les Canaries sont hors du territoire TVA de l'Union : l'impôt qui s'y applique est l'IGIC, un
     * impôt distinct. Le libellé doit le dire, sinon une facture canarienne porterait une mention
     * de TVA qui n'a pas lieu d'être.
     */
    public function testLesCanariesRendentLIgicEtPasLaTvaPeninsulaire(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->semer('ES', '', VatRateCategory::Standard, '21.00');
        $this->semer('ES', 'IC', VatRateCategory::Standard, '7.00', 'IGIC tipo general — Canaries (impot distinct de la TVA)');

        $peninsule = $this->catalogue($client, $entete, '?country=ES');
        self::assertContains('21.00', $peninsule);
        self::assertNotContains('7.00', $peninsule);

        $canaries = $this->catalogue($client, $entete, '?country=ES&territory=IC');
        self::assertContains('7.00', $canaries);
        self::assertNotContains('21.00', $canaries);

        $lignes = $this->lignes($client, $entete, '?country=ES&territory=IC');
        self::assertStringContainsString('IGIC', (string) $lignes[0]['label']);
        self::assertSame('IC', $lignes[0]['territory']);
    }

    /**
     * Un territoire sans taux propres retombe sur le droit commun du pays.
     *
     * C'est le comportement voulu — l'immense majorité des subdivisions n'ont pas de régime propre
     * — mais il a un revers qu'il faut connaître : un territoire dont on a OUBLIÉ de semer les taux
     * est indiscernable d'un territoire qui n'en a pas. Le champ `territory` de chaque ligne rendue
     * est ce qui permet de faire la différence côté écran, et c'est pourquoi il est rendu.
     */
    public function testUnTerritoireSansTauxPropresRetombeSurLeDroitCommun(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->semer('FR', '', VatRateCategory::Standard, '20.00');

        $lignes = $this->lignes($client, $entete, '?territory=CORSE');

        self::assertSame('20.00', $lignes[0]['rate']);
        self::assertSame('', $lignes[0]['territory'], 'La ligne doit s annoncer comme nationale, pas comme corse.');
    }

    /**
     * Un territoire mal écrit est REFUSÉ, pas normalisé en silence.
     *
     * « canaries » rendrait le catalogue de droit commun, c'est-à-dire les taux de la péninsule sur
     * une facture des Canaries, sans qu'aucun message ne le dise. Un 422 dit ce qui ne va pas.
     */
    public function testUnTerritoireMalEcritEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/compta/vat-rate-catalog?territory=canaries!', $entete);

        self::assertResponseStatusCodeSame(422);
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<string> les taux rendus, du plus élevé au plus bas
     */
    private function catalogue(object $client, array $entete, string $requete = ''): array
    {
        return array_map(
            static fn (array $l): string => (string) $l['rate'],
            $this->lignes($client, $entete, $requete),
        );
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(object $client, array $entete, string $requete = ''): array
    {
        $client->request('GET', '/api/compta/vat-rate-catalog' . $requete, $entete);
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $rates */
        $rates = $client->getResponse()->toArray()['rates'];

        return $rates;
    }

    private function territoireRendu(object $client): string
    {
        return (string) $client->getResponse()->toArray()['territory'];
    }

    private function etablissementActif(): Etablissement
    {
        /** @var Etablissement $etab */
        $etab = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        return $etab;
    }

    private function semer(string $pays, string $territoire, VatRateCategory $categorie, string $taux, string $libelle = 'Taux d epreuve'): void
    {
        $em = $this->em();
        $em->persist(
            (new LegalVatRate())
                ->setCountry($pays)
                ->setTerritory($territoire)
                ->setCategory($categorie)
                ->setRate($taux)
                ->setLabel($libelle)
                ->setValidFrom(new \DateTimeImmutable('2014-01-01'))
                ->setSource('Jeu de test')
        );
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
