<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\LettrageEcriture;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-FACT-04, RG-FACT-06 (CA-5) : un règlement total lettre la ligne « client » et solde la facture ;
 * un règlement partiel met à jour le solde sans lettrage.
 */
final class ReglementFactureApiTest extends FacturationApiTestCase
{
    public function testCa5ReglementTotalLettreEtPasse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
        self::assertSame('120.00', $emise['totalTTC']);

        $reponse = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/reglements', $entete + [
            'json' => ['montant' => '120.00', 'moyen' => 'virement', 'reference' => 'VIR-001'],
        ]);
        self::assertSame(201, $reponse->getStatusCode());

        $facture = $client->request('GET', '/api/factures/' . $brouillon['id'], $entete)->toArray();
        self::assertSame('payee', $facture['statut']);
        self::assertSame('0.00', $facture['soldeDu']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        // ⚠ CETTE ASSERTION ATTENDAIT 1, ET C'EST LE COMPORTEMENT D'AVANT QUI ÉTAIT FAUX.
        //
        // Le règlement lettrait la ligne 411 de la facture SEULE — contre rien. La créance était
        // marquée soldée alors qu'aucune écriture ne la soldait : la ligne restait débitrice au grand
        // livre, et la trésorerie n'y entrait jamais (0 écriture au journal `ENC`, mesuré en préprod
        // le 08/09). Un lettrage à une seule ligne ne rapproche rien ; il affirme.
        //
        // Depuis, l'encaissement produit sa propre écriture et les DEUX lignes 411 se lettrent
        // ensemble. On en attend donc deux — et surtout, on vérifie qu'elles portent le MÊME code de
        // rapprochement, ce qui est la seule chose qui prouve qu'elles ont été rapprochées l'une de
        // l'autre plutôt que lettrées chacune dans son coin.
        $lettrages = $em->getRepository(LettrageEcriture::class)->findAll();
        self::assertCount(2, $lettrages, 'La ligne de la facture et celle de l\'encaissement se lettrent ensemble (CA-5).');

        $codes = array_unique(array_map(static fn (LettrageEcriture $l): ?string => $l->getReconciliationCode(), $lettrages));
        self::assertCount(1, $codes, 'Les deux lignes doivent partager un seul code de rapprochement.');
        self::assertNotNull($codes[array_key_first($codes)], 'Un lettrage groupé porte toujours un code.');

        // Et la contrepartie de tout ça : le compte client revient réellement à zéro au grand livre,
        // ce qu'aucune assertion ne vérifiait — le solde affiché venait des règlements, pas des comptes.
        $solde411 = (int) $em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(l.debit_centimes) - SUM(l.credit_centimes), 0)
               FROM compta_ligne_ecriture l
               JOIN compta_compte_comptable c ON c.id = l.compte_id
              WHERE c.numero = ?',
            ['411000'],
        );
        self::assertSame(0, $solde411, 'La créance est éteinte AU GRAND LIVRE, pas seulement à l\'écran.');
    }

    public function testCa5ReglementPartielMetAJourSolde(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/reglements', $entete + [
            'json' => ['montant' => '50.00', 'moyen' => 'virement'],
        ]);

        $facture = $client->request('GET', '/api/factures/' . $brouillon['id'], $entete)->toArray();
        self::assertSame('partiellement_reglee', $facture['statut']);
        self::assertSame('70.00', $facture['soldeDu']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbLettrages = (int) $em->getRepository(LettrageEcriture::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        self::assertSame(0, $nbLettrages, 'Aucun lettrage tant que le solde n\'est pas à zéro (CA-5).');
    }
}
