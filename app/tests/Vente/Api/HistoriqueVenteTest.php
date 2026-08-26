<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * D48 — l'historique des ventes doit être triable et filtrable **côté serveur**.
 *
 * Sans ces filtres, un écran d'historique n'a que deux options : tout charger, ou filtrer la page
 * déjà chargée. La seconde est la plus dangereuse — elle donne un résultat qui *ressemble* à une
 * recherche, et un caissier qui ne trouve pas sa vente en conclut qu'elle n'existe pas. `claude-H` a
 * refusé de l'écrire, et elle avait raison.
 *
 * Le tri compte autant que le filtre : sans `OrderFilter`, « les cinquante dernières ventes » n'est
 * même pas garanti, l'ordre étant celui que la base rend.
 */
final class HistoriqueVenteTest extends VenteApiTestCase
{
    /** Tri décroissant sur la date, filtre de plage, filtre par client — les trois manques de D48. */
    public function testHistoriqueTriableEtFiltrable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);

        $ancienne = $this->creerVente($client, $entete, $session['id'])['id'];
        $milieu = $this->creerVente($client, $entete, $session['id'])['id'];
        $recente = $this->creerVente($client, $entete, $session['id'])['id'];

        // Trois dates distinctes et un client identifié : la création les pose toutes au même instant,
        // ce qui ne permettrait de vérifier ni l'ordre ni la plage.
        $idClient = Uuid::v4();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $this->dater($em, $ancienne, '2026-01-10 09:00:00');
        $this->dater($em, $milieu, '2026-03-15 09:00:00');
        $this->dater($em, $recente, '2026-06-20 09:00:00', $idClient);
        $em->flush();

        // --- tri décroissant : la plus récente d'abord
        $ordonnees = $this->ids($client, $entete, ['order' => ['date' => 'desc'], 'itemsPerPage' => 100]);
        self::assertSame(
            [$recente, $milieu, $ancienne],
            array_values(array_intersect($ordonnees, [$recente, $milieu, $ancienne])),
            'Le tri décroissant sur la date doit être rendu par le serveur.'
        );

        // --- plage : ce qui est postérieur au 1er mars
        $apresMars = $this->ids($client, $entete, ['date' => ['after' => '2026-03-01'], 'itemsPerPage' => 100]);
        self::assertContains($recente, $apresMars);
        self::assertContains($milieu, $apresMars);
        self::assertNotContains($ancienne, $apresMars, 'Une vente antérieure à la borne ne doit pas être rendue.');

        // --- par client : seule la vente qui lui est rattachée
        $duClient = $this->ids($client, $entete, ['client' => (string) $idClient, 'itemsPerPage' => 100]);
        self::assertSame([$recente], $duClient, 'Le filtre client doit être exact, pas approchant.');
    }

    private function dater(EntityManagerInterface $em, string $idVente, string $date, ?Uuid $client = null): void
    {
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertNotNull($vente);
        $vente->setDate(new \DateTimeImmutable($date));
        if ($client !== null) {
            $vente->setClient($client);
        }
    }

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $requete
     *
     * @return list<string>
     */
    private function ids(object $client, array $entete, array $requete): array
    {
        $client->request('GET', '/api/ventes', $entete + ['query' => $requete]);
        self::assertResponseIsSuccessful();
        $reponse = $client->getResponse()->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];

        return array_map(static fn (array $v): string => $v['id'], $membres);
    }
}
