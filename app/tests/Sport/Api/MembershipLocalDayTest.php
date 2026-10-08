<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Membership\Entity\Membership;
use App\Membership\Service\DemanderResiliationHandler;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Sans date fournie, la souscription au guichet, la demande de résiliation et le réengagement
 * prennent le jour de l'établissement, pas le jour UTC du serveur.
 *
 * Mesuré le 07/10/2026 : les trois prenaient `new \DateTimeImmutable('today')`, le jour UTC. De
 * 00:00 à 01:00 ou 02:00 à Paris, c'était encore la veille : tarif de la veille, engagement décalé
 * d'un jour, date d'effet d'une résiliation un jour plus tôt. Pour un témoin à toute heure, les
 * établissements passent à UTC+14 puis à UTC-11 : l'un des deux n'a jamais le jour UTC.
 */
final class MembershipLocalDayTest extends SportApiTestCase
{
    /** @return iterable<string, array{string}> */
    public static function fuseaux(): iterable
    {
        yield 'UTC+14' => ['Pacific/Kiritimati'];
        yield 'UTC-11' => ['Pacific/Pago_Pago'];
    }

    #[DataProvider('fuseaux')]
    public function testSubscriptionDefaultsToTheLocalDay(string $fuseau): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->fuseau($fuseau);
        $gold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + ['json' => [
            'payeur' => '/api/clients/' . $payeur->getId(),
            'formule' => '/api/formules/' . $gold->getFormule()->getId(),
            'iban' => 'FR7630006000011234567890189',
            'titulaireMandat' => 'Jean Dupont',
        ]]);
        self::assertResponseIsSuccessful();

        self::assertSame($this->aujourdhui($fuseau), substr((string) $client->getResponse()->toArray()['dateSouscription'], 0, 10));
    }

    #[DataProvider('fuseaux')]
    public function testTerminationRequestDefaultsToTheLocalDay(string $fuseau): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->fuseau($fuseau);

        $client->request('POST', '/api/sport/abonnements/' . $this->idAbonnementDemo() . '/resiliations', $entete + [
            'json' => ['motif' => 'Déménagement'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame($this->aujourdhui($fuseau), substr((string) $client->getResponse()->toArray()['dateDemande'], 0, 10));
    }

    #[DataProvider('fuseaux')]
    public function testReengagementDefaultsToTheLocalDay(string $fuseau): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->fuseau($fuseau);
        $abonnement = $this->abonnementDemo();
        $id = (string) $abonnement->getId();
        /** @var DemanderResiliationHandler $resiliations */
        $resiliations = static::getContainer()->get(DemanderResiliationHandler::class);
        $resiliations->executerEffet($resiliations->demander($abonnement, $abonnement->getDateFinEngagement()->modify('+1 day'), 'Test', false, null));
        $em->clear();

        $client->request('POST', '/api/sport/abonnements/' . $id . '/reengager', $entete + [
            'json' => ['iban' => 'FR7630006000011234567890200', 'titulaireMandat' => 'Marie Dupont'],
        ]);
        self::assertResponseIsSuccessful();
        $nouvel = $client->getResponse()->toArray()['nouvelAbonnement'];
        $nouvelId = \is_array($nouvel) ? $nouvel['id'] : basename((string) $nouvel);

        self::assertSame($this->aujourdhui($fuseau), $em->getRepository(Membership::class)->find($nouvelId)?->getDateSouscription()->format('Y-m-d'));
    }

    /** Tous les établissements passent au fuseau donné. */
    private function fuseau(string $fuseau): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(Etablissement::class)->findAll() as $etablissement) {
            $etablissement->setFuseauHoraire($fuseau);
        }
        $em->flush();

        return $em;
    }

    private function aujourdhui(string $fuseau): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($fuseau)))->format('Y-m-d');
    }
}
