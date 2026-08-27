<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Tests\Marketing\MarketingApiTestCase;

/**
 * UN SEGMENT DÉCRIT DES CLIENTS — donc tout se joue sur qui il a le droit de décrire.
 *
 * Un module de campagnes est un module qui LIT des fiches clients en masse et les compte. Une fuite
 * n'y produit pas d'erreur : elle produit un effectif un peu plus gros, et un échantillon où
 * l'exploitant ne reconnaît personne — ce qu'il mettra sur le compte d'un critère mal écrit.
 *
 * Ces tests portent donc d'abord sur le périmètre, ensuite sur les critères.
 */
final class SegmentTest extends MarketingApiTestCase
{
    /**
     * **L'effectif s'affiche avant l'envoi (RG-CMP-02).**
     *
     * Sans ce chiffre, l'exploitant découvre l'ampleur de son geste après l'avoir fait — et un envoi
     * ne se rattrape pas.
     */
    public function testUnSegmentRendSonEffectifEtUnEchantillon(): void
    {
        [$client, $entete] = $this->adminSurA();

        $segment = $this->segment('Tous les particuliers', [SegmentCriteria::TYPE => 'physique']);

        $apercu = $client->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $entete)
            ->toArray();

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $apercu['effectif'], 'Les fixtures CRM posent des clients physiques.');
        self::assertNotEmpty($apercu['echantillon'], 'Un effectif sans échantillon ne se vérifie pas.');
        self::assertFalse(
            $apercu['envoiReelDisponible'],
            'Aucun prestataire n’est branché : l’écran doit pouvoir le dire sans le deviner.',
        );
    }

    /**
     * **Le versant qui empêche la mesure de tout rendre.**
     *
     * Sans lui, un résolveur qui aurait cessé de filtrer passerait le test précédent. Un critère
     * impossible doit rendre zéro — pas « tout », pas une erreur.
     */
    public function testUnCritereQueRienNeSatisfaitRendZero(): void
    {
        [$client, $entete] = $this->adminSurA();

        $segment = $this->segment('Millionnaires', [SegmentCriteria::CA_CUMULE_MIN => 999_999]);

        $apercu = $client->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $entete)
            ->toArray();

        self::assertSame(0, $apercu['effectif']);
        self::assertSame([], $apercu['echantillon']);
    }

    /**
     * **CA-8, premier verrou : le SEGMENT d'un autre établissement est introuvable.**
     *
     * 404 et non 403, et le libellé ne fuit pas dans le refus. Distinguer « hors périmètre » de
     * « inexistant » permettrait d'énumérer l'activité du voisin en essayant des identifiants — on
     * apprendrait qu'un segment existe, et son nom dit souvent ce qu'un exploitant prépare.
     *
     * L'autorité est recalculée contre l'établissement du segment RÉSOLU. L'en-tête
     * `X-Etablissement` est un sélecteur envoyé par le client, pas une preuve d'appartenance (D6) :
     * s'y fier laisserait passer quiconque le change.
     */
    public function testUnSegmentDUnAutreEtablissementEstIntrouvable(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $segment = $this->segment('Tous les particuliers', [SegmentCriteria::TYPE => 'physique']);

        $vuDeA = $clientA->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $enteteA)
            ->toArray();
        self::assertGreaterThan(0, $vuDeA['effectif'], 'Sans cette assertion, le test passerait sur un fournisseur toujours vide.');

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
        self::assertStringNotContainsString(
            'Tous les particuliers',
            $reponse->getContent(false),
            'Le libellé du segment ne doit pas fuir dans le refus.',
        );
    }

    /**
     * **CA-8, second verrou : les CLIENTS restent bornés au périmètre du lecteur.**
     *
     * Les deux verrous ne se remplacent pas. Le premier protège le segment ; celui-ci protège les
     * clients. Un segment créé PAR le groupe B, avec exactement les mêmes critères, ne compte pas
     * les clients du groupe A — sinon il suffirait de recréer le segment chez soi pour compter la
     * clientèle du voisin.
     */
    public function testUnSegmentIdentiqueNeCompteQueLesClientsDeSonLecteur(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $segmentA = $this->segment('Tous les particuliers', [SegmentCriteria::TYPE => 'physique']);
        $vuDeA = $clientA->request('GET', '/api/marketing/segments/' . $segmentA->getId() . '/apercu', $enteteA)
            ->toArray()['effectif'];

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('POST', '/api/segments', $enteteB + [
            'json' => ['label' => 'Les mêmes, vus de B', 'criteria' => [SegmentCriteria::TYPE => 'physique']],
        ]);
        self::assertResponseStatusCodeSame(201);

        $vuDeB = $clientB->request(
            'GET',
            '/api/marketing/segments/' . $reponse->toArray()['id'] . '/apercu',
            $enteteB,
        )->toArray()['effectif'];

        self::assertLessThan($vuDeA, $vuDeB, 'Le groupe B ne compte pas les clients du groupe A.');
    }

    /**
     * **Un critère inconnu est refusé, pas ignoré.**
     *
     * Ignorer `derniereVisiteAvant` mal orthographié rendrait le segment PLUS LARGE que ce que
     * l'exploitant croit avoir écrit — et il l'apprendrait en écrivant à mille personnes.
     */
    public function testUnCritereInconnuEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/segments', $entete + [
            'json' => ['label' => 'Faute de frappe', 'criteria' => ['derniereVisiteAvant' => '2026-01-01']],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Un segment sans critère désigne tout le monde.**
     *
     * C'est le geste le plus dangereux du module, et il est trop facile : créer un segment, oublier
     * les critères, envoyer. Refusé à la saisie.
     */
    public function testUnSegmentSansCritereEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/segments', $entete + [
            'json' => ['label' => 'Vide', 'criteria' => []],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Une fiche fusionnée n'est jamais une cible.**
     *
     * Écrire à une fiche absorbée, c'est écrire deux fois à la même personne, sous deux identités.
     * L'exclusion est posée par le résolveur, avant les critères : aucun exploitant ne pense à
     * l'écrire, et l'oublier ne se remarque qu'à la réclamation du client.
     */
    public function testUneFicheFusionneeNEstJamaisCiblee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $segment = $this->segment('Tous les particuliers', [SegmentCriteria::TYPE => 'physique']);

        $avant = $client->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $entete)
            ->toArray()['effectif'];

        $em = $this->em();
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $payeur);
        $conjoint = $em->getRepository(Client::class)->findOneBy(['prenom' => CrmFixtures::CONJOINT_PRENOM]);
        self::assertInstanceOf(Client::class, $conjoint);

        $payeur->setFusionneDans($conjoint);
        $em->flush();

        $apres = $client->request('GET', '/api/marketing/segments/' . $segment->getId() . '/apercu', $entete)
            ->toArray()['effectif'];

        self::assertSame($avant - 1, $apres, 'La fiche absorbée sort de l’audience.');
    }

    // --- outillage --------------------------------------------------------------------------------

    /** @param array<string, mixed> $criteres */
    private function segment(string $libelle, array $criteres): Segment
    {
        $em = $this->em();
        $segment = (new Segment())
            ->setEstablishment($this->etablissementA())
            ->setLabel($libelle)
            ->setCriteria($criteres);
        $em->persist($segment);
        $em->flush();

        return $segment;
    }
}
