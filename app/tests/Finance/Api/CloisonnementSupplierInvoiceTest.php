<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Finance\FinanceApiTestCase;

/**
 * D8 — tout Processor qui résout une entité depuis un identifiant du corps **revérifie le périmètre
 * serveur**, échec fermé. Test de cloisonnement explicitement demandé par la mission.
 *
 * Les assertions comparent directement le code HTTP de la réponse retournée par `$client->request()`
 * (plutôt que `self::assertResponseStatusCodeSame()`, qui s'appuie sur le client « courant » statique de
 * `WebTestCase` — piégeux dès qu'un test instancie plusieurs clients, ce que `operateurFinanceSur()`/
 * `adminSurA()` font systématiquement).
 */
final class CloisonnementSupplierInvoiceTest extends FinanceApiTestCase
{
    public function testEtablissementHorsPerimetreRefuse(): void
    {
        $idFournisseurA = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $avant = \count($this->toutesLesFacturesDeA());

        // Opérateur affecté uniquement sur B, mais qui connaît/devine l'id de l'établissement A.
        [$client, $entete] = $this->operateurFinanceSur('Patinoire B', ['supplier_invoice_create']);

        $reponse = $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseurA,
                'supplierInvoiceNumber' => 'FACT-IDOR-001',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);

        // Échec fermé (noyau commun #2) : constaté empiriquement à 400 (« item not found », la
        // résolution de l'IRI `establishment` par `IriConverter` échoue en amont du processor, avant
        // même que `PerimetreEtablissementVerificateur::verifier()` ne soit atteint côté
        // `SupplierInvoiceProcessor` — celui-ci resterait la protection de dernier recours, en 403, si
        // la résolution d'IRI aboutissait par un autre chemin, ex. `find()` brut). Dans les deux cas :
        // aucune facture n'est créée hors périmètre.
        self::assertContains($reponse->getStatusCode(), [400, 403]);
        self::assertCount($avant, $this->toutesLesFacturesDeA(), 'Aucune facture ne doit avoir été créée hors périmètre.');
    }

    public function testBusinessProfileAutreEtablissementRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idFournisseurA = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        // Second profil exploitant, scopé **uniquement** sur B (IDOR inter-profils, §0.2 point 1 du plan).
        $profilB = $this->creerProfilScopeSurB();

        $reponse = $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $profilB->getId(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseurA,
                'supplierInvoiceNumber' => 'FACT-IDOR-002',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);

        // Le profil ne couvre pas l'établissement A -> 404 (échec fermé, ne révèle pas son existence).
        self::assertSame(404, $reponse->getStatusCode());
    }

    public function testFournisseurAutreEtablissementRefuse(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $idFournisseurA = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        $reponseOk = $clientA->request('POST', '/api/supplier_invoices', $enteteA + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseurA,
                'supplierInvoiceNumber' => 'FACT-OK-001',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);
        self::assertSame(201, $reponseOk->getStatusCode());

        // Même fournisseur (A) référencé alors que l'établissement actif du corps est B (le profil de
        // test couvre A **et** B, §0.2 : isole la vérification fournisseur de celle du profil).
        [$clientB, $enteteB] = $this->adminSurB();
        $reponseB = $clientB->request('POST', '/api/supplier_invoices', $enteteB + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Patinoire B'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseurA,
                'supplierInvoiceNumber' => 'FACT-OK-002',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);
        self::assertSame(404, $reponseB->getStatusCode());
    }

    public function testLigneSurFactureHorsPerimetreRefuse(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $idFournisseurA = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($clientA, $enteteA, $idFournisseurA, [], 'FACT-LIGNE-IDOR');

        // Un opérateur affecté uniquement sur B tente d'ajouter une ligne sur la facture de A.
        [$clientB, $enteteB] = $this->operateurFinanceSur('Patinoire B', ['supplier_invoice_create']);

        $reponse = $clientB->request('POST', '/api/supplier_invoice_lines', $enteteB + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'description' => 'Ligne intruse',
                'quantity' => '1.000',
                'unitPriceExclTax' => '1.00',
                'vatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
                'expenseNatureCode' => FinanceFixtures::EXPENSE_NATURE_MAPPEE,
            ],
        ]);

        // La facture référencée est hors du périmètre de l'opérateur B : la résolution de l'IRI par
        // `IriConverter` passe déjà par `PerimetreFinanceExtension` (§7 point 6 du plan — hypothèse
        // confirmée empiriquement dans ce dépôt) et échoue en amont du processor (400, « item not
        // found »). `SupplierInvoiceLineProcessor` porte tout de même sa propre revérification
        // explicite (D8 : jamais délégué à une extension Doctrine) pour les cas où la résolution d'IRI
        // aboutirait quand même (ex. `find()` brut, patron D8 explicitement visé par la mission).
        self::assertContains($reponse->getStatusCode(), [400, 404], 'Échec fermé attendu (ni la ligne ni la facture ne doivent être accessibles hors périmètre).');
    }

    /** @return list<array<string, mixed>> */
    private function toutesLesFacturesDeA(): array
    {
        [$client, $entete] = $this->adminSurA();

        return $client->request('GET', '/api/supplier_invoices', $entete)->toArray()['member'] ?? [];
    }

    private function creerProfilScopeSurB(): ProfilExploitant
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $this->entite(Etablissement::class, ['nom' => 'Patinoire B']);

        $profil = new ProfilExploitant();
        $profil->setType(TypeExploitant::RegieDirecte);
        $profil->setReferentielComptable(ReferentielComptable::M57);
        $profil->setSiren('999999999');
        $profil->setEtablissementPrincipal($etabB);
        $em->persist($profil);
        $em->flush();

        return $profil;
    }
}
