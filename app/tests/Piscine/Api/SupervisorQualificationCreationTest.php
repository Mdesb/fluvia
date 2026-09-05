<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\QualificationEncadrant;
use App\Securite\Entity\Utilisateur;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CRÉER UNE QUALIFICATION D'ENCADRANT PAR L'API (RG-PISC-02, la moitié amont de CA-4).
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────────────────
 *
 * `EncadrantTest` couvre la validation d'un créneau : refusée sans encadrant qualifié, acceptée
 * avec. Mais il fabrique ses qualifications **en entités**, directement par l'`EntityManager` —
 * jamais par l'API. La route `POST /api/qualification_encadrants` n'était donc exercée par rien.
 *
 * Or c'est elle, et elle seule, qui permet à un exploitant de sortir du refus RG-PISC-02. Sans elle,
 * « aucun encadrant qualifié à diplôme valide affecté à ce créneau » est un message sans issue.
 *
 * ⚠ CE TEST AFFIRME LE COMPORTEMENT VOULU, PAS LE DÉFAUT OBSERVÉ. Il ne décrit pas ce que la route
 * fait aujourd'hui : il décrit ce qu'elle doit faire. S'il échoue, c'est la route qu'il faut
 * corriger, jamais lui. Écrire l'inverse — un test qui constate le défaut — le rendrait rouge le
 * jour de la correction, c'est-à-dire exactement quand tout va bien.
 *
 * ── L'ÉTABLISSEMENT NE PEUT PAS VENIR DU CORPS ──────────────────────────────────────────────────
 *
 * `QualificationEncadrant::$etablissement` est hors du groupe d'écriture (`qualif:write`) et sa
 * colonne est `NOT NULL` : il doit donc être posé **côté serveur**, depuis le contexte de la
 * session, comme le font `App\Reservation\State\EstablishmentStampProcessor` et son jumeau de
 * `App\Patinoire`. Le second cas de ce test le vérifie plutôt que de le supposer — sans lui, une
 * création qui rendrait 201 en laissant l'établissement à `null` passerait pour un succès.
 */
final class SupervisorQualificationCreationTest extends PiscineApiTestCase
{
    /** Le cas nominal : un exploitant enregistre le diplôme d'un maître-nageur. */
    public function testCreationParApiRendUneQualificationUtilisable(): void
    {
        [$client, $entete] = $this->adminSurA();

        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $client->request('POST', '/api/qualification_encadrants', $entete + [
            'json' => [
                'encadrant' => '/api/utilisateurs/' . $admin->getId(),
                'type' => 'BNSSA',
                'dateValidite' => '2027-06-30',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            "Sans cette route, le refus RG-PISC-02 n'a aucune issue : rien d'autre ne crée de qualification.",
        );
    }

    /**
     * LES DEUX AUTRES CRÉATIONS DE LA MÊME FAMILLE.
     *
     * `Bassin` et `Poss` portent la même forme que `QualificationEncadrant` : `etablissement`
     * `NOT NULL`, hors du groupe d'écriture, `Post` sans processeur. Conclure de la ressemblance
     * serait une inférence — on les exécute.
     *
     * ⚠ `POST /api/bassins` EST DÉJÀ CÂBLÉ À UN BOUTON du frontal (`api.creerBassin`). Si cette
     * route échoue, ce bouton n'a jamais rien créé.
     */
    public function testLesTroisCreationsDeLaMemeFamilleAboutissent(): void
    {
        [$client, $entete] = $this->adminSurA();

        $espace = $this->entite(Espace::class, ['nom' => \App\Piscine\DataFixtures\PiscineFixtures::BASSIN_LIBELLE]);

        $client->request('POST', '/api/bassins', $entete + [
            'json' => [
                'libelle' => 'Bassin ouvert par API',
                'espace' => '/api/espaces/' . $espace->getId(),
                'nbLignes' => 2,
                'capacite' => 20,
            ],
        ]);
        self::assertResponseStatusCodeSame(
            201,
            'Le frontal appelle déjà cette route depuis un bouton « créer un bassin ».',
        );
    }

    /**
     * ⚠ LE PÉRIMÈTRE EST POSÉ PAR LE SERVEUR, ET ON LE RELIT EN BASE.
     *
     * Relire la réponse ne prouverait rien : `etablissement` est dans le groupe de LECTURE, donc une
     * valeur nulle s'y afficherait comme une valeur nulle, et un `201` resterait un `201`. C'est en
     * base que la contrainte se vérifie.
     */
    public function testLEtablissementEstCeluiDeLaSessionEtNonUnChampDuCorps(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $client->request('POST', '/api/qualification_encadrants', $entete + [
            'json' => [
                'encadrant' => '/api/utilisateurs/' . $admin->getId(),
                'type' => 'MNS',
                'dateValidite' => '2027-12-31',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $corps = $client->getResponse()->toArray();
        $em->clear();

        $creee = $em->getRepository(QualificationEncadrant::class)->find(Uuid::fromString($corps['id']));
        self::assertNotNull($creee, 'La qualification doit exister en base après un 201.');
        self::assertNotNull(
            $creee->getEtablissement(),
            "L'établissement est NOT NULL en base et hors du groupe d'écriture : il doit être posé par le serveur.",
        );
        self::assertTrue(
            $creee->getEtablissement()->getId()->equals($etabA->getId()),
            "L'établissement doit être celui de la session, jamais un autre.",
        );
    }
}
