<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Entity\EntreeAudit;
use App\Audit\Service\ClientAuditRedactor;
use App\Audit\Service\JournalAudit;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #363 — le caviardage d'une fiche : ses entrées passent au marqueur, rien d'autre ne bouge.
 */
final class ClientAuditRedactionTest extends CrmApiTestCase
{
    public function testRedactReplacesIdentityOfTheCustomerOnlyAndTracesIt(): void
    {
        $this->adminSurA();
        $em = $this->em();
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $u1 = $this->createCustomer($establishment, 'Dupont', 'Alice');
        $u2 = $this->createCustomer($establishment, 'Martin', 'Bruno');
        $otherEntries = $this->otherEntries($u1, $u2);
        $id1 = (string) $u1->getId();

        // TÉMOIN POSITIF : la recherche des valeurs exactes trouve bien l'identité de U1 avant caviardage.
        $before = $this->jsonOf($id1);
        foreach (['Dupont', 'Alice', 'dupont@example.test', '0611223344'] as $value) {
            self::assertStringContainsString($value, $before, "témoin : « $value » doit être dans l'audit de U1 avant caviardage");
        }
        self::assertSame(2, $this->countEntries($id1, 'creation') + $this->countEntries($id1, 'modification'));

        $outsideRows = $this->rowsOutsideCustomer($id1);
        $u1Rows = $this->rowsOfCustomer($id1);

        $redactor = new ClientAuditRedactor($em, static::getContainer()->get(JournalAudit::class));
        $n = $redactor->redact($u1);
        $em->flush();

        self::assertSame(2, $n);
        $after = $this->jsonOf($id1);
        foreach (['Dupont', 'Alice', 'dupont@example.test', '0611223344'] as $value) {
            self::assertStringNotContainsString($value, $after);
        }
        self::assertStringContainsString(ClientAuditRedactor::MARKER, $after);

        // Clés, date, auteur, action inchangés ; champs non personnels intacts.
        $rowsAfter = $this->rowsOfCustomer($id1);
        self::assertSame(array_keys($u1Rows), array_keys($rowsAfter));
        foreach ($u1Rows as $id => $row) {
            foreach (['date_heure', 'auteur', 'action', 'cible_type', 'cible_id', 'etablissement'] as $col) {
                self::assertSame($row[$col], $rowsAfter[$id][$col], "colonne $col d'une entrée caviardée");
            }
            foreach (['valeur_avant', 'valeur_apres'] as $col) {
                $a = json_decode((string) $row[$col], true);
                $b = json_decode((string) $rowsAfter[$id][$col], true);
                self::assertSame(array_keys($a ?? []), array_keys($b ?? []), "clés de $col");
                foreach ($a ?? [] as $key => $value) {
                    if (!\in_array($key, ClientAuditRedactor::IDENTITY_FIELDS, true) || $value === null) {
                        self::assertSame($value, $b[$key], "le champ $key ne devait pas changer");
                    }
                }
            }
        }

        // U2, une autre entité (Famille), une entrée d'une autre classe aux clés d'identité : identiques ligne à ligne,
        // hors la trace seule, qui est la seule ligne nouvelle.
        $rowsOutsideAfter = $this->rowsOutsideCustomer($id1);
        foreach ($outsideRows as $id => $row) {
            self::assertSame($row, $rowsOutsideAfter[$id], 'une entrée hors de la fiche a changé');
        }
        self::assertCount(\count($outsideRows) + 1, $rowsOutsideAfter, 'seule la trace est ajoutée hors de la fiche');
        self::assertStringContainsString('Martin', $this->jsonOf((string) $u2->getId()), 'U2 intacte');
        self::assertStringContainsString('Dupont', (string) json_encode($otherEntries), 'témoin : l\'entrée étrangère garde ses clés d\'identité');

        // La trace.
        $traces = $em->getRepository(EntreeAudit::class)->findBy(['action' => 'crm.rgpd.caviardage', 'cibleId' => $id1]);
        self::assertCount(1, $traces);
        self::assertSame('Client', $traces[0]->getCibleType());
        self::assertSame(['entrees' => 2], $traces[0]->getValeurApres());
        self::assertSame((string) $establishment->getId(), (string) $traces[0]->getEtablissement());
        self::assertStringNotContainsString('Dupont', (string) json_encode([$traces[0]->getValeurAvant(), $traces[0]->getValeurApres(), $traces[0]->getAuteur()]));
    }

