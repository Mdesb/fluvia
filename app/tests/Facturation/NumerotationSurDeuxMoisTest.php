<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutPeriode;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\GenerateurNumeroFacture;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DEUX MOIS DE LA MEME ANNEE PARTAGENT UNE SEULE SEQUENCE DE NUMEROS.
 *
 * ── LE DEFAUT QUE CE TEST EPINGLE, MESURE LE 14/09/2026 ─────────────────────────────────────────
 *
 * `PeriodeComptableResolver` cree une periode par MOIS. Le compteur de `SerieNumerotation` etait
 * verrouille sur `(profil, periode, prefixe)` — donc sur le mois — alors que le numero ne porte que
 * l'ANNEE (`FA-2026-00001`) et que `uniq_facture_numero` est unique sur ce numero seul.
 *
 * La premiere facture de chaque nouveau mois reprenait donc le numero de la premiere du mois
 * precedent, et la contrainte la rejetait :
 *
 *     Duplicate entry 'FA-2026-00001' for key 'uniq_facture_numero'
 *
 * Autrement dit : plus aucune facture emettable a partir du deuxieme mois, pour tout exploitant.
 * Sur la preproduction, aucune facture de septembre ne pouvait sortir, celle d'aout portant deja
 * `FA-2026-00001`.
 *
 * ── POURQUOI AUCUN TEST NE L'AVAIT VU ───────────────────────────────────────────────────────────
 *
 * ⚠ `NumerotationFactureTest` emet VINGT factures et verifie que la sequence n'a ni trou ni
 * doublon. Vingt, c'est beaucoup — mais toutes le meme jour, donc toutes dans la meme periode. Le
 * cas qui demasque n'est pas le VOLUME, c'est le FRANCHISSEMENT : deux periodes, une annee. Aucun
 * nombre d'emissions dans un seul mois n'aurait pu le reveler.
 *
 * ── CE QU'IL N'EPROUVE PAS ──────────────────────────────────────────────────────────────────────
 *
 * Il ne passe pas par l'API : `EmettreFactureDirecteHandler` resout la periode depuis
 * `new \DateTimeImmutable()`, donc une emission HTTP tombe toujours dans le mois courant. Le
 * franchissement ne s'atteint qu'en s'adressant au generateur, ce qui est precisement l'organe
 * concerne.
 */
final class NumerotationSurDeuxMoisTest extends FacturationApiTestCase
{
    /**
     * ⚠ UNE ANNEE LOINTAINE, VOLONTAIREMENT. Les jeux d'essai posent des periodes autour du mois
     * courant ; s'y greffer ferait dependre le resultat de la date d'execution, et un test qui passe
     * en septembre et tombe en janvier est pire qu'un test absent.
     */
    private const EXERCICE = 2031;

    public function testDeuxMoisDeLaMemeAnneeNeRedonnentPasLeMemeNumero(): void
    {
        self::bootKernel();
        $em = $this->em();
        $profil = $this->profil($em);

        $janvier = $this->periode($em, $profil, self::EXERCICE . '-01-01', self::EXERCICE . '-01-31');
        $fevrier = $this->periode($em, $profil, self::EXERCICE . '-02-01', self::EXERCICE . '-02-28');
        $em->flush();

        $premier = $this->numeroter($em, $profil, $janvier);
        $second = $this->numeroter($em, $profil, $fevrier);

        self::assertSame('FA-' . self::EXERCICE . '-00001', $premier, 'la premiere facture de l annee porte le numero 1');
        self::assertSame(
            'FA-' . self::EXERCICE . '-00002',
            $second,
            'la premiere facture de FEVRIER continue la sequence de janvier — elle ne la recommence pas',
        );
        self::assertNotSame($premier, $second, 'deux mois de la meme annee ne peuvent pas rendre le meme numero');
    }

    /**
     * ⚠ ET L'ANNEE SUIVANTE REPART BIEN A 1. Sans cette seconde assertion, un compteur devenu
     * simplement GLOBAL — jamais remis a zero — passerait le test precedent en cassant la
     * numerotation annuelle que le format `FA-AAAA-NNNNN` promet.
     */
    public function testUneNouvelleAnneeRecommenceAUn(): void
    {
        self::bootKernel();
        $em = $this->em();
        $profil = $this->profil($em);

        $decembre = $this->periode($em, $profil, self::EXERCICE . '-12-01', self::EXERCICE . '-12-31');
        $janvierSuivant = $this->periode($em, $profil, (self::EXERCICE + 1) . '-01-01', (self::EXERCICE + 1) . '-01-31');
        $em->flush();

        $this->numeroter($em, $profil, $decembre);
        $premierDeLAnneeSuivante = $this->numeroter($em, $profil, $janvierSuivant);

        self::assertSame(
            'FA-' . (self::EXERCICE + 1) . '-00001',
            $premierDeLAnneeSuivante,
            'le compteur se remet a zero au changement d annee, comme le format du numero le promet',
        );
    }

    /**
     * Numerote une facture comme l'emission le fait : DANS une transaction.
     *
     * ⚠ CE N'EST PAS UN DETAIL DE HARNAIS. `serieVerrouillee()` pose un `LockMode::PESSIMISTIC_WRITE`
     * sur la ligne de compteur, et Doctrine exige une transaction ouverte pour cela — `attribuer()`
     * hors transaction leve `TransactionRequiredException`. Le verrou est ce qui empeche deux
     * emissions simultanees de rendre le meme numero : le test doit donc l'exercer, pas le
     * contourner.
     */
    private function numeroter(EntityManagerInterface $em, ProfilExploitant $profil, PeriodeComptable $periode): string
    {
        return $em->wrapInTransaction(
            fn (): string => $this->generateur()->attribuer($this->facture($profil, $periode)),
        );
    }

    private function facture(ProfilExploitant $profil, PeriodeComptable $periode): Facture
    {
        $facture = new Facture();
        $facture->setProfilExploitant($profil);
        $facture->setPeriode($periode);

        return $facture;
    }

    private function periode(EntityManagerInterface $em, ProfilExploitant $profil, string $debut, string $fin): PeriodeComptable
    {
        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($profil);
        $periode->setDateDebut(new \DateTimeImmutable($debut));
        $periode->setDateFin(new \DateTimeImmutable($fin));
        $periode->setStatut(StatutPeriode::Ouverte);
        $em->persist($periode);

        return $periode;
    }

    private function profil(EntityManagerInterface $em): ProfilExploitant
    {
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy([]);
        self::assertInstanceOf(ProfilExploitant::class, $profil, 'Les jeux d essai doivent porter un profil exploitant.');

        return $profil;
    }

    private function generateur(): GenerateurNumeroFacture
    {
        /** @var GenerateurNumeroFacture $generateur */
        $generateur = static::getContainer()->get(GenerateurNumeroFacture::class);

        return $generateur;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
