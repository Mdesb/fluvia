<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Service\GenerationRemiseHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * ⚠ UN MANDAT RÉVOQUÉ NE DOIT PLUS RIEN AUTORISER — ET PENDANT LONGTEMPS IL AUTORISAIT TOUT.
 *
 * `StatutMandatSepa` distingue `Actif` et `Revoque` depuis l'origine, et `CardDebitFallback` refusait
 * déjà un mandat non actif sur la bascule carte, avec le message « Un mandat révoqué ou suspendu
 * n'autorise plus rien ». Mais `GenerationRemiseHandler` ne lisait jamais ce statut, et ni
 * `SportEcheanceSepaSource` ni `CardFallbackDebtSource` ne filtrent dessus : le chemin principal du
 * prélèvement ignorait la révocation. Le garde protégeait la petite porte pendant que la grande
 * restait ouverte.
 *
 * **Ce test se lit en deux moitiés, et la première est la plus importante.**
 *
 * Une échéance peut être écartée d'une remise pour une raison qui n'a rien à voir avec le mandat :
 * le contrôle de préavis (PAY-2) écarte tout ce qui n'a pas été annoncé au débiteur. Un test qui se
 * contenterait de révoquer le mandat et de constater l'exclusion serait vert **même si mon filtre
 * n'existait pas** — l'absence de préavis suffirait à le rendre vert, et il affirmerait alors une
 * protection qu'il n'a jamais mesurée.
 *
 * La première moitié est donc un témoin positif : mandat ACTIF, même source, même référence, même
 * montant — la remise se compose, une ligne, zéro exclusion. Elle prouve que le préavis couvre bien
 * cette échéance et que le seul changement de la seconde moitié est le statut du mandat.
 *
 * Retirer le filtre de `GenerationRemiseHandler` fait tomber la seconde moitié, et elle seule.
 */
final class RemiseMandatRevoqueTest extends SepaApiTestCase
{
    public function testUnMandatRevoqueSortDeLaRemiseEtLeMotifLeDit(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var GenerationRemiseHandler $handler */
        $handler = static::getContainer()->get(GenerationRemiseHandler::class);

        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['rum' => 'RUM-DEMO-REGIE-0001']);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        $etablissement = $mandat->getEtablissement();
        self::assertInstanceOf(Etablissement::class, $etablissement);

        // La source rejoue exactement l'échéance de démonstration : c'est celle que le préavis des
        // fixtures couvre (référence et montant identiques), et c'est ce qui rend le témoin positif
        // ci-dessous possible.
        $source = $this->sourceDUneEcheance($mandat);

        // ─── Moitié 1 : LE TÉMOIN POSITIF. Sans elle, ce test ne mesurerait rien. ───────────────
        self::assertSame(
            StatutMandatSepa::Actif,
            $mandat->getStatut(),
            'Le mandat de démonstration doit partir actif, sinon la moitié 2 ne prouve plus rien.',
        );

        $remise = $handler->generer($etablissement, new \DateTimeImmutable('today'), $source);
        self::assertInstanceOf(RemiseSepa::class, $remise);
        self::assertSame(
            1,
            $remise->getNbTxs(),
            'Mandat actif et préavis couvrant : la remise doit se composer. Si elle échoue ici, '
            .'l’exclusion de la moitié 2 ne sera pas attribuable au statut du mandat.',
        );
        self::assertSame(
            0,
            $remise->getNbExclues(),
            'Rien ne doit être écarté tant que le mandat est actif — surtout pas faute de préavis.',
        );

        // ─── Moitié 2 : on ne change QUE le statut. ────────────────────────────────────────────
        $mandat->setStatut(StatutMandatSepa::Revoque);
        $em->flush();

        try {
            $handler->generer($etablissement, new \DateTimeImmutable('today'), $source);
            self::fail(
                'Un mandat révoqué a été prélevé. La remise s’est composée alors que le débiteur '
                .'avait retiré son autorisation : c’est un prélèvement sans mandat.',
            );
        } catch (UnprocessableEntityHttpException $e) {
            // Tout est écarté, donc la remise est vide : le handler lève, et le message doit nommer
            // la vraie cause. S'il disait encore « faute de préavis », il enverrait chercher un
            // préavis manquant là où un mandat est révoqué.
            self::assertStringContainsString(
                'mandat non actif',
                $e->getMessage(),
                'Le motif d’exclusion ne nomme pas le mandat : le message renverra vers la mauvaise cause.',
            );
            self::assertStringContainsString(
                StatutMandatSepa::Revoque->value,
                $e->getMessage(),
                'Le motif doit porter l’état réel du mandat, pas seulement « non actif ».',
            );
            self::assertStringNotContainsString(
                'écartée(s) faute de préavis',
                $e->getMessage(),
                'Le message affirme encore que le préavis est la seule cause d’exclusion. Il ne l’est plus.',
            );
        }
    }

    private function sourceDUneEcheance(MandatSepa $mandat): EcheanceSepaSource
    {
        return new class($mandat) implements EcheanceSepaSource {
            public function __construct(private readonly MandatSepa $mandat)
            {
            }

            public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
            {
                return [
                    new EcheanceSepaDue(
                        referenceOrigine: 'demo-regie-1',
                        mandatId: $this->mandat->getId(),
                        montantCentimes: 6300,
                        libelle: 'Abonnement piscine démo 1',
                        dateEcheance: $dateExecution,
                    ),
                ];
            }

            public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
            {
                // Aucun échéancier de verticale à mettre à jour : ce test mesure le filtre, pas la
                // boucle de retour vers le métier.
            }
        };
    }
}
