<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Command\PreNotifyUpcomingDebitsCommand;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Service\DebitPreNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `sepa:preavis:annoncer` — la commande sans laquelle PAY-2 ne servait à rien.
 *
 * **Ce qui manquait.** La collecte écarte toute échéance non couverte par un préavis émis assez tôt.
 * Poser le préavis au moment de prélever ne peut pas marcher : le délai ne serait jamais tenu, et
 * annoncer au moment de prélever n'est pas prévenir. Il fallait donc quelqu'un qui parle **avant**, et
 * `App\Sepa` ne déclarait aucune commande — en production, cent pour cent des échéances auraient été
 * écartées, indéfiniment.
 */
final class PreNotifyCommandTest extends SepaApiTestCase
{
    public function testElleAnnonceLesEcheancesAVenir(): void
    {
        [$commande, $espion] = $this->commande(4990);

        $testeur = new CommandTester($commande);
        $testeur->execute([]);

        self::assertStringContainsString('1 préavis envoyés', $testeur->getDisplay());

        $envoi = $espion->dernier();
        self::assertNotNull($envoi);
        self::assertSame(4990, $envoi->variables['amountCents']);
    }

    /**
     * **Le test qui compte : deux passages ne repoussent pas la date d'envoi.**
     *
     * `announce()` remet `sentAt` à l'instant courant, à dessein. Une commande quotidienne qui
     * réannoncerait tout repousserait donc `sentAt` chaque jour, et **plus aucune échéance ne serait
     * jamais couverte** : la collecte s'arrêterait pendant que la commande signale, chaque matin, avoir
     * bien travaillé. C'est la panne la plus difficile à voir — tout s'exécute, rien n'aboutit.
     */
    public function testDeuxPassagesNeRepoussentPasLaDateDEnvoi(): void
    {
        [$commande] = $this->commande(4990);

        (new CommandTester($commande))->execute([]);

        $em = $this->em();
        $premier = $em->getRepository(DebitPreNotification::class)->findOneBy(['originReference' => 'echeance-cmd']);
        self::assertNotNull($premier);
        $envoyeLe = $premier->getSentAt();

        // On recule artificiellement l'envoi : si le second passage réannonçait, il le ramènerait à
        // maintenant, et l'écart se verrait.
        $premier->setSentAt(new \DateTimeImmutable('-20 days'));
        $em->flush();
        $em->clear();

        $testeur = new CommandTester($commande);
        $testeur->execute([]);

        // « 0 envoyés » est l assertion qui compte : rien n a ete reannonce. Le nombre de « deja
        // annonces » suit le nombre de configurations creancier des fixtures, ce qui ne regarde pas
        // ce test.
        self::assertStringContainsString('0 préavis envoyés', $testeur->getDisplay());

        $relu = $this->em()->getRepository(DebitPreNotification::class)->findOneBy(['originReference' => 'echeance-cmd']);
        self::assertNotNull($relu);
        self::assertLessThan(
            $envoyeLe,
            $relu->getSentAt(),
            'le second passage a repousse sentAt : plus aucune echeance ne serait jamais couverte',
        );
    }

    /**
     * **La boucle se referme : ce que la commande annonce, la collecte le reconnaît.**
     *
     * Sans cette assertion, on aurait deux moitiés qui marchent séparément et ne se rejoignent
     * jamais — exactement le défaut que ce lot corrige.
     */
    public function testCeQuElleAnnonceEstEnsuiteCouvert(): void
    {
        [$commande, , $preNotifier, $mandat] = $this->commande(4990);

        (new CommandTester($commande))->execute([]);

        $em = $this->em();
        $preavis = $em->getRepository(DebitPreNotification::class)->findOneBy(['originReference' => 'echeance-cmd']);
        self::assertNotNull($preavis);

        $dateEcheance = $preavis->getAnnouncedDueDate();

        self::assertTrue(
            $preNotifier->covers($mandat, 'echeance-cmd', 4990, $dateEcheance),
            'le prelevement annonce doit etre couvert a la date annoncee',
        );
        self::assertFalse(
            $preNotifier->covers($mandat, 'echeance-cmd', 4990, new \DateTimeImmutable('tomorrow')),
            'et ne pas l etre avant que le delai soit ecoule',
        );
    }

