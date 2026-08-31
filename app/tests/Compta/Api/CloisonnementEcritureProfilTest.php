<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PEUT-ON ECRIRE DANS LA COMPTABILITE D'UN AUTRE EXPLOITANT EN DESIGNANT SON PROFIL ?
 *
 * ⚠ CE TEST EST NE ROUGE, ET C'EST AINSI QU'IL A PROUVE QUELQUE CHOSE.
 *
 * Quatre entites comptables — `CompteComptable`, `Journal`, `MappingComptable`, `TauxTva` —
 * portent leur `profilExploitant` dans le groupe d'ecriture avec `nullable: false` et sans aucun
 * processeur. Le `nullable: false` n'est pas un detail : il OBLIGE le client a designer le profil,
 * parce qu'il n'existe aucun autre moyen de creer la ligne. Et rien ne verifiait que ce profil
 * etait le sien.
 *
 * Les deux cloisonnements existants passaient a cote :
 *   — `AccountingScopeExtension` filtre les LECTURES ;
 *   — `EstablishmentScopeWriteGuard` ne regarde que les entites qui portent un `getEtablissement()`,
 *     et celles-ci portent un profil.
 *
 * ── ⚠ POURQUOI IL Y A UN CONTROLE POSITIF, ET POURQUOI IL PASSE EN PREMIER ──────────────────────
 *
 * Sans lui, ce test serait un piege a faux vert. Un `Journal` refuse pour un champ manquant rendrait
 * 422, un profil inexistant rendrait 404 — et les deux ressembleraient exactement au cloisonnement
 * qu'on cherche a prouver. On etablit donc D'ABORD que cette ecriture-la fonctionne avec son propre
 * profil. Un refus n'a de sens que mesure contre une acceptation.
 */
final class CloisonnementEcritureProfilTest extends ComptaApiTestCase
{
    public function testUnJournalNePeutPasEtreCreeSurLeProfilDunAutre(): void
    {
        [$client, $entete] = $this->adminSurA();

        // ── CONTROLE POSITIF : la meme ecriture, avec SON profil, doit aboutir ──────────────────
        $client->request('POST', '/api/journals', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'code' => 'ZZA',
                'libelle' => 'Journal de controle positif',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'temoin : creer un journal sur son propre profil doit reussir, sinon le refus mesure '
            . 'plus bas ne prouverait rien',
        );

        // ── LE PROFIL D'UN AUTRE ────────────────────────────────────────────────────────────────
        $idEtranger = $this->profilSurEtablissementB();

        // ⚠ LA LIGNE EXISTE-T-ELLE VRAIMENT ? Sans cette verification, un « Item not found » rendu
        // par l'API se confondrait avec « je n'ai pas reussi a fabriquer le profil ». Les deux
        // donnent la meme reponse, et l'un prouve un cloisonnement quand l'autre ne prouve rien.
        /** @var EntityManagerInterface $emControle */
        $emControle = static::getContainer()->get('doctrine')->getManager();
        $emControle->clear();
        self::assertNotNull(
            $emControle->getRepository(ProfilExploitant::class)->find($idEtranger),
            'temoin : le profil etranger doit EXISTER en base, sinon son inaccessibilite ne prouve rien',
        );

        $client->request('POST', '/api/journals', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $idEtranger,
                'code' => 'ZZB',
                'libelle' => 'Journal pose chez le voisin',
            ],
        ]);

        // ⚠ 400, ET J'ATTENDAIS 404. LA DIFFERENCE DIT PAR OU LE REFUS PASSE, ET C'EST TOUT LE SUJET.
        //
        // Je cherchais un refus de PERIMETRE — une garde qui lit le profil soumis et constate qu'il
        // n'est pas a nous. Il n'y en a pas. Le refus vient d'un cran plus tot : API Platform resout
        // « /api/profil_exploitants/{id} » en passant par le fournisseur d'item, lequel est cloisonne
        // par `AccountingScopeExtension`. Hors perimetre, l'IRI ne se resout pas, et la
        // deserialisation echoue sur « Item not found » — donc 400, corps malforme, avant toute
        // logique metier.
        //
        // ⚠ CE QUE CELA CHANGE : la lecture cloisonnee protege l'ecriture par ricochet, parce qu'on
        // ne peut pas NOMMER ce qu'on ne peut pas lire. L'analyse statique ne pouvait pas le voir :
        // elle cherchait une garde a l'ecriture, et la protection est ailleurs.
        //
        // ⚠ CE QUE CELA NE CHANGE PAS : cette protection est un EFFET DE BORD, pas une intention.
        // Rien ne la nomme, aucun test ne la tenait avant celui-ci, et elle tombe le jour ou
        // quelqu'un elargit la lecture des profils — un ecran d'administration groupe, un
        // `security:` assoupli — sans savoir qu'une ecriture en dependait. Ce test est la pour que
        // ce jour-la soit rouge.
        self::assertSame(
            400,
            $client->getResponse()->getStatusCode(),
            'un journal ne doit pas pouvoir etre cree sur le profil comptable d\'un autre exploitant',
        );

        // ⚠ ET ON VERIFIE QUE RIEN N'A ETE ECRIT. Un code de retour n'est pas une absence de ligne :
        // un processeur qui refuse APRES avoir persiste laisserait la trace qu'on croit avoir evitee.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        self::assertNull(
            $em->getRepository(\App\Compta\Entity\Journal::class)->findOneBy(['code' => 'ZZB']),
            'le refus doit aussi n\'avoir rien enregistre',
        );
    }

    /**
     * Un second profil comptable, rattache a l'etablissement B.
     *
     * Les fixtures n'en posent qu'un : sans voisin, il n'y a pas de cloisonnement a mesurer. On le
     * fabrique donc ici plutot que d'alourdir les fixtures de tout le depot pour un seul test.
     */
    private function profilSurEtablissementB(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB, 'temoin : l\'etablissement B doit exister');

        $profil = new ProfilExploitant();
        $profil->setSiren('999888777');
        $profil->setEtablissementPrincipal($etabB);

        $em->persist($profil);
        $em->flush();

        return (string) $profil->getId();
    }
}
