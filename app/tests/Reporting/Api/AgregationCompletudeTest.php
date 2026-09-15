<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Acces\Entity\Controleur;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\DataFixtures\L11Fixtures;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\StatutCompletude;
use App\Tests\Reporting\ReportingApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN SITE SANS INSTRUMENT NE REND PAS « COMPLET 0 » (arbitrage n°7 du 15/09/2026).
 *
 * `etablissementHorsLigne()` rend `false` dans deux cas opposés — « les contrôleurs répondent » et
 * « il n'y a aucun contrôleur » — et l'agrégateur en tirait `complet`. Mesuré sur la préproduction
 * après le rattrapage du 15/09 : 77 mesures `complet` à 0,00 sur sept sites sans contrôleur, dont
 * un musée et une patinoire.
 *
 * ⚠ CE TEST FAIT VARIER UNE SEULE CHOSE : la présence des contrôleurs sur LE MÊME site. Comparer
 * deux sites différents mélangerait la présence de contrôleurs et leurs données.
 *
 * ⚠ ET IL VÉRIFIE CE QUI NE DOIT PAS BOUGER. Le nouvel état ne concerne que les indicateurs dont
 * `sourceModule` vaut `acces` : le chiffre d'affaires, de source `vente`, doit garder son statut.
 * Sans ce témoin, un état qui avalerait tout passerait pour un succès.
 */
final class AgregationCompletudeTest extends ReportingApiTestCase
{
    public function testUnSiteInstrumenteGardeSonStatut(): void
    {
        $this->agreger();

        $statutFrequentation = $this->statutDe(L11Fixtures::SITE_A1_NOM, 'FREQUENTATION_CUMULEE');
        self::assertNotSame(
            StatutCompletude::NonInstrumente,
            $statutFrequentation,
            'un site qui PORTE des contrôleurs ne doit jamais être dit « non instrumenté » : '
            . 'sa fréquentation est mesurée, même si elle vaut zéro',
        );
        self::assertContains(
            $statutFrequentation,
            [StatutCompletude::Complet, StatutCompletude::Partiel],
            'le statut d\'un site instrumenté reste l\'un des deux états d\'origine',
        );
    }

    public function testUnSiteSansControleurBasculeEnNonInstrumente(): void
    {
        // AUCUN SITE DES FIXTURES N'EST DÉPOURVU DE CONTRÔLEUR — mesuré sur la base de test : trois
        // sites, trois contrôleurs. Le test fabrique donc le sien.
        //
        // ⚠ ET IL NE DÉSÉQUIPE PAS UN SITE EXISTANT. Ma première version supprimait les contrôleurs,
        // ce qui exige de supprimer leurs passages — et le produit refuse :
        // `PassageInalterableException : le passage est append-only (CA-14, RG-SOCLE-07)`. C'est un
        // garde d'inaltérabilité, il a raison, et on ne le contourne pas pour faire passer un test.
        $em = $this->em();
        $region = $this->entite(Region::class, ['nom' => L11Fixtures::REGION_A_NOM]);
        $site = (new Etablissement())->setNom('Site sans instrument (test)');
        $site->setRegion($region);
        $em->persist($site);
        $em->flush();
        $em->clear();

        self::assertSame(
            0,
            $this->em()->getRepository(Controleur::class)
                ->count(['etablissement' => $this->entite(Etablissement::class, ['nom' => 'Site sans instrument (test)'])]),
            'précondition : ce site neuf ne porte aucun contrôleur',
        );

        $this->agreger();

        // LE TÉMOIN POSITIF : source `acces`, pas d'instrument → ni complet, ni partiel.
        self::assertSame(
            StatutCompletude::NonInstrumente,
            $this->statutDe('Site sans instrument (test)', 'FREQUENTATION_CUMULEE'),
            'sans aucun contrôleur, la fréquentation n\'est pas une mesure complète à zéro',
        );

        // ⚠ LE TÉMOIN NÉGATIF, SUR LE MÊME SITE — c'est lui qui isole la règle. `CA` a pour source
        // `vente`, pas `acces` : le nouvel état ne doit pas le toucher. Un état qui avalerait tous
        // les indicateurs passerait le témoin positif sans que personne ne le voie.
        self::assertNotSame(
            StatutCompletude::NonInstrumente,
            $this->statutDe('Site sans instrument (test)', 'CA'),
            'le nouvel état ne concerne que les indicateurs de source `acces` : le chiffre '
            . 'd\'affaires d\'un site sans tourniquet reste une mesure ordinaire',
        );
    }

