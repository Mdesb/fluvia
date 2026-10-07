<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Saison;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA SAISON D'UN CLIENT NE DOIT PAS EMPÊCHER CELLE D'UN AUTRE.
 *
 * Trouvé en exerçant une écriture que personne n'exerçait : un `PATCH` au corps vide — qui ne change
 * rien mais traverse tout le pipeline — répondait 422 sur `/api/saisons` :
 *
 *     dateDebut: Cette saison en chevauche une autre de même priorité :
 *                « Saison patinoire éphémère démo »
 *
 * La saison patchée était celle de Piscine A ; celle qu'on lui opposait n'était pas la sienne.
 *
 * `SaisonSansChevauchementValidator` cherchait sans borne — `findBy(['actif' => true])` — alors que
 * l'entité déclare dans son propre commentaire « D51 — entièrement cloisonné ». Deux conséquences :
 *
 *   1. un exploitant ne peut pas créer sa saison d'hiver parce qu'un AUTRE CLIENT en a une aux
 *      mêmes dates, et rien dans son écran ne montre ce qui bloque ;
 *   2. le message NOMME la saison de l'autre — une fuite de cloisonnement par le texte d'erreur.
 *
 * La seconde est la plus grave, et c'est celle qu'un test de comptage n'aurait jamais vue : la
 * fuite ne passe pas par une collection, elle passe par une chaîne de caractères.
 */
final class SaisonCloisonnementTest extends OffreApiTestCase
{
    private const CHEZ_LE_VOISIN = 'Saison du voisin, mêmes dates';

    /**
     * Une priorité que la saison des fixtures (priorité 0 chez A, jusqu'à la fin de l'an prochain)
     * n'occupe pas : ces saisons ne butent que l'une sur l'autre, quelle que soit l'année du lancement.
     */
    private const PRIORITE = 7;

    public function testUneSaisonDUnAutreEtablissementNEmpechePasLaCreation(): void
    {
        $this->saisonChezB();

        [$client, $token, $idA] = $this->adminSurA();
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Mêmes dates, même priorité que celle de B — mais sur un autre établissement.
        $client->request('POST', '/api/saisons', $enteteA + [
            'json' => [
                'nom' => 'Saison hiver de A',
                'dateDebut' => '2027-01-01',
                'dateFin' => '2027-03-31',
                'priorite' => self::PRIORITE,
            ],
        ]);

        self::assertSame(
            201,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(false),
        );
    }

    /**
     * LE MESSAGE NE DOIT PAS NOMMER CE QU'ON N'A PAS LE DROIT DE VOIR.
     *
     * Une assertion sur le seul code de statut aurait laissé passer la fuite : le refus PEUT être
     * légitime pour une autre raison, et c'est alors le CONTENU du message qui trahit. Ce test vaut
     * même si un jour la règle change et refuse à nouveau.
     */
    public function testLeRefusNeNommeJamaisLaSaisonDUnAutreEtablissement(): void
    {
        $this->saisonChezB();

        [$client, $token, $idA] = $this->adminSurA();
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('POST', '/api/saisons', $enteteA + [
            'json' => [
                'nom' => 'Saison hiver de A',
                'dateDebut' => '2027-01-01',
                'dateFin' => '2027-03-31',
                'priorite' => self::PRIORITE,
            ],
        ]);

        self::assertStringNotContainsString(
            self::CHEZ_LE_VOISIN,
            (string) $client->getResponse()->getContent(false),
            'le nom d’une saison d’un autre établissement apparaît dans la réponse',
        );
    }

    /**
     * LA RÈGLE CONTINUE DE S'APPLIQUER CHEZ SOI.
     *
     * Sans cette vérification, remplacer le validateur par « ne refuse jamais rien » passerait les
     * deux tests précédents. Le sens sûr de l'erreur est celui qui restreint — mais encore faut-il
     * qu'il reste quelque chose qui restreigne.
     */
    public function testDeuxSaisonsQuiSeChevauchentSurLeMemeSiteRestentRefusees(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $corps = static fn (string $nom): array => [
            'nom' => $nom,
            'dateDebut' => '2028-01-01',
            'dateFin' => '2028-03-31',
            'priorite' => self::PRIORITE,
        ];

        $client->request('POST', '/api/saisons', $enteteA + ['json' => $corps('Première chez A')]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), 'la première doit passer');

        $client->request('POST', '/api/saisons', $enteteA + ['json' => $corps('Seconde chez A')]);
        self::assertSame(
            422,
            $client->getResponse()->getStatusCode(),
            'deux saisons superposées sur le MÊME site doivent rester refusées',
        );
    }

    private function saisonChezB(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        /** @var Etablissement $etabB */
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);

        $saison = (new Saison())
            ->setNom(self::CHEZ_LE_VOISIN)
            ->setDateDebut(new \DateTimeImmutable('2027-01-01'))
            ->setDateFin(new \DateTimeImmutable('2027-03-31'))
            ->setPriorite(self::PRIORITE)
            ->setActif(true)
            ->setEtablissement($etabB);

        $em->persist($saison);
        $em->flush();
    }
}
