<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Tests\SocleApiTestCase;
use App\Website\Entity\Trade;
use App\Website\Entity\TradeActivity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `website:trades:seed` — la seule propriété qui compte est ce qu'elle NE FAIT PAS.
 *
 * Cette commande tourne à CHAQUE déploiement (`infra/deploy-preprod.sh`). Si elle écrasait les
 * lignes existantes, le chapô que Maxime vient de corriger reviendrait à celui qui dort dans le code
 * à la mise en production suivante — la page reculerait toute seule, sans erreur, sans journal, et
 * sans que personne relie la régression au déploiement.
 *
 * ⚠ **UN TEST QUI NE LANCERAIT LA COMMANDE QU'UNE FOIS NE VERRAIT RIEN.** Le premier passage crée :
 * il est juste dans tous les cas, y compris ceux où le second détruirait tout. C'est le DEUXIÈME
 * passage qui porte la garantie, et c'est celui que la production exécute vingt fois.
 */
final class SeedTradesCommandTest extends SocleApiTestCase
{
    public function testDeuxPassagesDonnentToujoursCinqLignes(): void
    {
        static::createClient();

        self::assertSame(0, $this->lancer()->getStatusCode());
        self::assertCount(5, $this->lignes(), 'Le premier passage doit créer les cinq métiers.');

        self::assertSame(0, $this->lancer()->getStatusCode());
        self::assertCount(5, $this->lignes(), 'Le second passage ne doit rien ajouter — ni doublon, ni ligne fantôme.');
    }

    /**
     * ⚠ **LE TÉMOIN QUI PORTE TOUT LE TEST.**
     *
     * On modifie un chapô comme un rédacteur le ferait, puis on relance la commande. Le texte
     * modifié doit survivre. Sans cette assertion, « deux passages, cinq lignes » resterait vert sur
     * une commande qui réécrit tout à chaque déploiement : le compte serait juste, et le contenu
     * perdu.
     */
    public function testUnChapoModifieALaMainSurvitAuDeploiementSuivant(): void
    {
        static::createClient();
        $this->lancer();

        $ecrit = 'Un chapô corrigé à la main, un mardi après-midi.';

        $piscine = $this->ligne('piscine');
        $origine = $piscine->getLead();
        $piscine->setLead($ecrit);
        $this->em()->flush();

        self::assertNotSame($origine, $ecrit, 'Le témoin doit différer du texte d’origine, sinon il ne mesure rien.');

        $this->lancer();
        $this->em()->clear();

        self::assertSame(
            $ecrit,
            $this->ligne('piscine')->getLead(),
            'Un déploiement ne doit jamais réécrire un texte rédigé : la page reculerait sans que personne sache pourquoi.',
        );
    }

    /**
     * `--force` est le geste explicite qui ramène les textes d'origine.
     *
     * Il existe pour que la garantie ci-dessus ne soit pas une impasse — sans lui, on n'aurait plus
     * aucun moyen de revenir en arrière, et quelqu'un finirait par retirer la garantie elle-même.
     */
    public function testForceReecritCeQuiAEteRedige(): void
    {
        static::createClient();
        $this->lancer();

        $piscine = $this->ligne('piscine');
        $origine = $piscine->getLead();
        $piscine->setLead('Texte de passage.');
        $this->em()->flush();

        $this->lancer(['--force' => true]);
        $this->em()->clear();

        self::assertSame($origine, $this->ligne('piscine')->getLead());
        self::assertCount(5, $this->lignes(), '`--force` réécrit, il ne duplique pas.');
    }

