<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use PHPUnit\Framework\TestCase;

/**
 * **Ce test existe pour dire que PAY-2 n'est pas fini, et pour disparaître le jour où il le sera.**
 *
 * `CardDebitFallback` est écrit, testé, et **rien ne l'appelle**. Son déclencheur est le refus de
 * carte, qui vit dans `App\Vente` : c'est `PAY-3`, chez `claude-G`, et il n'a pas encore atterri.
 *
 * **Pourquoi épingler une absence plutôt que la mentionner dans un rapport.** Un rapport se lit une
 * fois. Ce dépôt contient des mécanismes complets qu'aucun appelant n'atteint — un garde de support
 * qu'aucune route n'invoque, un calculateur de prorata écrit pour un lot et jamais branché, un module
 * SEPA qui n'émettait aucune notification, une commande de préavis qui n'existait pas. Chacun avait
 * l'air terminé. **Le seul moyen de ne pas en livrer un de plus est que l'absence parle d'elle-même,
 * au moment où quelqu'un croira la fonctionnalité prête.**
 *
 * **Ce test échouera le jour où le service sera appelé**, et son message dira quoi faire :
 * supprimer ce fichier, parce que ce qu'il surveillait n'existe plus.
 *
 * ─── LE CONTRAT ATTENDU DE `App\Vente`, pour que `claude-G` n'ait pas à le deviner ────────────────
 *
 *   événement : `sale.card_payment_rejected`
 *   charge    : paymentId, saleId, amountCents, establishmentId, customerId, occurredAt
 *
 * `occurredAt` est **l'instant du refus**, jamais celui du traitement (D37) : un refus traité en
 * différé doit produire un préavis daté du refus, sinon le délai court à partir de la mauvaise date.
 *
 * Un événement et non un appel direct : `App\Sepa` ne doit pas dépendre de `App\Vente` (D2/D8). Et je
 * n'écris pas l'abonné avant que l'émetteur existe — le garde-fou des événements orphelins refuse un
 * abonné inerte, et il a raison : un abonné qui n'écoute rien est le même défaut vu de l'autre côté.
 */
final class CardDebitFallbackNonBrancheTest extends TestCase
{
    private const SERVICE = 'CardDebitFallback';

    /** Les répertoires où un appel légitime apparaîtrait le jour où PAY-3 atterrit. */
    private const SOURCES = __DIR__ . '/../../src';

    public function testRienNAppelleEncoreLaBascule(): void
    {
        $appelants = $this->fichiersCitant(self::SERVICE);

        self::assertSame(
            [],
            $appelants,
            "PAY-2 vient d'être branché — c'est une bonne nouvelle, et ce test a fini son travail.\n\n"
            . "Il existait pour signaler que `CardDebitFallback` n'était appelé par personne, faute du\n"
            . "refus de carte (PAY-3, App\\Vente). Ces fichiers l'appellent désormais :\n"
            . '  - ' . implode("\n  - ", $appelants) . "\n\n"
            . "À FAIRE : supprimez ce fichier de test. Vérifiez au passage que l'abonné passe bien\n"
            . "`occurredAt` — l'instant du REFUS et non celui du traitement (D37) —, sans quoi le délai\n"
            . 'de préavis courrait à partir de la mauvaise date.',
        );
    }

    /**
     * @return list<string> chemins relatifs des fichiers de `src/` qui citent le service, hors sa
     *                      propre déclaration
     */
    private function fichiersCitant(string $classe): array
    {
        $racine = realpath(self::SOURCES);
        self::assertIsString($racine, 'src/ introuvable');

        $trouves = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        foreach ($iterateur as $fichier) {
            if (!$fichier instanceof \SplFileInfo || 'php' !== $fichier->getExtension()) {
                continue;
            }

            $chemin = $fichier->getPathname();
            // Le service se nomme lui-même : ce n'est pas un appel.
            if (str_ends_with($chemin, 'Service' . \DIRECTORY_SEPARATOR . $classe . '.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($chemin), $classe)) {
                $trouves[] = str_replace($racine . \DIRECTORY_SEPARATOR, '', $chemin);
            }
        }

        sort($trouves);

        return $trouves;
    }
}
