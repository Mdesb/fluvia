<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\TpeMock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LE TERMINAL SIMULÉ NE RÉPOND QUE LÀ OÙ ON L'A AUTORISÉ (décision de Maxime du 07/10).
 *
 * `TpeMock` était câblé dans tous les environnements : en production, une carte était « acceptée »
 * sans terminal, et l'en-tête `X-Tpe-Simule`, posé par n'importe quel appelant, choisissait l'issue.
 * Hors `TPE_SIMULE_AUTORISE` (posé en test et pour la démonstration en préprod), il refuse.
 *
 * Chaque branche a son témoin : un simulateur qui refuserait toujours passerait le premier test,
 * l'ancien comportement passerait le second.
 */
final class TpeMockTest extends TestCase
{
    public function testSansAutorisationLEnTeteNeForceRienEtLeRefusLeDit(): void
    {
        $tpe = new TpeMock($this->requete('accepte'), false);

        try {
            $resultat = $tpe->demander(new PointDeVente(), '45.00');
            self::fail(sprintf('hors test et hors démonstration, le simulateur a répondu « %s »', $resultat->statut->value));
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('Aucun terminal de paiement configuré', $e->getMessage());
        }
    }

    public function testAvecAutorisationLEnTeteChoisitLIssue(): void
    {
        $resultat = (new TpeMock($this->requete('refuse'), true))->demander(new PointDeVente(), '45.00');

        self::assertSame(StatutTPE::Refuse, $resultat->statut);
    }

    private function requete(string $issue): RequestStack
    {
        $pile = new RequestStack();
        $pile->push(new Request(server: ['HTTP_X_TPE_SIMULE' => $issue]));

        return $pile;
    }
}