    public function testUnAgregatDontUnSiteNestPasInstrumenteEstPartielEtLeNomme(): void
    {
        // Région A Reporting porte déjà deux sites équipés. On lui en ajoute un sans contrôleur :
        // l'agrégat régional couvre donc une portée dont une partie n'est pas mesurable.
        $site = $this->creerSiteSansControleur('Site sans instrument (région)', L11Fixtures::REGION_A_NOM);
        $idSite = $site->getId()->toRfc4122();

        $this->agreger();

        $mesure = $this->mesureDe(
            NiveauEntite::Region,
            'FREQUENTATION_CUMULEE',
            ['region' => $this->entite(Region::class, ['nom' => L11Fixtures::REGION_A_NOM])],
        );

        // ⚠ C'EST LE CAS QUE LA PREMIÈRE VERSION LAISSAIT PASSER : le parent ressortait `complet`
        // avec un total qui OMET le site sans source, puisque sa valeur est zéro.
        self::assertSame(
            StatutCompletude::Partiel,
            $mesure->getStatutCompletude(),
            'un agrégat dont une partie de la portée n\'est pas instrumentée n\'est pas complet',
        );
        self::assertContains(
            $idSite,
            $mesure->getSitesManquants() ?? [],
            'le site sans instrument doit être NOMMÉ : un total partiel qui ne dit pas ce qui '
            . 'manque est un nombre faux qui a l\'air juste',
        );
    }

    public function testUnAgregatEntierementNonInstrumenteNestPasPartielMaisNonInstrumente(): void
    {
        // Une région neuve, dont le SEUL site n'a aucun contrôleur : rien n'y est mesurable.
        $groupe = $this->entite(Groupe::class, ['nom' => L11Fixtures::GROUPE_NOM]);
        $region = (new Region())->setNom('Région sans instrument (test)');
        $region->setGroupe($groupe);
        $em = $this->em();
        $em->persist($region);
        $em->flush();
        $em->clear();

        $this->creerSiteSansControleur('Site unique sans instrument', 'Région sans instrument (test)');

        $this->agreger();

        // ⚠ NI COMPLET NI PARTIEL. « Partiel » supposerait qu'une partie a répondu ; ici rien
        // n'était attendu, donc rien ne manque.
        self::assertSame(
            StatutCompletude::NonInstrumente,
            $this->mesureDe(
                NiveauEntite::Region,
                'FREQUENTATION_CUMULEE',
                ['region' => $this->entite(Region::class, ['nom' => 'Région sans instrument (test)'])],
            )->getStatutCompletude(),
            'une région dont AUCUN site n\'est instrumenté n\'est pas partielle : elle n\'est '
            . 'pas instrumentée',
        );
    }

    private function creerSiteSansControleur(string $nom, string $nomRegion): Etablissement
    {
        $em = $this->em();
        $site = (new Etablissement())->setNom($nom);
        $site->setRegion($this->entite(Region::class, ['nom' => $nomRegion]));
        $em->persist($site);
        $em->flush();
        $em->clear();

        $relu = $this->entite(Etablissement::class, ['nom' => $nom]);
        self::assertSame(
            0,
            $this->em()->getRepository(Controleur::class)->count(['etablissement' => $relu]),
            'précondition : ce site neuf ne porte aucun contrôleur',
        );

        return $relu;
    }

    /** @param array<string, mixed> $criteres */
    private function mesureDe(NiveauEntite $niveau, string $codeIndicateur, array $criteres): Mesure
    {
        $mesure = $this->em()->getRepository(Mesure::class)->findOneBy($criteres + [
            'indicateur' => $this->entite(Indicateur::class, ['code' => $codeIndicateur]),
            'niveau' => $niveau,
        ]);
        self::assertNotNull(
            $mesure,
            sprintf('aucune mesure %s au niveau %s — le test ne mesure rien', $codeIndicateur, $niveau->value),
        );

        return $mesure;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function statutDe(string $nomSite, string $codeIndicateur): StatutCompletude
    {
        $site = $this->entite(Etablissement::class, ['nom' => $nomSite]);
        $indicateur = $this->entite(Indicateur::class, ['code' => $codeIndicateur]);
        $mesure = $this->em()->getRepository(Mesure::class)->findOneBy([
            'indicateur' => $indicateur,
            'niveau' => NiveauEntite::Etablissement,
            'etablissement' => $site,
        ]);
        self::assertNotNull(
            $mesure,
            sprintf('aucune mesure %s pour %s — le test ne mesure rien', $codeIndicateur, $nomSite),
        );

        return $mesure->getStatutCompletude();
    }
}
