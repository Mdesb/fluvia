<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * Journal d'audit exploitable (US-L7-09, RG-M8-05) : valeurs avant/après, filtres, export CSV.
 */
final class AuditAvantApresTest extends SecuriteApiTestCase
{
    /** CA-14 — Une modification d'entité surveillée produit une EntreeAudit avec valeurAvant/valeurApres. */
    public function testCa14ValeursAvantApres(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idRoleLecteur = $this->idRole('Lecture seule');

        $client->request('PATCH', '/api/roles/' . $idRoleLecteur, $this->entetePatch($entete) + [
            'json' => ['nom' => 'Lecture seule (renommé)'],
        ]);
        self::assertResponseIsSuccessful();

        $audits = $client->request('GET', '/api/entree_audits', $entete + [
            'query' => ['action' => 'modification', 'cibleType' => \App\Securite\Entity\Role::class],
        ]);
        self::assertResponseIsSuccessful();
        $membres = $audits->toArray()['member'] ?? $audits->toArray()['hydra:member'];
        self::assertNotEmpty($membres);

        $entree = $membres[0];
        self::assertSame('Lecture seule', $entree['valeurAvant']['nom'] ?? null);
        self::assertSame('Lecture seule (renommé)', $entree['valeurApres']['nom'] ?? null);
    }

    /** CA-14 (création) — avant=null, après=capture de la nouvelle entité. Champs sensibles exclus. */
    public function testCa14CreationEtChampsSensiblesExclus(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/utilisateurs', $entete + [
            'json' => ['email' => 'audit.creation@itcotation.com', 'nom' => 'Audit Création'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $audits = $client->request('GET', '/api/entree_audits', $entete + [
            'query' => ['action' => 'creation', 'cibleType' => \App\Securite\Entity\Utilisateur::class],
        ]);
        $membres = $audits->toArray()['member'] ?? $audits->toArray()['hydra:member'];
        $entreeCreation = null;
        foreach ($membres as $m) {
            if (($m['valeurApres']['email'] ?? null) === 'audit.creation@itcotation.com') {
                $entreeCreation = $m;
                break;
            }
        }
        self::assertNotNull($entreeCreation);
        self::assertNull($entreeCreation['valeurAvant']);
        self::assertArrayNotHasKey('motDePasse', $entreeCreation['valeurApres']);
        self::assertArrayNotHasKey('jetonInvitation', $entreeCreation['valeurApres']);
    }

    /** CA-15 — Filtres serveur (auteur, action, cibleType, période) et export CSV exploitable. */
    public function testCa15FiltresEtExport(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/groupes', $entete + ['json' => ['nom' => 'Groupe Audit Filtre']]);
        self::assertResponseStatusCodeSame(201);

        $filtre = $client->request('GET', '/api/entree_audits', $entete + [
            'query' => ['auteur' => SocleFixtures::ADMIN_EMAIL, 'action' => 'creation', 'cibleType' => \App\Organisation\Entity\Groupe::class],
        ]);
        self::assertResponseIsSuccessful();
        $total = $filtre->toArray()['totalItems'] ?? $filtre->toArray()['hydra:totalItems'] ?? 0;
        self::assertGreaterThan(0, $total);

        // Période : borne dans le futur ⇒ aucun résultat.
        $futur = $client->request('GET', '/api/entree_audits', $entete + [
            'query' => ['dateHeure' => ['after' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)]],
        ]);
        self::assertResponseIsSuccessful();
        $totalFutur = $futur->toArray()['totalItems'] ?? $futur->toArray()['hydra:totalItems'] ?? 0;
        self::assertSame(0, $totalFutur);

        // Export CSV.
        $export = $client->request('GET', '/audit/export', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $export->getHeaders()['content-type'][0] ?? '');
        $contenu = $export->getContent();
        self::assertStringContainsString('id;dateHeure;auteur;action;cibleType;cibleId;etablissement', $contenu);
    }

    /** L'export est refusé sans permission securite.gerer/securite.exporter. */
    public function testExportRefusePourLectureSeule(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        // Le lecteur a *.lire (wildcard) qui couvre securite.lire mais pas securite.gerer/exporter.
        $client->request('GET', '/audit/export', ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(403);
    }
}
