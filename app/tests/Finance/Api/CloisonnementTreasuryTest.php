<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * D8 — tout Processor/extension qui résout une entité depuis un identifiant du corps ou de l'URL
 * **revérifie le périmètre serveur**, échec fermé. Tests de cloisonnement explicitement demandés par la
 * mission (§0.2 du plan).
 */
final class CloisonnementTreasuryTest extends TreasuryApiTestCase
{
    /** §0.2 — `establishment` d'un autre périmètre dans le corps de création de `BankAccount` -> 403, aucun compte créé (D8). */
    public function testEtablissementHorsPerimetreRefuse403(): void
    {
        $avant = \count($this->tousLesComptesDeA());

        // Opérateur affecté uniquement sur B, mais qui connaît/devine l'id de l'établissement A.
        [$client, $entete] = $this->operateurFinanceSur('Patinoire B', ['treasury_manage_account']);

        $reponse = $client->request('POST', '/api/bank_accounts', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'label' => 'Compte IDOR',
                'bic' => 'AGRIFRPP',
                'openingBalance' => '0.00',
                'openingBalanceDate' => '2026-08-01',
            ],
        ]);

        self::assertContains($reponse->getStatusCode(), [400, 403]);
        self::assertCount($avant, $this->tousLesComptesDeA(), 'Aucun compte bancaire ne doit avoir été créé hors périmètre.');
    }

    /** §0.2 point 1 — IDOR inter-profils sur `ledgerAccount`. */
    public function testLedgerAccountAutreProfilRefuse422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteB = $this->creerCompteComptableScopeSurB();

        $reponse = $client->request('POST', '/api/bank_accounts', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'ledgerAccount' => '/api/compte_comptables/' . $compteB->getId(),
                'label' => 'Compte IDOR profil',
                'bic' => 'AGRIFRPP',
                'openingBalance' => '0.00',
                'openingBalanceDate' => '2026-08-01',
            ],
        ]);

        // Echec ferme. Le refus vient desormais du deserialiseur, qui ne peut pas resoudre l'IRI
        // d'une ressource hors de l'etablissement actif : il rend 400 la ou le processeur rendait
        // 404. Les deux disent « introuvable » et aucun ne confirme l'existence -- c'est
        // l'indistinguabilite qui est la propriete, pas le nombre. Ce qui reste interdit : 403,
        // qui confirmerait, et 2xx, qui servirait.
        self::assertContains($reponse->getStatusCode(), [400, 422]);
    }

    /** §0.2 point 2 — chaîne à deux sauts, `BankStatementLine` d'un autre établissement absente de la collection. */
    public function testLigneRelevesHorsPerimetreInvisible(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $compteA = $this->creerCompteBancaire($clientA, $enteteA);
        $importA = $this->creerImportManuel($clientA, $enteteA, $compteA['id']);
        $ligneA = $this->creerLigneManuelle($clientA, $enteteA, $importA['id'], '2026-08-10', 'Ligne A', '100.00');

        [$clientOperateurB, $enteteOperateurB] = $this->operateurFinanceSur('Patinoire B', ['read']);

        $collection = $clientOperateurB->request('GET', '/api/bank_statement_lines', $enteteOperateurB)->toArray();
        $idsVisibles = array_map(
            static fn (array $membre): string => basename((string) $membre['@id']),
            $collection['member'] ?? [],
        );
        self::assertNotContains($ligneA['id'], $idsVisibles, 'La ligne de relevé de A ne doit pas fuiter en collection pour un opérateur limité à B.');

        $reponseItem = $clientOperateurB->request('GET', '/api/bank_statement_lines/' . $ligneA['id'], $enteteOperateurB);
        self::assertContains($reponseItem->getStatusCode(), [403, 404], 'Fuite cross-tenant en lecture item (D8).');
    }

    /** @return list<array<string, mixed>> */
    private function tousLesComptesDeA(): array
    {
        [$client, $entete] = $this->adminSurA();

        return $client->request('GET', '/api/bank_accounts', $entete)->toArray()['member'] ?? [];
    }

    private function creerCompteComptableScopeSurB(): \App\Compta\Entity\CompteComptable
    {
        $em = $this->em();
        $etabB = $this->entite(Etablissement::class, ['nom' => 'Patinoire B']);

        $profil = new ProfilExploitant();
        $profil->setType(TypeExploitant::RegieDirecte);
        $profil->setReferentielComptable(ReferentielComptable::M57);
        $profil->setSiren('888888888');
        $profil->setEtablissementPrincipal($etabB);
        $em->persist($profil);

        $compte = new \App\Compta\Entity\CompteComptable();
        $compte->setProfilExploitant($profil);
        $compte->setNumero('512999');
        $compte->setLibelle('Banque B (hors périmètre A)');
        $compte->setSens(\App\Compta\Enum\SensCompte::Debit);
        $em->persist($compte);
        $em->flush();

        return $compte;
    }
}
