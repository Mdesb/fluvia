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
 * LE PRIX D'UN ABONNEMENT VIENT DU TARIF, ET LE PRORATA NE PEUT QU'EN RETRANCHER (T33-2 / T33-3).
 *
 * ── L'ARBITRAGE QUE CES TESTS TIENNENT ────────────────────────────────────────────────────────
 *
 * Maxime, 01/09 : **« il ne doit pas y avoir de prix libre. »** Le guichet recevait
 * `montantCentimes` en champ libre et strictement positif ; il resout desormais depuis la grille
 * tarifaire, comme la boutique le fait depuis toujours.
 *
 * Ces tests portent donc DEUX choses qui se defont l'une l'autre si on n'en garde qu'une :
 *
 *   · le prix vient bien du tarif             -- sinon l'arbitrage n'est pas applique
 *   · un montant envoye est REFUSE, pas ignore -- sinon un appelant croit fixer le prix, le tarif
 *                                                 s'applique a sa place, et rien ne le dit
 *
 * ── ⚠ POURQUOI LE SECOND EST LE PLUS IMPORTANT ───────────────────────────────────────────────
 *
 * Ignorer un champ devenu inutile est le defaut le plus silencieux qui soit : l'appel reussit,
 * l'abonnement se cree, et le montant n'est pas celui qu'on croyait avoir demande. Sur un
 * prelevement mensuel, l'ecart se decouvre au releve bancaire.
 */
final class TarifAbonnementTest extends SportApiTestCase
{
    private const IBAN = 'FR7630006000011234567890189';

    /** Le tarif « Plein tarif » de l'abonnement Gold, pose par `OffreFixtures`. */
    private const TARIF_GOLD_CENTIMES = 3990;

    public function testLePrixVientDuTarifEtNonDeLAppelant(): void
    {
        $abonnement = $this->souscrire(null);

        self::assertSame(self::TARIF_GOLD_CENTIMES, $abonnement->getMontantCentimes());
        self::assertSame(
            [self::TARIF_GOLD_CENTIMES, self::TARIF_GOLD_CENTIMES, self::TARIF_GOLD_CENTIMES],
            $this->montantsDesEcheances($abonnement),
        );
    }

    /**
     * ⚠ REFUSE, PAS IGNORE. Si le champ etait simplement laisse de cote, un appelant continuerait
     *    de l'envoyer en croyant fixer le prix.
     */
    public function testUnMontantEnvoyeEstRefuseEtNonIgnore(): void
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $this->requeteSouscription($client, $entete, ['montantCentimes' => 9900]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('montantCentimes', $reponse);
        self::assertStringContainsString('grille', $reponse, 'Le message doit dire OU le prix est desormais pris.');
    }

    /**
     * ⚠ LES DEUX ASSERTIONS NE VALENT QU'ENSEMBLE : la premiere seule passerait si l'on posait le
     *    prorata partout, la seconde seule s'il etait ignore.
     */
    public function testLaPremiereEcheanceSeuleTombeAuProrata(): void
    {
        $abonnement = $this->souscrire(1995);

        self::assertSame(
            [1995, self::TARIF_GOLD_CENTIMES, self::TARIF_GOLD_CENTIMES],
            $this->montantsDesEcheances($abonnement),
        );
        self::assertSame(
            self::TARIF_GOLD_CENTIMES,
            $abonnement->getMontantCentimes(),
            'L abonnement porte ce qu on facturera la prochaine fois, pas le demi-mois d entree.',
        );
    }

    /**
     * ⚠ ZERO EST UNE VALEUR, PAS UNE ABSENCE. Ecrit `?:` au lieu de `!== null`, le generateur
     *    remplacerait ce 0 par le tarif plein et facturerait un mois annonce gratuit.
     */
    public function testUnPremierMoisOffertEstUnProrataDeZeroEtNonUneAbsence(): void
    {
        $abonnement = $this->souscrire(0);

        self::assertSame([0, self::TARIF_GOLD_CENTIMES, self::TARIF_GOLD_CENTIMES], $this->montantsDesEcheances($abonnement));
        self::assertSame(self::TARIF_GOLD_CENTIMES, $abonnement->getMontantCentimes());
    }

    /**
     * ⚠ LA BORNE QUI EMPECHE LE PRORATA DE REDEVENIR UN PRIX LIBRE. Sans elle, il suffirait
     *    d'appeler « prorata » un montant superieur au tarif pour contourner l'arbitrage.
     */
    public function testUnProrataSuperieurAuTarifEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $this->requeteSouscription($client, $entete, [
            'montantPremiereEcheanceCentimes' => self::TARIF_GOLD_CENTIMES + 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('retranche', $reponse);
    }

    public function testUnProrataNegatifEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $this->requeteSouscription($client, $entete, ['montantPremiereEcheanceCentimes' => -100]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('montantPremiereEcheanceCentimes', $reponse);
    }

    // ── outillage ────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $enPlus */
    private function requeteSouscription(object $client, array $entete, array $enPlus): string
    {
        $modele = $this->abonnementDemo();
        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + ['json' => [
            // adherent omis : le payeur est l'adhérent (sans effet sur le tarif mesuré ici).
            'payeur' => (string) $modele->getPayeur()->getId(),
            'formule' => (string) $modele->getFormuleId(),
            'dureeEngagementMois' => 3,
            'iban' => self::IBAN,
            'titulaireMandat' => 'Essai tarif',
        ] + $enPlus]);

        return (string) $client->getResponse()->getContent(false);
    }

    private function souscrire(?int $prorata): AbonnementFitness
    {
        $modele = $this->abonnementDemo();

        /** @var SouscriptionAbonnementHandler $handler */
        $handler = static::getContainer()->get(SouscriptionAbonnementHandler::class);

        return $handler->souscrire(
            $modele->getAdherent(),
            $modele->getPayeur(),
            $modele->getFormule(),
            $modele->getEtablissement(),
            new \DateTimeImmutable('2026-01-01'),
            3,
            self::IBAN,
            'Essai tarif',
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
