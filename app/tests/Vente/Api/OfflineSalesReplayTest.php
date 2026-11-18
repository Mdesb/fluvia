<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Audit\Entity\EntreeAudit;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Nf525\SignataireOperation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE TÉMOIN D'ACCEPTATION DU MODE HORS CONNEXION (#338) : couper le réseau, vendre trois titres,
 * rétablir. Les trois ventes sont en base, chaînées, une seule fois.
 *
 * Le poste rejoue son lot tant qu'il n'a pas lu de réponse : la coupure peut tomber PENDANT le
 * retour de la première. Le lot est donc envoyé deux fois, à l'identique, et c'est le second envoi
 * qui prouve l'anti-doublon.
 */
final class OfflineSalesReplayTest extends VenteApiTestCase
{
    public function testTroisVentesHorsLigneRejoueesDeuxFoisSontEnBaseChaineesUneSeuleFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $ouverture = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $heures = [];
        $operations = [];
        for ($n = 1; $n <= 3; ++$n) {
            // Les heures du comptoir : quelques secondes après l'ouverture, dans la tolérance d'horloge.
            $heures[$n] = $ouverture->modify(sprintf('+%d seconds', $n * 10))->format('Y-m-d\TH:i:s.v\Z');
            $operations[] = $this->operation($n, $heures[$n]);
        }
        $lot = ['session' => '/api/session_caisses/' . $session['id'], 'operations' => $operations];

        $premier = $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(3, $premier['inseres'], 'témoin positif : les trois ventes doivent être insérées au premier envoi');
        self::assertSame([], $premier['quarantaine']);
        self::assertSame([], $premier['reportees']);

        $second = $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame([], $second['inseres'], 'un lot rejoué ne crée rien');
        self::assertCount(3, $second['doublons']);
        // Le poste qui a perdu la première réponse apprend quand même les numéros définitifs.
        self::assertCount(3, $second['correspondances']);
        self::assertSame(
            array_column($premier['correspondances'], 'numero', 'numeroLocal'),
            array_column($second['correspondances'], 'numero', 'numeroLocal'),
        );

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        // EN BASE, UNE SEULE FOIS.
        $ventes = [];
        foreach ($operations as $op) {
            $trouvees = $em->getRepository(Vente::class)->findBy(['cleIdempotence' => Uuid::fromString($op['cleIdempotence'])]);
            self::assertCount(1, $trouvees, 'chaque vente du comptoir existe exactement une fois');
            $ventes[$op['sequenceLocale']] = $trouvees[0];
        }
        foreach ($ventes as $n => $vente) {
            self::assertTrue($vente->isOrigineHorsLigne());
            self::assertSame('validee', $vente->getStatut()->value, 'remontée = validée, donc scellée');
            // L'heure retenue est celle du comptoir, pas celle de la remontée.
            self::assertSame(
                (new \DateTimeImmutable($heures[$n]))->format('Y-m-d H:i:s'),
                $vente->getDate()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            );
        }

        // CHAÎNÉES : trois maillons consécutifs, dans l'ordre du comptoir, et la chaîne est intacte.
        $pdv = $em->getRepository(PointDeVente::class)->find($ventes[1]->getPointDeVente()?->getId());
        $chaine = $em->getRepository(OperationScellee::class)->findBy(['pointDeVente' => $pdv], ['numeroSequence' => 'ASC']);
        $maillons = [];
        foreach ($chaine as $op) {
            foreach ($ventes as $n => $vente) {
                if ($op->getCibleId()->equals($vente->getId())) {
                    $maillons[$n] = $op;
                }
            }
        }
        self::assertCount(3, $maillons, 'chaque vente est scellée une fois, et une seule');
        self::assertSame($maillons[1]->getNumeroSequence() + 1, $maillons[2]->getNumeroSequence());
        self::assertSame($maillons[2]->getNumeroSequence() + 1, $maillons[3]->getNumeroSequence());
        self::assertSame($maillons[1]->getEmpreinte(), $maillons[2]->getEmpreintePrecedente());
        self::assertSame($maillons[2]->getEmpreinte(), $maillons[3]->getEmpreintePrecedente());
        /** @var SignataireOperation $signataire */
        $signataire = static::getContainer()->get(SignataireOperation::class);
        self::assertTrue($signataire->verifieChaine($chaine)->intacte, 'la chaîne du point de vente reste vérifiable après la remontée');

