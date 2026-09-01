<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Scheduling\ScheduleCatalog;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * LE VERROU DU PREMIER PASSAGE TIENT-IL VRAIMENT ? — D91.
 *
 * Une tache jamais executee et non marquee sure rattrape tout son retard en une fois. Le catalogue
 * en compte quatorze, dont `dms:purge-expired-documents`, `crm:rgpd:appliquer-conservation`,
 * `subscription:facturer-le-mois` et `padel:eclairage:commander`. Le verrou existe pour ca.
 *
 * ── POURQUOI CE TEST N'EXISTAIT PAS, ET CE QUE CA A COUTE ───────────────────────────────────────
 *
 * Le verrou lisait `$supervise = is_string($only) && $only !== ''` : « lance avec --only » valait
 * « regarde par un humain ». Or `infra/ordonnanceur.sh` appelle --only POUR CHAQUE TACHE, A CHAQUE
 * CYCLE. Le verrou etait donc court-circuite en permanence, depuis sa naissance, et **aucun test ne
 * le disait parce qu'aucun test ne le regardait**. Mesure par `allaccess-b8` le 01/09.
 *
 * Ce qui protegeait reellement, c'etait la liste blanche du shell — pas ce verrou. Les deux avaient
 * l'air complementaires ; en realite l'un desactivait l'autre.
 *
 * ── CE QUE CE TEST MESURE, ET COMMENT ───────────────────────────────────────────────────────────
 *
 * `--dry-run` : rien ne s'execute, donc le test ne declenche ni purge ni facturation. Et la garde du
 * premier passage est evaluee AVANT `--dry-run`, donc la sortie distingue exactement les deux
 * issues : « premier passage » quand le verrou retient, « due » quand il laisse passer.
 *
 * ⚠ LE TEMOIN POSITIF EST OBLIGATOIRE ICI. Sans lui, ce test passerait aussi bien si la commande
 * n'affichait plus jamais « due » — pour une raison sans aucun rapport avec le verrou.
 */
final class VerrouPremierPassageTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        SchemaDuHarnais::reinitialiser($em);

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    public function testUneTacheNonSureNePartPasQuandLOrdonnanceurLAppelleAvecOnly(): void
    {
        $sortie = $this->executer(['--only' => $this->uneTacheNonSure(), '--dry-run' => true]);

        self::assertStringContainsString('premier passage', $sortie);
        self::assertStringNotContainsString('due', $sortie);
    }

    /**
     * LE TEMOIN POSITIF. Le meme appel, plus `--supervise`, DOIT laisser passer — sinon le test
     * ci-dessus serait vert pour une raison qui n'a rien a voir avec le verrou.
     */
    public function testLaMemeTachePartQuandLaSupervisionEstRevendiquee(): void
    {
        $sortie = $this->executer([
            '--only' => $this->uneTacheNonSure(),
            '--dry-run' => true,
            '--supervise' => true,
        ]);

        self::assertStringContainsString('due', $sortie);
        self::assertStringNotContainsString('premier passage', $sortie);
    }

    /**
     * D53 : le message d'echec ne met pas en avant son propre contournement. Celui-la le faisait —
     * il imprimait `--only=<tache>`, c'est-a-dire exactement la porte qui s'ouvrait toute seule.
     */
    public function testLeMessageNAnnoncePasCommentLeverLeVerrou(): void
    {
        $sortie = $this->executer(['--only' => $this->uneTacheNonSure(), '--dry-run' => true]);

        self::assertStringNotContainsString('--supervise', $sortie);
        self::assertStringNotContainsString('--only=', $sortie);
    }

    /**
     * La tache d'epreuve est choisie DANS le catalogue, jamais ecrite en dur : une constante
     * deviendrait fausse le jour ou quelqu'un marquerait cette tache sure, et le test se mettrait a
     * mesurer autre chose sans changer de couleur.
     */
    private function uneTacheNonSure(): string
    {
        /** @var ScheduleCatalog $catalogue */
        $catalogue = static::getContainer()->get(ScheduleCatalog::class);

        foreach ($catalogue->all() as $tache) {
            if (!$tache->safeOnFirstRun) {
                return $tache->command;
            }
        }

        self::fail(
            "Aucune tache non sure au premier passage : ce test ne peut plus rien mesurer. "
            . "Si c'est voulu, il faut le retirer et dire pourquoi — pas le laisser vert a vide."
        );
    }

    /** @param array<string, mixed> $options */
    private function executer(array $options): string
    {
        $sortie = new BufferedOutput();
        $this->application->find('platform:scheduler:run')->run(
            new ArrayInput($options),
            $sortie,
        );

        return $sortie->fetch();
    }
}