    /**
     * ⚠ **UNE ACTIVITÉ DÉCOCHÉE NE REVIENT PAS — et ce témoin a trouvé un vrai défaut.**
     *
     * La commande complétait les activités des lignes qu'elle CONSERVE. Une activité retirée par un
     * exploitant revenait donc au déploiement suivant : aucun texte ne changeait, mais les modules
     * suggérés sur sa page, si. C'est la même régression qu'un chapô réécrit, un cran plus bas et
     * bien plus difficile à voir.
     *
     * La leçon tient en une phrase, et elle était écrite à l'envers dans le code : « ne jamais
     * retirer » ne protège rien tout seul, puisque c'est l'AJOUT qui ramène ce qu'on a retiré.
     */
    public function testUneActiviteRetireeNeRevientPas(): void
    {
        static::createClient();
        $this->lancer();

        $piscine = $this->ligne('piscine');

        // ⚠ On collecte AVANT de retirer : muter une collection pendant qu'on la parcourt saute des
        //   éléments en silence, et le témoin partirait d'un état qui n'est pas celui qu'il annonce.
        $aRetirer = [];

        foreach ($piscine->getActivities() as $activite) {
            if (EstablishmentActivity::EquipmentRental === $activite->getActivity()) {
                $aRetirer[] = $activite;
            }
        }

        self::assertNotSame([], $aRetirer, 'La piscine doit bien porter la location de matériel au départ.');

        foreach ($aRetirer as $activite) {
            $piscine->removeActivity($activite);
        }

        $this->em()->flush();
        $this->em()->clear();

        self::assertNotContains(
            EstablishmentActivity::EquipmentRental,
            $this->activitesDe('piscine'),
            'Le témoin doit partir d’un état où l’activité est bien absente.',
        );

        $this->lancer();
        $this->em()->clear();

        self::assertNotContains(
            EstablishmentActivity::EquipmentRental,
            $this->activitesDe('piscine'),
            'Une activité décochée ne doit pas revenir : elle changerait les modules suggérés sans que personne l’ait demandé.',
        );

        // Et le sens inverse : ce qui n'a jamais été retiré est toujours là. Sans cette assertion,
        // une commande qui viderait purement et simplement les activités passerait le test.
        self::assertContains(EstablishmentActivity::Entry, $this->activitesDe('piscine'));
    }

    /**
     * Une ligne QU'ELLE CRÉE, en revanche, reçoit bien ses activités.
     *
     * ⚠ C'est l'autre moitié, et sans elle la garantie ci-dessus se satisferait d'une commande qui
     * n'écrirait jamais aucune activité : « décochée ne revient pas » serait vrai, trivialement, et
     * les cinq métiers seraient semés sans composition.
     */
    public function testUneLigneCreeeRecoitSesActivites(): void
    {
        static::createClient();
        $this->lancer();

        $activites = $this->activitesDe('piscine');

        self::assertContains(EstablishmentActivity::Entry, $activites);
        self::assertContains(EstablishmentActivity::EquipmentRental, $activites);
        self::assertCount(4, $activites, 'La piscine en déclare quatre dans `TradeFallback::ACTIVITES`.');
    }

    /**
     * `--a-blanc` n'écrit rien. Sur une base vide, elle doit donc la laisser vide.
     *
     * ⚠ Une option « à blanc » qui écrirait quand même est le pire des cas : on l'utilise
     * précisément quand on n'est pas sûr, donc sur les bases où l'on a le plus à perdre.
     */
    public function testABlancNecritRien(): void
    {
        static::createClient();

        self::assertSame(0, $this->lancer(['--a-blanc' => true])->getStatusCode());
        self::assertCount(0, $this->lignes(), '« À blanc » doit laisser la base exactement comme elle était.');
    }

    // ---------------------------------------------------------------- montage

    /** @param array<string, bool> $options */
    private function lancer(array $options = []): CommandTester
    {
        $application = new Application(static::$kernel ?? static::bootKernel());
        $tester = new CommandTester($application->find('website:trades:seed'));
        $tester->execute($options);

        return $tester;
    }

    /** @return list<Trade> */
    private function lignes(): array
    {
        /** @var list<Trade> $lignes */
        $lignes = $this->em()->getRepository(Trade::class)->findAll();

        return $lignes;
    }

    private function ligne(string $slug): Trade
    {
        $ligne = $this->em()->getRepository(Trade::class)->findOneBy(['slug' => $slug]);

        self::assertInstanceOf(Trade::class, $ligne, sprintf('La ligne « %s » doit exister.', $slug));

        return $ligne;
    }

    /** @return list<EstablishmentActivity> */
    private function activitesDe(string $slug): array
    {
        return array_map(
            static fn (TradeActivity $a): EstablishmentActivity => $a->getActivity(),
            $this->ligne($slug)->getActivities()->toArray(),
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
