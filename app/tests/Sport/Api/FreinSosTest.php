<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Audit\Entity\EntreeAudit;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE FREIN DU BOUTON SOS — et surtout ce qu'il ne doit JAMAIS faire.
 *
 * ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────────────────
 *
 * `POST /sport/espaces/{id}/sos` est en `PUBLIC_ACCESS` — choix documenté et défendable : un boîtier
 * posé sur un mur, sans personnel, 24 h sur 24, ne peut pas se connecter. Mais il n'avait **aucun
 * frein** : qui a lu l'identifiant de l'espace une fois peut déclencher depuis n'importe où,
 * indéfiniment.
 *
 * ⚠ Le danger n'est pas la fausse alerte, c'est qu'un flot de fausses alertes **noie une vraie**. Un
 * système d'alerte dont le personnel a appris à se méfier ne protège plus personne.
 *
 * Relevé par allaccess-b8 ; décision de Maxime du 31/08 : poser un frein tout de suite, traiter le
 * jeton d'appareil plus tard.
 *
 * ── ⚠ LE TEST QUI COMPTE LE PLUS EST CELUI QUI DOIT PASSER ────────────────────────────────────
 *
 * Un frein sur un système d'urgence se juge sur ce qu'il **laisse passer**, pas sur ce qu'il
 * refuse. Une personne en détresse appuie une fois : si cet appel-là se perd, le frein a fait plus
 * de mal que le bruit qu'il empêche. Les deux premiers tests ci-dessous portent donc sur des appels
 * qui doivent aboutir, et le refus vient en dernier.
 */
final class FreinSosTest extends SportApiTestCase
{
    /**
     * ⚠ UN PREMIER APPEL NE SE PERD JAMAIS, MÊME APRÈS UN FLOT.
     *
     * C'est la règle qui distingue ce frein d'un limiteur ordinaire. Un espace silencieux depuis un
     * quart d'heure passe quoi qu'il arrive — quelle que soit l'adresse, quel que soit son budget.
     * Sans cela, une adresse déjà bavarde verrait son appel légitime jeté comme les autres.
     */
    public function testUnPremierAppelPasseTouours(): void
    {
        [$client] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        $client->request('POST', '/api/sport/espaces/'.$espaceId.'/sos', []);
        self::assertResponseIsSuccessful(
            'Le tout premier appel est refusé : le frein perd l’alerte qu’il devrait laisser passer.',
        );
    }

    /**
     * Plusieurs appuis rapprochés passent : c'est le comportement normal devant un bouton d'urgence.
     *
     * Quelqu'un qui panique appuie trois fois, ou doute que ça ait marché. Le punir serait absurde —
     * et c'est exactement ce qu'un limiteur trop serré ferait.
     */
    public function testPlusieursAppuisRapprochesPassent(): void
    {
        [$client] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        for ($i = 1; $i <= 3; $i++) {
            $client->request('POST', '/api/sport/espaces/'.$espaceId.'/sos', []);
            self::assertResponseIsSuccessful(sprintf(
                'L’appui n°%d est refusé : trois appuis rapprochés sont le comportement normal '
                .'devant un bouton d’urgence, pas un abus.',
                $i,
            ));
        }
    }

    /**
     * Le flot est écarté — ET la mise à l'écart laisse une trace.
     *
     * ⚠ SANS LA TRACE, ON AURAIT CHANGÉ DE PROBLÈME SANS LE SAVOIR : « on est noyé sous les fausses
     * alertes » deviendrait « on ne sait pas qu'on l'est », ce qui est pire parce que cela ne se
     * remarque pas.
     */
    public function testLeFlotEstEcarteEtLaisseUneTrace(): void
    {
        [$client] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        // ── TÉMOIN : les premiers passent. Sans lui, un frein qui refuserait TOUT rendrait
        // l'assertion suivante verte, et on aurait cassé le bouton en croyant l'avoir protégé.
        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/api/sport/espaces/'.$espaceId.'/sos', []);
            self::assertResponseIsSuccessful('témoin : les premiers appels doivent aboutir.');
        }

        $avant = $this->tracesEcartees();

        $client->request('POST', '/api/sport/espaces/'.$espaceId.'/sos', []);
        self::assertResponseStatusCodeSame(
            429,
            'Le quatrième appel en quelques secondes est accepté : rien n’empêche un flot de '
            .'fausses alertes de noyer une vraie.',
        );

        self::assertGreaterThan(
            $avant,
            $this->tracesEcartees(),
            'L’appel écarté ne laisse aucune trace : « on est noyé » devient « on ne sait pas '
            .'qu’on l’est », et personne ne s’en apercevra.',
        );
    }

    private function tracesEcartees(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return \count($em->getRepository(EntreeAudit::class)->findBy(['action' => 'sport.sos_ecarte']));
    }
}
