<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Enum\Canal;
use App\Offre\Enum\Debtor;
use App\Offre\Enum\PaymentExpectation;
use PHPUnit\Framework\TestCase;

/**
 * D46-bis — **un canal ne peut pas exister sans déclarer son attente de paiement et son débiteur**.
 *
 * L'idéal serait que la donnée soit exigée à la construction ; une énumération PHP n'a pas de
 * constructeur, donc c'est impossible. Ce test est l'équivalent le plus proche : il parcourt
 * `Canal::cases()`, donc **il grandit tout seul**. Un canal ajouté sans être décrit dans les deux
 * `match` — qui n'ont volontairement pas de `default` — fait échouer ce test au lieu d'arriver en
 * production avec un comportement deviné.
 *
 * C'est le motif de la semaine appliqué à une énumération : un mécanisme qui dépend de la vigilance
 * n'est pas un mécanisme. Maxime a trouvé le trou en trois secondes parce qu'il connaît son métier ;
 * le prochain canal sera ajouté par quelqu'un qui ne le connaîtra pas.
 */
final class CanalContratTest extends TestCase
{
    /** Aucun canal ne peut échapper aux deux déclarations. */
    public function testChaqueCanalDeclareSonAttenteEtSonDebiteur(): void
    {
        foreach (Canal::cases() as $canal) {
            // `UnhandledMatchError` si le canal manque à l'un des deux `match` : l'échec est ici,
            // nommé, et non dans un écran de caisse six mois plus tard.
            self::assertInstanceOf(PaymentExpectation::class, $canal->attentePaiement(), $canal->value);
            self::assertInstanceOf(Debtor::class, $canal->debiteur(), $canal->value);
        }
    }

    /**
     * Le cas qui motive toute la décision : sur `ota`, le débiteur **n'est pas le client**.
     *
     * Le visiteur a payé son agence, et c'est l'agence qui reverse. Traiter une vente OTA non soldée
     * comme un impayé enverrait une relance à quelqu'un qui a déjà payé — le pire résultat possible
     * pour une fonction censée récupérer de l'argent.
     */
    public function testSeulLOtaFaitPayerLePartenaire(): void
    {
        self::assertSame(Debtor::Partner, Canal::Ota->debiteur());
        self::assertSame(PaymentExpectation::DeferredPooled, Canal::Ota->attentePaiement());

        foreach (Canal::cases() as $canal) {
            if ($canal === Canal::Ota) {
                continue;
            }
            self::assertSame(Debtor::Customer, $canal->debiteur(), $canal->value . ' doit être dû par le client.');
        }
    }

    /** Les trois attentes sont distinctes et chacune sert : sinon la propriété ne dirait rien. */
    public function testLesTroisAttentesSontToutesUtilisees(): void
    {
        $attentes = array_map(static fn (Canal $c): PaymentExpectation => $c->attentePaiement(), Canal::cases());

        self::assertContains(PaymentExpectation::Immediate, $attentes);
        self::assertContains(PaymentExpectation::AgreedTerm, $attentes, 'Le canal de gestion facture à terme convenu (D45-bis).');
        self::assertContains(PaymentExpectation::DeferredPooled, $attentes, 'L\'OTA reverse groupé et plus tard.');
    }
}
