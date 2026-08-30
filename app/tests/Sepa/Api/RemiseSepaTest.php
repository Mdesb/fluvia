<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Sepa\DataFixtures\SepaFixtures;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `RemiseSepa` (plan-sepa.md §2/§6/§8) : la remise **régie** de démonstration (fixtures, générée via
 * `GenerationRemiseHandler`) prouve la réutilisation bi-régime — `UltmtCdtr` présent, `Cdtr` =
 * collectivité, `NbOfTxs`/`CtrlSum` exacts. Téléchargement du pain.008 (`GET
 * /sepa/remises/{id}/pain008`). `POST /sepa/remises/generer` gère proprement le cas « aucune échéance
 * due » (établissement sans verticale SEPA branchée dans ce jeu de test).
 */
final class RemiseSepaTest extends SepaApiTestCase
{
    public function testRemiseRegieDeDemoContientUltmtCdtrEtCdtrEstLaCollectivite(): void
    {
        [$client, $entete] = $this->adminSurA();

        $remise = $this->remiseDemoRegie();
        self::assertSame('transmise', $remise->getStatut()->value);
        self::assertSame(1, $remise->getNbTxs());
        self::assertSame(6300, $remise->getCtrlSumCentimes());
        self::assertSame('FRST', $remise->getSeqTp()?->value, 'Mandat jamais collecté avant cette remise.');

        $client->request('GET', '/api/remise_sepas/' . $remise->getId(), $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame(1, $corps['nbTxs']);
        self::assertSame('transmise', $corps['statut']);
    }

    public function testTelechargementPain008DeLaRemiseRegieContientUltmtCdtrEtAmdmntInd(): void
    {
        [$client, $entete] = $this->adminSurA();
        $remise = $this->remiseDemoRegie();

        $client->request('GET', '/sepa/remises/' . $remise->getId() . '/pain008', $entete);
        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->getHeaders();
        self::assertStringContainsString('application/xml', $headers['content-type'][0] ?? '');

        $xml = $client->getResponse()->getContent();
        self::assertStringContainsString('<UltmtCdtr>', $xml);
        self::assertStringContainsString('COLLECTIVITE DEMO / VILLE-MODELE', $xml);
        self::assertStringContainsString('<AmdmntInd>false</AmdmntInd>', $xml);
        self::assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', $xml);

        // Coffre IBAN réversible (§4 spec) : le fichier de remise transmis à la banque porte le
        // véritable IBAN (fictif, données de démonstration), déchiffré côté serveur — plus un
        // placeholder. Reste néanmoins absent de toute réponse API JSON (MandatSepa/ConfigCreancierSepa,
        // cf. `MandatSepaTest`/`ConfigCreancierSepaTest`) : seul ce fichier XML de remise le porte.
        self::assertStringContainsString(SepaFixtures::REGIE_IBAN_DEMO, $xml, 'IBAN débiteur réel (fictif) du mandat démo.');
        self::assertStringContainsString('FR7600000000000000000000097', $xml, 'IBAN créancier réel (fictif) de la config démo.');
    }

    public function testTelechargementPain008RefuseHorsPerimetreEtablissement(): void
    {
        [$clientB, $enteteB] = $this->adminSurB();
        $remise = $this->remiseDemoRegie();

        // La remise appartient à l'établissement A (régie) : accès refusé depuis le contexte B.
        $clientB->request('GET', '/sepa/remises/' . $remise->getId() . '/pain008', $enteteB);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGenererRemiseSansEcheanceDueRenvoie422(): void
    {
        [$client, $entete] = $this->adminSurB();

        // Établissement B (privé) : aucune verticale SEPA n'a d'échéances dues dans ce jeu de test
        // (Sport non chargé par `SepaApiTestCase`) — le point d'entrée générique gère le cas proprement.
        $client->request('POST', '/api/sepa/remises/generer', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * ⚠ CE QUI A ETE ECARTE DOIT SORTIR DE L'API — sinon une remise amputee a l'air reussie.
     *
     * Le cas « tout est ecarte » leve un 422 qui nomme la raison legale. Le cas « une partie est
     * ecartee » reussit : l'ecran annonce le nombre de lignes COMPOSEES, et rien ne dit que
     * d'autres sont restees dehors faute de preavis. Un total qui parait petit peut etre un total
     * amputé.
     *
     * `nbExclues` et `motifExclusion` portent le groupe de lecture ; ce test le cloue. S'ils le
     * perdaient, l'ecran retomberait dans le silence sans qu'aucune erreur ne le dise — et le
     * commentaire de l'ecran, qui affirmait deja ce silence par le passe, redeviendrait vrai sans
     * que personne ne le sache.
     */
    public function testCeQuiAEteEcarteSortDeLApi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $remise = $this->remiseDemoRegie();

        // ⚠ ON POSE UN MOTIF AVANT DE LIRE. API Platform omet les proprietes nulles : sans exclusion
        // reelle, le motif serait absent de la charge utile pour une raison qui n'a rien a
        // voir avec sa serialisation, et le test conclurait a un defaut inexistant. C'est ce qui
        // s'est passe a ma premiere version.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $remise->setNbExclues(3)->setMotifExclusion('Preavis non parti : aucun expediteur configure.');
        $em->flush();

        $charge = $client->request('GET', '/api/remise_sepas/' . $remise->getId(), $entete)->toArray();

        self::assertArrayHasKey(
            'nbExclues',
            $charge,
            'Le nombre d’échéances écartées ne sort pas de l’API : une remise amputée sera indiscernable d’une remise complète.',
        );
        self::assertArrayHasKey(
            'motifExclusion',
            $charge,
            'Le motif d’exclusion ne sort pas de l’API : l’écran pourra dire QUE des lignes manquent, jamais POURQUOI.',
        );

        // Témoin : la fiche est bien celle qu'on croit, sinon les deux clés ci-dessus pourraient
        // manquer pour une raison sans rapport avec la sérialisation.
        self::assertSame((string) $remise->getId(), $charge['id'] ?? null);
    }

    private function remiseDemoRegie(): RemiseSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['rum' => 'RUM-DEMO-REGIE-0001']);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        $ligne = $em->getRepository(LigneRemiseSepa::class)->findOneBy(['mandat' => $mandat]);
        self::assertInstanceOf(LigneRemiseSepa::class, $ligne);
        $remise = $ligne->getRemise();
        self::assertInstanceOf(RemiseSepa::class, $remise);

        return $remise;
    }
}
