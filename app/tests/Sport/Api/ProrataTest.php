<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Service\SouscriptionAbonnementHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE MONTANT COURANT DE L'ABONNEMENT, ET LE PRORATA D'ENTREE (T33-2).
 *
 * ── CE QUE LA MESURE A CORRIGE AVANT D'ECRIRE CES TESTS ───────────────────────────────────────
 *
 * Le lot annoncait << aucun montant sur l'abonnement, le prix est relu du tarif produit a chaque
 * echeance >>. Faux : `SouscrireAbonnementProcessor` EXIGE un `montantCentimes` du corps, et
 * `GenerateurEcheancierHandler` l'estampille sur chaque echeance sans jamais le relire. Le montant
 * libre existait deja ; ce qui manquait etait la MEMOIRE (l'abonnement ne le gardait pas) et le
 * PRORATA (toutes les echeances portaient le meme montant).
 *
 * ── REGLE DE PRESEANCE EPROUVEE ICI : L'ECHEANCE FAIT FOI ─────────────────────────────────────
 *
 * L'abonnement ne porte que le montant COURANT. `testProrataNeContaminePasLeMontantCourant` est le
 * test qui tient cette regle : sans lui, poser le prorata sur l'abonnement passerait inapercu et
 * l'abonnement afficherait le prix d'un cas particulier pour toute sa duree.
 */
final class ProrataTest extends SportApiTestCase
{
    private const IBAN = 'FR7630006000011234567890189';

    public function testLAbonnementGardeSonMontantCourant(): void
    {
        $abonnement = $this->souscrire(4500, null);

        self::assertSame(4500, $abonnement->getMontantCentimes());
        self::assertSame([4500, 4500, 4500], $this->montantsDesEcheances($abonnement));
    }

    /**
     * ⚠ LES DEUX ASSERTIONS NE VALENT QU'ENSEMBLE. La premiere seule passerait si l'on posait le
     *    prorata partout ; la seconde seule passerait si on l'ignorait completement.
     */
    public function testLaPremiereEcheanceSeuleTombeAuProrata(): void
    {
        $abonnement = $this->souscrire(4500, 2250);

        self::assertSame(
            [2250, 4500, 4500],
            $this->montantsDesEcheances($abonnement),
            'Le demi-mois d entree ne concerne que la premiere echeance.',
        );
    }

    public function testProrataNeContaminePasLeMontantCourant(): void
    {
        $abonnement = $this->souscrire(4500, 2250);

        self::assertSame(
            4500,
            $abonnement->getMontantCentimes(),
            'L abonnement porte ce qu on facturera la prochaine fois, pas le demi-mois d entree.',
        );
    }

    /**
     * ⚠ ZERO EST UNE VALEUR, PAS UNE ABSENCE : le premier mois offert est une pratique courante.
     *    Ecrit `?: $montantCentimes` au lieu de `!== null`, le generateur remplacerait ce 0 par le
     *    montant plein et facturerait un mois annonce gratuit.
     */
    public function testUnPremierMoisOffertEstUnProrataDeZeroEtNonUneAbsence(): void
    {
        $abonnement = $this->souscrire(4500, 0);

        self::assertSame([0, 4500, 4500], $this->montantsDesEcheances($abonnement));
        self::assertSame(4500, $abonnement->getMontantCentimes());
    }

    public function testUnProrataNegatifEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $modele = $this->abonnementDemo();

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + ['json' => [
            'adherent' => (string) $modele->getAdherent()->getId(),
            'payeur' => (string) $modele->getPayeur()->getId(),
            'formule' => (string) $modele->getFormule()->getId(),
            'periodicite' => 'mensuel',
            'dureeEngagementMois' => 3,
            'montantCentimes' => 4500,
            'montantPremiereEcheanceCentimes' => -100,
            'iban' => self::IBAN,
            'titulaireMandat' => 'Essai prorata',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('montantPremiereEcheanceCentimes', $client->getResponse()->getContent(false));
    }

    private function souscrire(int $montantCentimes, ?int $prorata): AbonnementFitness
    {
        $modele = $this->abonnementDemo();

        /** @var SouscriptionAbonnementHandler $handler */
        $handler = static::getContainer()->get(SouscriptionAbonnementHandler::class);

        return $handler->souscrire(
            $modele->getAdherent(),
            $modele->getPayeur(),
            $modele->getFormule(),
            $modele->getEtablissement(),
            PeriodiciteAbonnementFitness::Mensuel,
            new \DateTimeImmutable('2026-01-01'),
            3,
            $montantCentimes,
            self::IBAN,
            'Essai prorata',
            $prorata,
        );
    }

    /** @return list<int> les montants des echeances, dans l'ordre des dates */
    private function montantsDesEcheances(AbonnementFitness $abonnement): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $echeances = $em->getRepository(EcheanceSepa::class)
            ->findBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        self::assertNotEmpty($echeances, 'Aucune echeance generee : le test ne mesure rien.');

        return array_map(static fn (EcheanceSepa $e): int => $e->getMontantCentimes(), $echeances);
    }
}
