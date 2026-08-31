<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Padel\Entity\PlageHoraire;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D41 — le client ne choisit pas l'etablissement de ce qu'il cree.
 *
 * **Ce que ce test protege.** `PlageHoraire` acceptait `etablissement` en ecriture : un administrateur de A
 * pouvait creer une plage horaire **dans l'etablissement B**. La victime l'aurait vu apparaitre dans ses propres
 * ecrans, puisque ses lectures sont filtrees sur son perimetre — donc sans jamais comprendre d'ou il
 * venait.
 *
 * On envoie deliberement l'IRI de B. Le champ n'etant plus denormalise, il est **ignore** : ce n'est
 * pas un refus, c'est une absence d'effet, et c'est pour cela qu'un test est necessaire — rien dans la
 * reponse ne signale que le client n'a pas ete ecoute. Verifie rouge avant le correctif : l'entite
 * atterrissait bien chez B.
 */
final class EtablissementNonChoisiTest extends PadelApiTestCase
{
    public function testLeClientNePeutPasCreerDansUnAutreEtablissement(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);
        $idB = (string) $etabB->getId();
        self::assertNotSame($idA, $idB, 'Le montage du test suppose deux etablissements distincts.');

        $client->request('POST', '/api/padel_plage_horaires', $entete + [
            'json' => ['etablissement' => '/api/etablissements/' . $idB, 'libelle' => 'creuse', 'joursApplicables' => [3]],
        ]);
        self::assertResponseIsSuccessful();

        $id = $client->getResponse()->toArray()['id'];
        $em->clear();
        $cree = $em->getRepository(PlageHoraire::class)->find($id);
        self::assertInstanceOf(PlageHoraire::class, $cree);

        self::assertSame(
            $idA,
            (string) $cree->getEtablissement()?->getId(),
            'D41 : l etablissement vient de la session serveur, pas du corps de la requete.'
        );
    }
}