        // LE TICKET PROVISOIRE SE RETROUVE : son numéro est au journal, à côté de la vente.
        foreach ($ventes as $n => $vente) {
            $trace = $em->getRepository(EntreeAudit::class)->findOneBy(['action' => 'vente.hors_ligne.remontee', 'cibleId' => (string) $vente->getId()]);
            self::assertInstanceOf(EntreeAudit::class, $trace);
            self::assertSame(sprintf('HL-TEST-%05d', $n), $trace->getValeurApres()['numeroLocal'] ?? null);
        }
    }

    /**
     * #369 / G-1 — UNE CHAÎNE EN RÉGRESSION DE CLÉ REFUSE L'ANCRAGE (409), comme toute chaîne rompue.
     *
     * Le dernier maillon perd son identifiant de clé et est re-signé SANS identifiant par la clé active : il
     * est chaîné et signé correctement, seule la règle d'ordre (« clé, puis aucun identifiant ») le trahit.
     * Témoin positif : le même lot, une fois le maillon rétabli, est ancré.
     */
    public function testUneChaineEnRegressionDeCleRefuseLAncrage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $cle = $_SERVER['NF525_SEAL_KEY'] ?? $_ENV['NF525_SEAL_KEY'] ?? getenv('NF525_SEAL_KEY');
        self::assertIsString($cle);

        $ancre = ['session' => '/api/session_caisses/' . $session['id'], 'operations' => [$this->operation(1, null), $this->operation(2, null)]];
        $client->request('POST', '/api/synchro/operations', $entete + ['json' => $ancre]);
        self::assertResponseIsSuccessful();

        $connexion = $this->em()->getConnection();
        /** @var array{id: string, empreinte: string, signature: string, seal_key_id: string} $dernier */
        $dernier = $connexion->fetchAssociative('SELECT id, empreinte, signature, seal_key_id FROM nf525_operation_scellee ORDER BY numero_sequence DESC LIMIT 1');
        self::assertNotFalse($dernier);
        self::assertNotSame('', (string) $dernier['seal_key_id'], 'témoin : le maillon porte l\'identifiant de sa clé');

        // La forge : sans identifiant, signé par la clé active.
        $connexion->executeStatement(
            'UPDATE nf525_operation_scellee SET seal_key_id = NULL, signature = ? WHERE id = ?',
            [hash_hmac('sha256', $dernier['empreinte'], $cle), $dernier['id']],
        );
        $this->em()->clear();

        $lot = ['session' => '/api/session_caisses/' . $session['id'], 'operations' => [$this->operation(3, null)]];
        $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Chaîne serveur rompue', $client->getResponse()->toArray(false)['detail'] ?? '');
        self::assertSame(
            0,
            (int) $connexion->fetchOne('SELECT COUNT(*) FROM vente_vente WHERE cle_idempotence = ?', [Uuid::fromString($lot['operations'][0]['cleIdempotence'])->toBinary()]),
            'rien n\'est ancré sur une chaîne en régression',
        );

        // Témoin positif : le maillon rétabli, le même lot est ancré.
        $connexion->executeStatement(
            'UPDATE nf525_operation_scellee SET seal_key_id = ?, signature = ? WHERE id = ?',
            [$dernier['seal_key_id'], $dernier['signature'], $dernier['id']],
        );
        $this->em()->clear();
        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $reponse['inseres']);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * UNE HEURE DU POSTE HORS DE LA SESSION N'EST PAS CRUE — MAIS LA VENTE N'EST PAS PERDUE.
     *
     * Antidater une vente d'avant l'ouverture de la caisse la ferait tomber dans une autre journée
     * comptable. On garde la vente (l'argent est dans le tiroir), à l'heure du serveur, et on le dit.
     */
    public function testUneHeureHorsDeLaSessionEstEcarteeEtTracee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $avantOuverture = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $op = $this->operation(1, $avantOuverture);
        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [$op],
        ]])->toArray();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $reponse['inseres'], 'la vente est enregistrée malgré l heure refusée');
        self::assertCount(1, $reponse['anomalies']);
        self::assertStringContainsString('hors de la session', $reponse['anomalies'][0]['raison']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $vente = $em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => Uuid::fromString($op['cleIdempotence'])]);
        self::assertInstanceOf(Vente::class, $vente);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $vente->getDate(), 'l heure retenue est celle du serveur');
        self::assertNotNull($em->getRepository(EntreeAudit::class)->findOneBy(['action' => 'vente.hors_ligne.horodatage_ecarte', 'cibleId' => (string) $vente->getId()]));
    }

    /**
     * DEUX CLIENTS ET UN TÉMOIN : la session d'un établissement ne reçoit pas le lot d'un autre.
     */
    public function testLaSessionDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $lot = ['session' => '/api/session_caisses/' . $session['id'], 'operations' => [$this->operation(1, null)]];

        $surB = $entete;
        $surB['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $client->request('POST', '/api/synchro/operations', $surB + ['json' => $lot]);
        self::assertResponseStatusCodeSame(404);

        // Témoin : le même lot, sur son établissement, passe.
        $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function operation(int $sequence, ?string $heure): array
    {
        $op = [
            'cleIdempotence' => (string) Uuid::v4(),
            'sequenceLocale' => $sequence,
            'numeroLocal' => sprintf('HL-TEST-%05d', $sequence),
            'lignes' => [[
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ]],
            'paiements' => [['moyen' => 'especes', 'montant' => '45.00']],
        ];
        if ($heure !== null) {
            $op['horodatageLocal'] = $heure;
        }

        return $op;
    }
}
