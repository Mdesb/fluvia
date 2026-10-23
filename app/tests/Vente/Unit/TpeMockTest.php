<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalAttempt;
use App\Vente\Tpe\TerminalOutcomeSink;
use App\Vente\Tpe\TpeMock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `TpeMock` était câblé dans tous les environnements : en production, une carte était « acceptée »
 * sans terminal, et l'en-tête `X-Tpe-Simule`, posé par n'importe quel appelant, choisissait l'issue.
 * Hors `TPE_SIMULE_AUTORISE` (posé en test et pour la démonstration en préprod), il refuse.
 *
 * Depuis le port asynchrone (spec TPE lot 1), il rend son issue par le chemin de l'issue, et « attente »
 * ne rend rien. Chaque branche a son témoin : un simulateur qui refuserait toujours passerait le
 * premier test, l'ancien comportement passerait le second, un simulateur qui répondrait toujours le
 * troisième.
 */
final class TpeMockTest extends TestCase
{
    /** @var list<array{string, ResultatTpe}> */
    private array $issues = [];

    public function testSansAutorisationLEnTeteNeForceRienEtLeRefusLeDit(): void
    {
        $tpe = new TpeMock($this->sink(), $this->requete('accepte'), false);

        try {
            $tpe->demarrer($this->tentative());
            self::fail('hors test et hors démonstration, le simulateur a accepté une demande');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('Aucun terminal de paiement configuré', $e->getMessage());
        }
        self::assertSame([], $this->issues, 'Aucune issue ne doit être livrée.');
    }

    public function testAvecAutorisationLEnTeteChoisitLIssue(): void
    {
        (new TpeMock($this->sink(), $this->requete('refuse'), true))->demarrer($tentative = $this->tentative());

        self::assertCount(1, $this->issues);
        self::assertSame([$tentative->id, StatutTPE::Refuse], [$this->issues[0][0], $this->issues[0][1]->statut]);
    }

    public function testEnAttenteLeSimulateurNeRepondPas(): void
    {
        $tpe = new TpeMock($this->sink(), $this->requete(TpeMock::NO_ANSWER), true);
        $tpe->demarrer($tentative = $this->tentative());

        self::assertSame([], $this->issues);
        self::assertSame([$tentative->id], $tpe->journal());
    }

    public function testUneIssueProgrammeeGardeSonMontantDebite(): void
    {
        $tpe = new TpeMock($this->sink(), $this->requete('refuse'), true);
        $tpe->programmer(new ResultatTpe(StatutTPE::Accepte, montantDebite: '30.00'));
        $tpe->demarrer($this->tentative());

        self::assertSame([StatutTPE::Accepte, '30.00'], [$this->issues[0][1]->statut, $this->issues[0][1]->montantDebite]);
        self::assertNotNull($this->issues[0][1]->reference, 'Une acceptation simulée porte un n° de transaction.');
    }

    private function sink(): TerminalOutcomeSink
    {
        $test = $this;

        return new class($test) implements TerminalOutcomeSink {
            public function __construct(private readonly TpeMockTest $test)
            {
            }

            public function record(string $attemptId, ResultatTpe $resultat, ?Uuid $declaredBy = null): ?array
            {
                $this->test->recu($attemptId, $resultat);

                return null;
            }
        };
    }

    /** @internal le chemin de l'issue simulé */
    public function recu(string $attemptId, ResultatTpe $resultat): void
    {
        $this->issues[] = [$attemptId, $resultat];
    }

    private function tentative(): TerminalAttempt
    {
        return new TerminalAttempt(bin2hex(Uuid::v4()->toBinary()), Uuid::v4(), 'cb', '45.00', new PointDeVente());
    }

    private function requete(string $issue): RequestStack
    {
        $pile = new RequestStack();
        $pile->push(new Request(server: ['HTTP_X_TPE_SIMULE' => $issue]));

        return $pile;
    }
}
