<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Enum\PerimetreAutorisation;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Platform\Event\DomainEvent;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Finance\ExpenseReportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * CA-8 (US-EXP-09) + D6/D7 — `expense_report.submitted`/`.approved`/`.reimbursed` publiés
 * **synchrones, dans la transaction** de l'action qui les déclenche, avec un
 * `tenant.establishmentId` dérivé de `ExpenseReport.establishment`, **jamais** de
 * `ContexteEtablissement` (même patron que `SupplierInvoiceEventTest`, FIN-2).
 */
final class ExpenseReportEventTest extends ExpenseReportApiTestCase
{
    public function testSubmittedEmisAvantEvaluationAutorisation(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, false);

        [$client, $entete, $idEmploye] = $this->salarie('expense.event-submitted@itcotation.com');
        $client->disableReboot();
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');

        $captures = $this->capturerEvenements(['expense_report.submitted']);

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        // `expense_report.submitted` est émis **avant** l'appel à `ServiceAutorisation` (RG-EXP-01.1) :
        // il est bien publié même dans la branche `Refuse` (montant > plafond, pas d'escalade permise),
        // preuve indirecte de l'ordre d'émission.
        self::assertSame('rejected', $reponse->toArray(false)['status']);
        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('expense_report.submitted', $evenement->name->value);
        self::assertSame($note['id'], $evenement->subject->id);
        self::assertSame('ExpenseReport', $evenement->subject->type);
        self::assertSame($idEmploye, $evenement->payload['employeeId']);
        self::assertSame(25000, $evenement->payload['amountCents']);
    }

    public function testTenantDeriveDeLEtablissementNoteJamaisDuContexte(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.event-tenant@itcotation.com');
        $client->disableReboot();
        $idEtablissementA = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);
        $idEtablissementB = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_B_NOM);
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '40.00');

        // `is_granted('PERM', 'finance.expense_report_submit')` (sécurité API Platform de l'opération
        // /submit) résout les codes effectifs via `ContexteEtablissement::idActif()` — sans `Affectation`
        // sur B, la requête serait refusée (403) avant même d'atteindre le handler, ce qui ne testerait
        // rien du tout côté D6. On accorde donc une `Affectation` supplémentaire sur B (même rôle) pour
        // isoler strictement la question testée ici : le tenant de l'événement reste A (établissement
        // RÉEL de la note), jamais B (contexte HTTP actif) — même patron que
        // `SupplierInvoiceEventTest::testTenantDeriveDeLEtablissementFactureJamaisDuContexte` (FIN-2, qui
        // utilise l'admin socle, affecté sur A ET B).
        $em = $this->em();
        $etabB = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idEtablissementB);
        $utilisateur = $em->getRepository(\App\Securite\Entity\Utilisateur::class)->findOneBy(['email' => 'expense.event-tenant@itcotation.com']);
        $role = $em->getRepository(\App\Securite\Entity\Role::class)->findOneBy(['nom' => \App\Finance\DataFixtures\ExpenseReportFixtures::ROLE_SALARIE]);
        $em->persist((new \App\Securite\Entity\Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        // En-tête `X-Etablissement` délibérément différent de `ExpenseReport.establishment` (A) : un
        // salarié multi-établissement dont le sélecteur front est resté sur B. Note : avec
        // `PerimetreAutorisation::Global`, le plafond RG-AUTZ-05 n'entre pas en jeu (§0.7 point 14 du
        // plan, point d'intégration front distinct) — seul le tenant de l'événement est vérifié ici.
        $enteteContexteB = $entete;
        $enteteContexteB['headers'][ContexteEtablissement::HEADER] = $idEtablissementB;

        $captures = $this->capturerEvenements(['expense_report.submitted']);

        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $enteteContexteB + ['json' => []]);

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame($idEtablissementA, $evenement->tenant->establishmentId->toRfc4122());
        self::assertNotSame($idEtablissementB, $evenement->tenant->establishmentId->toRfc4122());
    }

    public function testApprovedEmisApresAutorisationSousPlafond(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.event-approved@itcotation.com');
        $client->disableReboot();
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '40.00');

        $captures = $this->capturerEvenements(['expense_report.approved']);
        $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertCount(1, $captures, 'expense_report.approved doit être émis exactement une fois, dans la branche Autorise.');
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame(4000, $evenement->payload['amountCents']);
        // §0.7 / RG-AUTZ-13 : auto-approbation sous plafond -> approverId null, jamais le salarié soumettant.
        self::assertNull($evenement->payload['approverId'], 'Sous plafond = auto-approuvé : approverId doit être null.');
    }

    public function testSubmittedRollbackSiUnAbonneLeveUneException(): void
    {
        $this->configurerLimite('1000.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.event-rollback@itcotation.com');
        $client->disableReboot();
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '40.00');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $listener = static function (): void {
            throw new \RuntimeException('Abonné en échec (submitted).');
        };
        $dispatcher->addListener('expense_report.submitted', $listener);

        try {
            $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);
            self::assertSame(500, $reponse->getStatusCode());
        } finally {
            $dispatcher->removeListener('expense_report.submitted', $listener);
        }

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $rechargee = $em->getRepository(ExpenseReport::class)->find($note['id']);
        self::assertInstanceOf(ExpenseReport::class, $rechargee);
        self::assertSame('draft', $rechargee->getStatus()->value, 'Le statut ne doit pas basculer à `submitted` si l\'abonné a fait échouer la transaction (D7).');
    }

    /** @param list<string> $noms */
    private function capturerEvenements(array $noms): \ArrayObject
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        foreach ($noms as $nom) {
            $dispatcher->addListener($nom, static function (DomainEvent $event) use ($captures): void {
                $captures[] = $event;
            });
        }

        return $captures;
    }
}
