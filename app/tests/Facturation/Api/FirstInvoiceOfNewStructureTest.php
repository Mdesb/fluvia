<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\LegalVatRateFixtureTrait;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA PREMIÈRE FACTURE D'UNE STRUCTURE NEUVE EST ÉMISE.
 *
 * Mesuré le 08/10/2026 : `POST /organisation/structures` ouvre le profil, son plan de comptes, ses
 * taux et son paramétrage, mais aucune période comptable. `ResolveurComptesFacturation::periodePour`
 * ne savait que la chercher : la première facture était refusée en 422, « aucune période comptable
 * ouverte ». Le premier jour d'un client, donc, et sans rien qu'il puisse faire à l'écran.
 */
final class FirstInvoiceOfNewStructureTest extends FacturationApiTestCase
{
    use LegalVatRateFixtureTrait;

    public function testANewStructureIssuesItsFirstInvoice(): void
    {
        [$client, $headers] = $this->adminSurA();
        $this->seedFranceMetropolitanVatRates($this->em());
        $opened = $client->request('POST', '/api/organisation/structures', $headers + ['json' => [
            'nomCommercial' => 'Structure neuve',
            'denomination' => 'STRUCTURE NEUVE SAS',
            'siret' => '81240390500019',
            'formeJuridique' => '5710',
        ]])->toArray();
        $headers['headers'] = [ContexteEtablissement::HEADER => $opened['etablissement']];

        $em = $this->em();
        $site = $em->getRepository(Etablissement::class)->findOneBy(['nom' => 'Structure neuve']);
        $profile = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $site]);
        $rate = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profile, 'taux' => '20.00']);
        self::assertInstanceOf(TauxTva::class, $rate);

        $body = $this->corpsFactureDirecte();
        $body['lignes'][0]['tauxTva'] = '/api/taux_tvas/' . $rate->getId();
        $draft = $client->request('POST', '/api/factures', $headers + ['json' => $body])->toArray();
        $response = $client->request('POST', '/api/factures/' . $draft['id'] . '/emettre', $headers);

        self::assertLessThan(300, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('FA-' . date('Y') . '-00001', $response->toArray()['numero']);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
