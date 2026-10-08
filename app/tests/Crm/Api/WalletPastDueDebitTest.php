<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutPmv;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UN PORTE-MONNAIE ÉCHU N'EST PLUS DÉPENSABLE, MÊME AVANT LE PASSAGE DE LA TÂCHE D'EXPIRATION
 * (décision de Maxime du 08/10/2026).
 *
 * Mesuré le 08/10/2026 : `crm:rgpd:expirer-pmv` tourne une fois par jour à heure libre, et le débit
 * ne lisait que le statut. Échu la veille mais encore « actif », le porte-monnaie se débitait jusqu'au
 * passage suivant de la tâche, jusqu'à 24 h.
 */
final class WalletPastDueDebitTest extends CrmApiTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function echeances(): iterable
    {
        // [fuseau, échéance au jour de l'établissement, débit accepté]. À toute heure, l'un des deux
        // fuseaux n'a pas le jour UTC : un débit qui lirait le jour UTC se tromperait sur l'un d'eux.
        foreach (['UTC+14' => 'Pacific/Kiritimati', 'UTC-11' => 'Pacific/Pago_Pago'] as $nom => $fuseau) {
            yield $nom . ', échu depuis hier' => [$fuseau, 'yesterday', false];
            yield $nom . ', dernier jour' => [$fuseau, 'today', true];
        }
    }

    #[DataProvider('echeances')]
    public function testAPastDueWalletIsRefusedBeforeTheExpiryTaskRuns(string $fuseau, string $echeance, bool $accepte): void
    {
        $this->adminSurA();
        $payeurId = $this->idPayeur();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(Etablissement::class)->findAll() as $etablissement) {
            $etablissement->setFuseauHoraire($fuseau);
        }
        // La tâche d'expiration n'est pas passée : le statut est encore « actif ».
        $jour = (new \DateTimeImmutable($echeance, new \DateTimeZone($fuseau)))->format('Y-m-d');
        $this->entite(PorteMonnaieVirtuel::class, ['client' => $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])])
            ->setStatut(StatutPmv::Actif)->setDateEcheance(new \DateTimeImmutable($jour));
        $em->flush();
        self::ensureKernelShutdown();

        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 1);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv']]);

        $contenu = (string) $reponse->getContent(false);
        self::assertSame($accepte ? 'accepté' : 'refusé', $reponse->getStatusCode() < 300 ? 'accepté' : 'refusé', 'Échéance ' . $jour . ' : ' . $contenu);
        if (!$accepte) {
            self::assertSame(422, $reponse->getStatusCode());
            self::assertStringContainsString('RG-M4-04', $contenu, 'Motif « expiré », pas « solde insuffisant ».');
        }
        $solde = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray()['solde'];
        self::assertSame($accepte ? '45.05' : '50.00', $solde);
    }
}
