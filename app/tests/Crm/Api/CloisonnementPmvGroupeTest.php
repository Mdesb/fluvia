<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * Non-régression du n°11 (D8, corrigé le 23/08), trouvé par claude-C en auditant le porte-monnaie.
 *
 * **Le défaut.** `ResolutionClientSoiTrait` sortait dès que l'appelant portait la permission complète,
 * **sans jamais regarder le client résolu**. Or les trois Providers concernés résolvent le client par
 * un `find()` direct, qui court-circuite `PerimetreCrmExtension`. Un porteur de `crm.pmv_lire` dans un
 * groupe lisait donc le solde, l'historique des mouvements et la fiche 360 d'un client d'un **autre
 * groupe**.
 *
 * **Pourquoi le groupe et non l'établissement.** Le cloisonnement CRM est volontairement posé au
 * groupe : un client est partagé entre les établissements d'un même groupe, ce qui est le
 * comportement attendu d'un CRM. La frontière franchie ici était donc la plus large du projet.
 *
 * **Les trois routes sont testées ensemble**, parce que le défaut était dans le trait qu'elles
 * partagent : une correction qui n'en couvrirait qu'une laisserait les deux autres ouvertes, et rien
 * ne le signalerait.
 *
 * **404 et non 403** : un 403 confirmerait l'existence du client dans un autre groupe, ce qui ferait
 * de ces routes un oracle d'énumération sur le fichier clients.
 */
final class CloisonnementPmvGroupeTest extends CrmApiTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function routesSensibles(): iterable
    {
        yield 'solde du porte-monnaie' => ['/api/clients/%s/pmv'];
        yield 'historique des mouvements' => ['/api/clients/%s/pmv/mouvements'];
        yield 'fiche client 360' => ['/api/clients/%s/fiche-360'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routesSensibles')]
    public function testUnAgentDunAutreGroupeNeLitNiLeSoldeNiLaFiche(string $gabarit): void
    {
        $idClientDuGroupeA = $this->idPayeur();

        [$client, $entete] = $this->agentSurGroupeB();

        $reponse = $client->request('GET', sprintf($gabarit, $idClientDuGroupeA), $entete);

        self::assertSame(
            404,
            $reponse->getStatusCode(),
            'Un client d\'un autre groupe doit être introuvable, jamais interdit : '
            . (string) $reponse->getContent(false),
        );
    }

    /**
     * Contrôle positif : sur son propre groupe, la même route répond. Sans lui, un 404 provoqué par
     * n'importe quelle autre cause — route absente, client inexistant — rendrait le test vert à tort.
     */
    public function testUnAgentDeSonPropreGroupeLitBienLeSolde(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/api/clients/' . $this->idPayeur() . '/pmv', $entete);

        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
    }
}