    /** Un montant qui change réannonce, et rend au client la totalité du délai. */
    public function testUnMontantQuiChangeReannonce(): void
    {
        [$commande, , , $mandat, $source] = $this->commande(4990);

        (new CommandTester($commande))->execute([]);

        $source->montantCentimes = 9990;
        $testeur = new CommandTester($commande);
        $testeur->execute([]);

        self::assertStringContainsString('1 préavis envoyés', $testeur->getDisplay());

        // Sur CETTE echeance : la fixture de demonstration en pose une autre sur le meme mandat.
        $preavis = $this->em()->getRepository(DebitPreNotification::class)->findBy([
            'mandate' => $mandat,
            'originReference' => 'echeance-cmd',
        ]);
        self::assertCount(1, $preavis, 'on remplace l annonce, on n en empile pas une seconde');
        self::assertSame(9990, $preavis[0]->getAmountCents());
    }

    /** `--dry-run` montre sans envoyer : c'est ce qui rend le premier passage tenable. */
    public function testDryRunNEnvoieRien(): void
    {
        [$commande, $espion] = $this->commande(4990);

        $testeur = new CommandTester($commande);
        $testeur->execute(['--dry-run' => true]);

        self::assertStringContainsString('à annoncer', $testeur->getDisplay());
        self::assertNull($espion->dernier(), 'aucune notification ne part');
        self::assertCount(0, $this->em()->getRepository(DebitPreNotification::class)->findBy(['originReference' => 'echeance-cmd']));
    }

    // ---------------------------------------------------------------- montage

    /** @return array{0: PreNotifyUpcomingDebitsCommand, 1: NotifierLivrant, 2: DebitPreNotifier, 3: MandatSepa, 4: SourceEcheanceStub} */
    private function commande(int $montantCentimes): array
    {
        $em = $this->em();

        $config = $em->getRepository(ConfigCreancierSepa::class)->findOneBy([]);
        self::assertNotNull($config, 'une configuration creancier est requise');
        $etablissement = $config->getEtablissement();
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['etablissement' => $etablissement]);
        self::assertNotNull($mandat, 'un mandat est requis');
        self::assertNotNull($mandat->getClient(), 'le mandat doit mener a un client');

        // Un notifieur qui LIVRE : l'adaptateur par defaut journalise faute de prestataire (D19), et
        // sur cette base aucune annonce ne couvrirait quoi que ce soit. Ce que ces tests eprouvent,
        // c'est la commande, pas l'absence de prestataire — celle-la est deja epinglee ailleurs.
        $espion = new NotifierLivrant();
        $preNotifier = new DebitPreNotifier($em, $espion);

        $source = new SourceEcheanceStub($mandat->getId(), $montantCentimes);

        return [new PreNotifyUpcomingDebitsCommand($em, $preNotifier, $source), $espion, $preNotifier, $mandat, $source];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}

/** Une verticale qui a toujours une echeance due a la date demandee. */
final class SourceEcheanceStub implements EcheanceSepaSource
{
    public function __construct(
        private readonly \Symfony\Component\Uid\Uuid $mandatId,
        public int $montantCentimes,
    ) {
    }

    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        return [new EcheanceSepaDue(
            referenceOrigine: 'echeance-cmd',
            mandatId: $this->mandatId,
            montantCentimes: $this->montantCentimes,
            libelle: 'Abonnement de test',
            dateEcheance: $dateExecution,
        )];
    }

    public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
    {
    }
}

/** Un notifieur qui livre pour de bon, et retient ce qu'on lui a confie. */
final class NotifierLivrant implements ClientNotifierInterface
{
    /** @var list<ClientNotification> */
    private array $envois = [];

    public function notify(ClientNotification $notification): NotificationOutcome
    {
        $this->envois[] = $notification;

        return NotificationOutcome::Envoyee;
    }

    public function dernier(): ?ClientNotification
    {
        return [] === $this->envois ? null : $this->envois[array_key_last($this->envois)];
    }
}