    public function testRedactReadsACustomerWithManyEntriesInBatches(): void
    {
        $this->adminSurA();
        $em = $this->em();
        $u1 = $this->createCustomer($em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]), 'Dupont', 'Alice');
        $id1 = (string) $u1->getId();
        $journal = static::getContainer()->get(JournalAudit::class);
        for ($i = 0; $i < 600; ++$i) {
            $journal->enregistrer('modification', Client::class, $id1, null, 'test')->setValeurApres(['nom' => 'Dupont', 'statut' => 'actif']);
        }
        $em->flush();
        $em->clear();
        $u1 = $em->getRepository(Client::class)->find($id1);

        // Témoin positif : 602 entrées portent l'identité, plus d'un lot de 500.
        self::assertSame(602, $this->countEntries($id1, 'creation') + $this->countEntries($id1, 'modification'));
        $redactor = new ClientAuditRedactor($em, $journal);
        self::assertSame(602, $redactor->countRedactable($id1));

        self::assertSame(602, $redactor->redact($u1));
        $em->flush();

        self::assertStringNotContainsString('Dupont', $this->jsonOf($id1));
        self::assertSame(0, $redactor->countRedactable($id1));
        $trace = $em->getRepository(EntreeAudit::class)->findOneBy(['action' => 'crm.rgpd.caviardage', 'cibleId' => $id1]);
        self::assertSame(['entrees' => 602], $trace?->getValeurApres());
    }

    public function testRedactIsIdempotentAndWritesNoSecondTrace(): void
    {
        $this->adminSurA();
        $em = $this->em();
        $u1 = $this->createCustomer($em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]), 'Dupont', 'Alice');
        $redactor = new ClientAuditRedactor($em, static::getContainer()->get(JournalAudit::class));

        self::assertSame(2, $redactor->redact($u1));
        $em->flush();
        $rows = $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entree');

        self::assertSame(0, $redactor->redact($u1));
        $em->flush();
        self::assertSame($rows, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entree'), 'un second passage n\'écrit aucune ligne');
    }

    public function testCountRedactableReadsOnly(): void
    {
        $this->adminSurA();
        $em = $this->em();
        $u1 = $this->createCustomer($em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]), 'Dupont', 'Alice');
        $id1 = (string) $u1->getId();
        $redactor = new ClientAuditRedactor($em, static::getContainer()->get(JournalAudit::class));

        $before = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree ORDER BY id');
        self::assertSame(2, $redactor->countRedactable($id1));
        $em->flush();
        self::assertSame($before, $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree ORDER BY id'), 'countRedactable ne doit rien écrire');

        $redactor->redact($u1);
        $em->flush();
        self::assertSame(0, $redactor->countRedactable($id1));
    }

    private function createCustomer(Etablissement $establishment, string $name, string $firstName): Client
    {
        $em = $this->em();
        $c = (new Client())
            ->setGroupe($establishment->getRegion()?->getGroupe())
            ->setEtablissementCreation($establishment)
            ->setNom($name)
            ->setPrenom($firstName)
            ->setEmail(strtolower($name) . '@example.test')
            ->setTelephone('0611223344');
        if ($name === 'Martin') {
            $c->setTelephone('0699887766');
        }
        $em->persist($c);
        $em->flush(); // entrée « creation »
        $c->setTelephone($name === 'Martin' ? '0600000001' : '0611223344');
        $c->setPrenom($firstName . 'x');
        $em->flush(); // entrée « modification »

        return $c;
    }

    /** @return array<string, mixed> */
    private function otherEntries(Client $u1, Client $payer): array
    {
        $em = $this->em();
        $family = (new Famille())->setLibelle('Famille Dupont')->setGroupe($u1->getGroupe())->setPayeurPrincipal($payer);
        $em->persist($family);
        $em->flush();
        $journal = static::getContainer()->get(JournalAudit::class);
        $e = $journal->enregistrer('operation', 'App\Vente\Nf525\Entity\OperationScellee', (string) $u1->getId(), null, 'tache:test');
        $e->setValeurApres(['nom' => 'Dupont', 'email' => 'dupont@example.test', 'montant' => '12.00']);
        $em->flush();

        return $e->getValeurApres() ?? [];
    }

    private function jsonOf(string $clientId): string
    {
        $out = [];
        foreach ($this->rowsOfCustomer($clientId) as $row) {
            // Le JSON stocké échappe l'Unicode (\u00e9) : on le relit avant de chercher une valeur.
            $out[] = json_encode([json_decode((string) $row['valeur_avant'], true), json_decode((string) $row['valeur_apres'], true)], JSON_UNESCAPED_UNICODE);
        }

        return implode('|', $out);
    }

    private function countEntries(string $clientId, string $action): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entree WHERE cible_type = ? AND cible_id = ? AND action = ?', [Client::class, $clientId, $action]);
    }

    /** @return array<string, array<string, mixed>> les entrées `Client` de la fiche, par id, hors trace */
    private function rowsOfCustomer(string $clientId): array
    {
        $out = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_type = ? AND cible_id = ? ORDER BY id', [Client::class, $clientId]) as $l) {
            $out[$l['id']] = $l;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsOutsideCustomer(string $clientId): array
    {
        $out = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE NOT (cible_type = ? AND cible_id = ?) ORDER BY id', [Client::class, $clientId]) as $l) {
            $out[$l['id']] = $l;
        }

        return $out;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
