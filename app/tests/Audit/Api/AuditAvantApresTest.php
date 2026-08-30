<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Entity\EntreeAudit;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
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

    /**
     * ⚠ L'EXPORT CSV NE DOIT PAS TRAVERSER LES ÉTABLISSEMENTS.
     *
     * `ResidualScopeExtension` cloisonne `EntreeAudit`, mais une extension Doctrine ne s'applique
     * qu'aux opérations d'API Platform : `ExportAuditController` construit sa requête à la main et
     * n'en bénéficiait pas. La collection JSON était bornée, l'export CSV de la même donnée ne
     * l'était par rien — et `securite.gerer` est porté par « Administrateur groupe », un rôle
     * CLIENT. Un administrateur de groupe exportait donc l'audit de tous les établissements.
     *
     * ⚠ ON LIT LE CONTENU, PAS LE STATUT. L'export répondait déjà 200 avant la correction : c'est
     * exactement le problème. Un test sur le code HTTP aurait été vert dans les deux versions.
     */
    public function testExportNeTraversePasLesEtablissements(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        self::assertNotSame($idA, $idB, 'témoin : deux établissements distincts sont nécessaires pour mesurer une traversée');
        $voisin = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idB));
        $soi = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idA));
        self::assertNotNull($voisin);
        self::assertNotNull($soi);

        // Une entrée chez le voisin, et une chez soi : la seconde est le témoin positif.
        $cibleVoisin = 'CIBLE-VOISIN-' . bin2hex(random_bytes(6));
        $cibleSoi = 'CIBLE-SOI-' . bin2hex(random_bytes(6));
        foreach ([[$voisin->getId(), $cibleVoisin], [$soi->getId(), $cibleSoi]] as [$etab, $cible]) {
            $entree = new EntreeAudit();
            $entree->setAction('creation')
                ->setCibleType('BancExport')
                ->setCibleId($cible)
                ->setEtablissement($etab);
            $em->persist($entree);
        }
        $em->flush();

        $export = $client->request('GET', '/audit/export', $entete);
        self::assertResponseIsSuccessful();
        $contenu = $export->getContent();

        self::assertStringContainsString(
            $cibleSoi,
            $contenu,
            'témoin positif : l’entrée de l’établissement actif doit figurer, sinon un export vide rendrait ce test vert sans rien prouver',
        );
        self::assertStringNotContainsString(
            $cibleVoisin,
            $contenu,
            'L’export CSV contient une entrée d’audit d’un AUTRE établissement : le contrôleur écrit à la main échappe au cloisonnement que la collection applique.',
        );
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
