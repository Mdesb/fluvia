<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Service\DemanderResiliationHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des mandats SEPA (US-SPORT-10, CA-12) : révocation à la date d'effet de la résiliation
 * (pas avant), visible sur l'écran de gestion des mandats. IBAN jamais exposé (garde transverse §4).
 */
final class MandatTest extends SportApiTestCase
{
    public function testCa12MandatRevoqueALaDateDeffetDeLaResiliation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();
        $mandatId = (string) $abonnement->getMandatSepa()->getId();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var DemanderResiliationHandler $handler */
        $handler = static::getContainer()->get(DemanderResiliationHandler::class);

        $dateDemande = $abonnement->getDateFinEngagement()->modify('+1 day');
        $resiliation = $handler->demander($abonnement, $dateDemande, 'Test', false, null);
        self::assertSame('en_preavis', $resiliation->getStatut()->value);

        // Avant la date d'effet : le mandat reste actif (RG-SPORT-06, pas avant).
        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertSame('actif', $client->getResponse()->toArray()['statut']);

        $handler->executerEffet($resiliation);
        $em->clear();

        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('revoque', $client->getResponse()->toArray()['statut'], 'Visible révoqué sur l\'écran de gestion des mandats.');
    }

    /**
     * ⚠ CE TEST NAIT AVEC LE PARTAGE DU MANDAT, ET IL EST LA MOITIE QUI MANQUAIT.
     *
     * `AbonnementFitness.mandatSepa` est passe de `OneToOne` a `ManyToOne` pour qu'un payeur ne
     * donne son IBAN qu'une fois. Ce changement SEUL rendait faux
     * `DemanderResiliationHandler::executerEffet()`, qui revoquait le mandat sans condition :
     * resilier le premier abonnement aurait arrete les prelevements du second SANS ERREUR ET SANS
     * MESSAGE -- l'adherent gardant son acces, puisque SON abonnement reste actif.
     *
     * Les deux assertions ne valent qu'ensemble :
     *   - la premiere seule passerait si l'on ne revoquait plus JAMAIS rien ;
     *   - la seconde seule est deja couverte par `testCa12`, sur un mandat non partage.
     */
    public function testResilierUnAbonnementNeRevoquePasLeMandatQuUnAutreEmploie(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var DemanderResiliationHandler $handler */
        $handler = static::getContainer()->get(DemanderResiliationHandler::class);

        $premier = $this->abonnementDemo();
        $mandat = $premier->getMandatSepa();
        $mandatId = (string) $mandat->getId();

        // Un SECOND abonnement sur LE MEME mandat : le cas du parent qui inscrit son enfant.
        // ⚠ Date de souscription POSTERIEURE, delibere : `abonnementDemo()` trie par
        //    `dateSouscription ASC`, donc une date egale rendrait le choix -- et le test -- instable.
        $second = (new AbonnementFitness())
            ->setAdherent($premier->getAdherent())
            ->setPayeur($premier->getPayeur())
            ->setFormule($premier->getFormule())
            ->setEtablissement($premier->getEtablissement())
            ->setMandatSepa($mandat)
            ->setPeriodicite($premier->getPeriodicite())
            ->setDateSouscription($premier->getDateSouscription()->modify('+1 day'))
            ->setDateDebutEngagement($premier->getDateDebutEngagement())
            ->setDateFinEngagement($premier->getDateFinEngagement())
            ->setPreavisResiliationJours($premier->getPreavisResiliationJours());
        $em->persist($second);
        $em->flush();

        // ── Resiliation du PREMIER : le mandat doit SURVIVRE, le second en a besoin.
        $r1 = $handler->demander($premier, $premier->getDateFinEngagement()->modify('+1 day'), 'Test', false, null);
        $handler->executerEffet($r1);
        $em->clear();

        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'actif',
            $client->getResponse()->toArray()['statut'],
            'Un autre abonnement s appuie encore sur ce mandat : le revoquer arreterait ses '
            . 'prelevements sans erreur ni message.',
        );

        // ── Resiliation du SECOND : plus personne, le mandat doit alors etre revoque.
        $secondRelu = $em->getRepository(AbonnementFitness::class)->find($second->getId());
        $r2 = $handler->demander($secondRelu, $secondRelu->getDateFinEngagement()->modify('+1 day'), 'Test', false, null);
        $handler->executerEffet($r2);
        $em->clear();

        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'revoque',
            $client->getResponse()->toArray()['statut'],
            'Plus aucun abonnement vivant : la revocation doit toujours avoir lieu.',
        );
    }

    public function testIbanJamaisExposeSurLesReponsesMandat(): void
    {
        [$client, $entete] = $this->adminSurA();
        $mandatId = (string) $this->abonnementDemo()->getMandatSepa()->getId();

        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString('ibanToken', $corps);
        self::assertStringNotContainsString('ibanChiffre', $corps);
        self::assertStringNotContainsString('FR76', $corps, 'Aucun IBAN en clair (préfixe pays) dans la réponse API.');

        $client->request('GET', '/api/mandat_sepas', $entete);
        self::assertResponseIsSuccessful();
        $corpsCollection = $client->getResponse()->getContent();
        self::assertStringNotContainsString('ibanToken', $corpsCollection);
        self::assertStringNotContainsString('ibanChiffre', $corpsCollection);
    }

    public function testIbanJamaisPersisteEnClairEnBase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->find($this->idAbonnementDemo());
        $mandat = $abonnement->getMandatSepa();

        self::assertStringNotContainsString('FR76', $mandat->getIbanToken());
        self::assertNotSame('FR7630006000011234567890189', $mandat->getIbanToken());
        // Token non réversible trivialement : longueur d'un HMAC-SHA256 hexadécimal (64 caractères).
        self::assertSame(64, \strlen($mandat->getIbanToken()));
    }
}
