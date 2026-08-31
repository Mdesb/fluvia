<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Piscine\DataFixtures\PiscineFixtures;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN BASSIN EST NOMMÉ PARTOUT OÙ IL EST IDENTIFIÉ.
 *
 * ── LE DÉFAUT QUE CE FILET GARDE FERMÉ ──────────────────────────────────────────────────────────
 *
 *     Bassin::$id        bassin:read · poss:read · ligne:read · creneau:read
 *     Bassin::$libelle   bassin:read                                          ← amputé
 *
 * La relation sortait donc en objet — l'identifiant y était — mais **sans son nom**. `Piscine.jsx`
 * lisait `r.bassin?.libelle`, obtenait `undefined`, et repliait sur la fin de l'IRI :
 *
 *     texte(r.bassin?.libelle, String(r.bassin || '').split('/').pop() || '—')
 *
 * ⚠ **L'auteur savait.** Le repli est délibéré, et l'écran n'était pas cassé — il était DÉGRADÉ.
 * Sur un plan de surveillance de bassin, la différence entre « Grand bassin » et « 4f2a1c8e » n'est
 * pas cosmétique : c'est ce qu'un maître-nageur lit pour savoir où il est affecté.
 *
 * ── CE QUE CE TEST NE VÉRIFIE PAS ───────────────────────────────────────────────────────────────
 *
 * Il ne contrôle que `creneau:read`, parce que c'est la lecture qui a été relevée. `poss:read` et
 * `ligne:read` ont reçu le même groupe pour la même raison — leurs écrans n'ont pas été mesurés, et
 * je ne prétends pas qu'ils étaient fautifs.
 *
 * Relevé par le garde-fou n°32 d'`allaccess-c2`, qui compare les propriétés lues par le frontal aux
 * groupes que la relation porte réellement. Des trois lectures qu'il avait gelées, c'était la seule
 * vraie — les deux autres viennent d'objets résolus localement, vérifié sur la réponse du serveur.
 */
final class NomDuBassinTest extends PiscineApiTestCase
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** Le créneau sert le nom du bassin, pas seulement son identifiant. */
    public function testUnCreneauSertLeNomDuBassinEtPasSeulementSonIdentifiant(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var Bassin $bassin */
        $bassin = $this->em()->getRepository(Bassin::class)->findOneBy([]);
        self::assertNotNull($bassin, 'Témoin : le semis pose bien un bassin.');
        self::assertNotSame('', $bassin->getLibelle(), 'Témoin : ce bassin porte un nom.');

        $creneau = (new CreneauBassin())
            ->setBassin($bassin)
            ->setDebut(new \DateTimeImmutable('2026-09-01 10:00:00'))
            ->setFin(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $this->em()->persist($creneau);
        $this->em()->flush();

        $id = (string) $creneau->getId();
        $this->em()->clear();

        $rendu = $client->request('GET', '/api/creneau_bassins/' . $id, $entete)->toArray();

        self::assertIsArray($rendu['bassin'], 'Le bassin doit être servi en objet, pas en IRI nue.');
        self::assertArrayHasKey(
            'libelle',
            $rendu['bassin'],
            "Un bassin identifié doit être nommé : sans son libellé, l'écran replie sur l'identifiant."
        );
        self::assertSame($bassin->getLibelle(), $rendu['bassin']['libelle']);
    }
}
