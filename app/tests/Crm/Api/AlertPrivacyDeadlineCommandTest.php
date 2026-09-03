<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Command\AlertPrivacyDeadlineCommand;
use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\TypeDemandeRgpd;
use App\Platform\Entity\Notification;
use App\Tests\Crm\CrmApiTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LA SEULE ALERTE DU PRODUIT QUI NE VIENT PAS D'UN GESTE, MAIS DU TEMPS.
 *
 * Une demande RGPD dépasse le délai légal d'un mois sans que personne ne fasse quoi que ce soit :
 * il n'y a aucune action au bout de laquelle quelqu'un le remarquerait. L'écran qui les porte a
 * quitté le menu quotidien le 03/09 (R27), ce qui a rendu le problème visible.
 *
 * ⚠ CE QUE CE TEST GARDE AVANT TOUT, C'EST L'IDEMPOTENCE. Une tâche nocturne qui re-signalerait la
 * même demande chaque nuit jusqu'à son traitement transformerait la cloche en bruit de fond — et
 * une cloche qu'on n'ouvre plus est exactement le défaut qu'on cherchait à corriger. Le deuxième
 * passage est donc le test le plus important du fichier.
 */
final class AlertPrivacyDeadlineCommandTest extends CrmApiTestCase
{
    public function testUneDemandeHorsDelaiEstSignaleeUneFois(): void
    {
        $ids = $this->poserDeuxDemandes();

        self::assertSame(0, $this->nombreDeNotifications(), 'témoin : la cloche est vide au départ');

        $sortie = $this->lancer();
        self::assertStringContainsString('1 demande', $sortie);

        self::assertGreaterThan(
            0,
            $this->nombreDeNotifications(),
            'la demande hors délai doit produire au moins une notification',
        );
        self::assertNotNull(
            $this->relire($ids['horsDelai'])->getDeadlineAlertedAt(),
            'et la demande doit porter la trace du signalement',
        );
    }

    /**
     * ⚠ LE DEUXIÈME PASSAGE NE DOIT RIEN REFAIRE — c'est le cœur de cette commande.
     *
     * Sans la colonne `deadlineAlertedAt`, la requête reprendrait la même demande chaque nuit : la
     * même alerte, tous les matins, jusqu'à ce que quelqu'un traite la demande. Ce test échouerait
     * bruyamment, et c'est le seul qui le ferait.
     */
    public function testUnSecondPassageNeSignalePasDeNouveau(): void
    {
        $this->poserDeuxDemandes();

        $this->lancer();
        $apresLePremier = $this->nombreDeNotifications();
        self::assertGreaterThan(0, $apresLePremier, 'témoin : le premier passage a bien signalé');

        $sortie = $this->lancer();

        self::assertSame(
            $apresLePremier,
            $this->nombreDeNotifications(),
            'le second passage ne doit créer aucune notification de plus',
        );
        self::assertStringContainsString('Aucune demande', $sortie);
    }

    /** ⚠ ET CELLE QUI EST DANS LES TEMPS N'EST PAS TOUCHÉE : le contrôle doit aussi épargner. */
    public function testUneDemandeRECENTENEstPasSignalee(): void
    {
        $ids = $this->poserDeuxDemandes();

        $this->lancer();

        self::assertNull(
            $this->relire($ids['recente'])->getDeadlineAlertedAt(),
            'une demande d’hier est dans les temps — la signaler serait une fausse alerte',
        );
    }

    /** Le plafond permet de découvrir l'ampleur d'un arriéré sans inonder la cloche. */
    public function testLePlafondLimiteCeQuUnPassageSignale(): void
    {
        $ids = $this->poserDeuxDemandes();
        // Une seconde demande hors délai, pour qu'il y ait deux candidates.
        $this->poserDemande(TypeDemandeRgpd::Anonymisation, StatutDemandeRgpd::Recue, '-3 months');

        $this->lancer(['--plafond' => '1']);

        $signalees = array_filter(
            $this->em()->getRepository(DemandeRGPD::class)->findAll(),
            static fn (DemandeRGPD $d): bool => $d->getDeadlineAlertedAt() !== null,
        );
        self::assertCount(1, $signalees, 'le plafond doit borner ce qu’un passage signale');
        self::assertNull($this->relire($ids['recente'])->getDeadlineAlertedAt(), 'témoin : jamais la récente');
    }

    /**
     * ⚠ `CommandTester`, ET PAS `Application::run()` AVEC UN `BufferedOutput`.
     *
     * Premier montage : la commande tournait — notifications créées, colonne posée, code de sortie
     * zéro — et la sortie console arrivait VIDE. Longueur mesurée par une sonde : 0, verbatim vide.
     * Mes assertions d'ÉTAT passaient pendant que mon instrument de LECTURE ne rendait rien ; sans
     * elles, j'aurais conclu que la commande ne faisait rien et cherché le défaut dans la commande.
     *
     * Le dépôt a déjà son patron pour ceci — `PreNotifyCommandTest`, `RemettreCompteursCollecteCommandTest`
     * — et il utilise `CommandTester`. Reprendre le patron du dépôt vaut mieux que réparer le mien.
     *
     * @param array<string, string> $options
     */
    private function lancer(array $options = []): string
    {
        $commande = static::getContainer()->get(AlertPrivacyDeadlineCommand::class);
        $testeur = new CommandTester($commande);
        $testeur->execute($options);
        $testeur->assertCommandIsSuccessful();

        return $testeur->getDisplay();
    }

    private function nombreDeNotifications(): int
    {
        return (int) $this->em()
            ->createQuery('SELECT COUNT(n) FROM ' . Notification::class . ' n WHERE n.source = :s')
            ->setParameter('s', 'privacy_request.deadline_breached')
            ->getSingleScalarResult();
    }

    private function relire(string $id): DemandeRGPD
    {
        $this->em()->clear();
        $d = $this->em()->getRepository(DemandeRGPD::class)->find($id);
        self::assertNotNull($d, 'la demande doit être relisible');

        return $d;
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return array{horsDelai: string, recente: string} */
    private function poserDeuxDemandes(): array
    {
        return [
            'horsDelai' => $this->poserDemande(TypeDemandeRgpd::Effacement, StatutDemandeRgpd::Recue, '-2 months'),
            'recente' => $this->poserDemande(TypeDemandeRgpd::Anonymisation, StatutDemandeRgpd::Recue, '-1 day'),
        ];
    }

    private function poserDemande(TypeDemandeRgpd $type, StatutDemandeRgpd $statut, string $quand): string
    {
        $em = $this->em();
        $client = $em->getRepository(Client::class)->find($this->idPayeur());
        self::assertNotNull($client, 'témoin : le client des fixtures existe');

        $d = new DemandeRGPD();
        $d->setClient($client)->setType($type)->setStatut($statut);
        (new \ReflectionProperty(DemandeRGPD::class, 'dateDemande'))
            ->setValue($d, new \DateTimeImmutable($quand));
        $em->persist($d);
        $em->flush();

        return (string) $d->getId();
    }
}
