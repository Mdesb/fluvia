<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261023130000;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 3) . '/migrations/Version20261023130000.php';

/**
 * CA-7 (spec TPE lot 1 §6) : les points de vente existants passent au terminal EXTERNE, marque et
 * référence gardées ; la descente rend chaque valeur à l'identique. On joue le SQL de la migration
 * elle-même sur toutes les formes rencontrées : absent, liste d'un terminal, liste vide, liste de
 * deux, et le booléen que l'écran Paramètres écrivait (« un terminal est rattaché »).
 *
 * La même migration a aussi été jouée dans les deux sens sur une copie de la sauvegarde de préprod.
 */
final class TerminalSettingsMigrationTest extends CrmApiTestCase
{
    public function testEveryPointOfSaleBecomesExternalAndTheDownRestoresIt(): void
    {
        $avant = [
            'absent' => null,
            'un' => [['marque' => 'ingenico', 'ref' => 'TPE-A1']],
            'vide' => [],
            'deux' => [['marque' => 'pax', 'ref' => 'P1'], ['marque' => 'nayax', 'ref' => 'N2']],
            'oui' => true,
            'non' => false,
            'deja' => ['connecteur' => 'externe', 'marque' => 'pax', 'ref' => 'X'],
        ];
        $ids = [];
        foreach ($avant as $nom => $tpe) {
            $ids[$nom] = $this->pointDeVente('Migration TPE ' . $nom);
            $this->ecrire($ids[$nom], $tpe);
        }

        $this->jouer('up');

        self::assertSame(['connecteur' => 'externe'], $this->lire($ids['absent']));
        self::assertSame(['connecteur' => 'externe', 'marque' => 'ingenico', 'ref' => 'TPE-A1', 'terminaux' => $avant['un']], $this->lire($ids['un']));
        self::assertSame(['connecteur' => 'externe', 'terminaux' => []], $this->lire($ids['vide']));
        self::assertSame(['connecteur' => 'externe', 'marque' => 'pax', 'ref' => 'P1', 'terminaux' => $avant['deux']], $this->lire($ids['deux']));
        self::assertSame(['connecteur' => 'externe', 'ancien' => true], $this->lire($ids['oui']));
        self::assertSame(['connecteur' => 'externe', 'ancien' => false], $this->lire($ids['non']));
        self::assertSame($avant['deja'], $this->lire($ids['deja']), 'Une valeur déjà structurée ne bouge pas.');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM caisse_point_de_vente WHERE tpe IS NULL OR JSON_VALUE(tpe, '$.connecteur') IS NULL",
        ), 'Aucun point de vente sans connecteur après la montée.');

        $this->jouer('down');

        foreach ($avant as $nom => $tpe) {
            self::assertSame($nom === 'deja' ? [['marque' => 'pax', 'ref' => 'X']] : $tpe, $this->lire($ids[$nom]), $nom);
        }
    }

    private function jouer(string $sens): void
    {
        $connexion = $this->em()->getConnection();
        $migration = new Version20261023130000($connexion, new NullLogger());
        $migration->{$sens}(new Schema());
        foreach ($migration->getSql() as $requete) {
            $connexion->executeStatement($requete->getStatement(), $requete->getParameters(), $requete->getTypes());
        }
    }

    private function pointDeVente(string $libelle): string
    {
        $em = $this->em();
        $pdv = (new PointDeVente())
            ->setLibelle($libelle)
            ->setEtablissement($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]));
        $em->persist($pdv);
        $em->flush();

        return bin2hex($pdv->getId()->toBinary());
    }

    private function ecrire(string $id, mixed $tpe): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE caisse_point_de_vente SET tpe = :t WHERE id = UNHEX(:id)',
            ['t' => $tpe === null ? null : json_encode($tpe), 'id' => $id],
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function lire(string $id): mixed
    {
        $brut = $this->em()->getConnection()->fetchOne('SELECT tpe FROM caisse_point_de_vente WHERE id = UNHEX(:id)', ['id' => $id]);

        return $brut === null ? null : json_decode((string) $brut, true);
    }
}
