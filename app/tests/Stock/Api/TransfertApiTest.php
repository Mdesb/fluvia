<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\Entity\LotStock;
use App\Tests\Stock\StockApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transfert inter-établissements (US-STOCK-10, RG-STOCK-14, CA-12) : l'expédition décrémente la
 * source (consommation d'une couche selon sa méthode active) ; la réception crée à destination une
 * nouvelle couche dont le coût unitaire est celui de la couche consommée à la source (pas un nouveau
 * prix d'achat).
 */
final class TransfertApiTest extends StockApiTestCase
{
    public function testCa12TransfertExpeditionEtReception(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabAIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $etabBIri = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $articleSource = $this->creerArticle($client, $entete, $etabAIri, '5901234123457');
        $articleDestination = $this->creerArticle($client, $entete, $etabBIri, '40170725');

        $this->receptionner($client, $entete, $etabAIri, $articleSource['id'], '20.000', '6.0000');

        $transfert = $client->request('POST', '/api/stock_transferts', $entete + [
            'json' => [
                'articleStockSource' => '/api/article_stocks/' . $articleSource['id'],
                'articleStockDestination' => '/api/article_stocks/' . $articleDestination['id'],
                'quantite' => '8.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('demande', $transfert['statut']);

        $client->request('POST', '/api/stock/transferts/' . $transfert['id'] . '/expedier', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $lotSource = $em->getRepository(LotStock::class)->findOneBy(['articleStock' => $articleSource['id']]);
        self::assertNotNull($lotSource);
        self::assertSame('12.000', $lotSource->getQuantiteRestante(), 'Expédition : 20 − 8 = 12 restantes côté source.');

        $client->request('POST', '/api/stock/transferts/' . $transfert['id'] . '/recevoir', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $transfertApres = $client->request('GET', '/api/stock_transferts/' . $transfert['id'], $entete)->toArray();
        self::assertSame('recu', $transfertApres['statut']);

        $em->clear();
        $lotDestination = $em->getRepository(LotStock::class)->findOneBy(['articleStock' => $articleDestination['id']]);
        self::assertNotNull($lotDestination);
        self::assertSame('8.000', $lotDestination->getQuantiteInitiale());
        self::assertSame('6.0000', $lotDestination->getCoutUnitaireHT(), 'Coût transféré = coût de la couche consommée à la source, pas un nouveau prix d\'achat.');
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerArticle(object $client, array $entete, string $etabIri, string $ean): array
    {
        // D41 : l'etablissement d'une creation vient de la session serveur. L'IRI recue sert
        // desormais a se PLACER dans l'etablissement vise, au lieu de le nommer dans un corps ou il
        // serait ignore. Les appelants n'ont pas a changer.
        $entete['headers'][ContexteEtablissement::HEADER] = basename($etabIri);
        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'codeEAN' => $ean,
                'libelle' => 'Mug boutique',
                'unite' => 'piece',
                'prixAchatHT' => '6.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '2.000',
                'seuilMax' => '30.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $article;
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function receptionner(object $client, array $entete, string $etabIri, string $articleId, string $quantite, string $prix): void
    {
        // D41 : meme raison que dans `creerArticle`.
        $entete['headers'][ContexteEtablissement::HEADER] = basename($etabIri);
        $fournisseur = $client->request('POST', '/api/stock_fournisseurs', $entete + [
            'json' => ['raisonSociale' => 'Grossiste Boutique SARL'],
        ])->toArray();

        $reception = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-01',
                'numeroBonLivraison' => 'BL-INIT',
            ],
        ])->toArray();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception['id'],
                'articleStock' => '/api/article_stocks/' . $articleId,
                'quantiteRecue' => $quantite,
                'prixAchatUnitaireHT' => $prix,
            ],
        ]);

        $client->request('POST', '/api/stock/receptions-achat/' . $reception['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }
}
