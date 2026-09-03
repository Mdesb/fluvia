<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Entity\Client as CrmClient;
use App\Crm\Enum\StatutClient;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;

/**
 * LA RECHERCHE DE CLIENTS RENDAIT LES FICHES ARCHIVÉES ET ANONYMISÉES.
 *
 * R24 : « les fiches anonymisées ne doivent pas s'afficher ». R25 : « idem pour les archivées ».
 * R26 : « prévoir des filtres à cocher », et — mon exigence à la revue — « quand un filtre masque
 * des fiches, l'écran doit le dire ».
 *
 * ⚠ CE TEST PORTE SUR `/crm/clients/recherche`, PAS SUR `/clients`, ET C'EST TOUT LE SUJET.
 *
 * J'avais d'abord écrit le même test contre la collection `/api/clients` : il passait, parce que le
 * `SearchFilter` d'API Platform sait très bien répondre à `statut[]=a&statut[]=b`. Mais **aucun
 * écran n'appelle cette collection.** Les six qui cherchent un client — la liste, le picker, le
 * pipeline, la recherche globale, l'abonnement sport — passent tous par `RechercheClientProvider`,
 * un fournisseur écrit à la main qui court-circuite le pipeline. Un test vert sur la porte à côté
 * aurait « prouvé » un comportement que le produit n'emprunte jamais.
 *
 * Ce que fait le fournisseur, lui :
 *
 *     $statut = (string) $request->query->get('statut', '');   une seule valeur
 *     if ($statut !== '') { c.statut = :statut }
 *     else                { c.statut != 'fusionne' }           les archivés et anonymisés PASSENT
 */
final class FiltreStatutClientTest extends CrmApiTestCase
{
    /** ⚠ LE DÉFAUT DE R24/R25 : par défaut, une fiche anonymisée et une archivée sortent. */
    public function testParDefautLesFichesArchiveesEtAnonymiseesNeSortentPas(): void
    {
        [$http, $entete] = $this->adminSurA();
        $this->poserUnClientParStatut();

        $rendus = $this->statutsRendus($http, $entete, '');

        self::assertNotContains('anonymise', $rendus, 'une fiche anonymisée ne doit pas s’afficher (R24)');
        self::assertNotContains('archive', $rendus, 'une fiche archivée ne doit pas s’afficher (R25)');
        self::assertNotContains('fusionne', $rendus, 'une fiche fusionnée ne s’affichait déjà pas');

        // ⚠ LE TÉMOIN. Sans lui, une réponse VIDE — cloisonnement, permission, fixture absente —
        // passerait les trois assertions ci-dessus en accusant le bon mécanisme pour la mauvaise
        // raison. Il faut prouver qu'on rend bien quelque chose avant de prouver ce qu'on écarte.
        self::assertContains('actif', $rendus, 'témoin : les fiches actives sortent toujours');
    }

    /** Et on peut redemander explicitement ce qui est masqué — R26, les cases à cocher. */
    public function testOnPeutDemanderPlUSIEURSStatutsExplicitement(): void
    {
        [$http, $entete] = $this->adminSurA();
        $this->poserUnClientParStatut();

        $rendus = $this->statutsRendus($http, $entete, '&statut[]=archive&statut[]=anonymise');
        sort($rendus);

        self::assertSame(
            ['anonymise', 'archive'],
            $rendus,
            'demander deux statuts doit rendre les deux, et EUX SEULS — un « statut » scalaire ne '
            . 'sait pas répondre à ça, et Symfony refuse même de lire un paramètre tableau avec get()',
        );
    }

    /** Une valeur seule continue de marcher : on ne casse pas l'appel existant. */
    public function testUneValeurSeuleFonctionneToujours(): void
    {
        [$http, $entete] = $this->adminSurA();
        $this->poserUnClientParStatut();

        self::assertSame(['archive'], $this->statutsRendus($http, $entete, '&statut=archive'));
    }

    /**
     * ⚠ L'ÉCRAN DOIT POUVOIR DIRE « 12 MASQUÉES », PAS SEULEMENT EN MONTRER MOINS.
     *
     * Sans ce compte, un filtre qui masque produit une liste plus courte et rien d'autre — et une
     * liste plus courte se lit « il y a moins de clients », pas « j'en cache ». C'est le mensonge
     * du zéro, à l'envers.
     */
    public function testLaReponseDitCombienDeFichesLeStatutAEcartees(): void
    {
        [$http, $entete] = $this->adminSurA();
        $this->poserUnClientParStatut();

        $corps = $http->request('GET', '/api/crm/clients/recherche?itemsPerPage=100', $entete)->toArray();

        self::assertArrayHasKey(
            'masquesParStatut',
            $corps,
            'la réponse doit porter le nombre de fiches écartées par le filtre de statut',
        );
        self::assertSame(3, $corps['masquesParStatut'], 'un archivé, un anonymisé, un fusionné');

        // Le témoin : quand on demande TOUT, il n'y a plus rien de masqué.
        $tout = $http->request(
            'GET',
            '/api/crm/clients/recherche?itemsPerPage=100&statut[]=actif&statut[]=inactif&statut[]=archive'
            . '&statut[]=anonymise&statut[]=fusionne',
            $entete,
        )->toArray();
        self::assertSame(0, $tout['masquesParStatut'], 'témoin : rien n’est masqué quand on demande tout');
    }

    /** @return list<string> les statuts distincts rendus par la recherche */
    private function statutsRendus(object $http, array $entete, string $queryEnPlus): array
    {
        $corps = $http->request('GET', '/api/crm/clients/recherche?itemsPerPage=100' . $queryEnPlus, $entete)
            ->toArray();

        return array_values(array_unique(array_map(
            static fn (array $c): string => $c['statut'] ?? '?',
            $corps['items'] ?? [],
        )));
    }

    /** Un client par statut, pour que la recherche ait les cinq sous la main. */
    private function poserUnClientParStatut(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var Etablissement $etablissement */
        $etablissement = $this->entite(Etablissement::class, ['nom' => 'Piscine A']);

        // ⚠ DEUX RELATIONS OBLIGATOIRES, ET AUCUNE NE SE DEVINE : `groupe` et
        // `etablissementCreation` sont `nullable: false`. Un client posé sans eux échoue au
        // `flush()`, pas à l'écriture. Le groupe se lit à travers la région du site.
        $groupe = $etablissement->getRegion()?->getGroupe();
        self::assertNotNull($groupe, 'témoin : le site d’épreuve doit être rattaché à un groupe');

        foreach (StatutClient::cases() as $i => $statut) {
            $em->persist(
                (new CrmClient())
                    ->setNom('Epreuve' . $i)
                    ->setPrenom($statut->value)
                    ->setGroupe($groupe)
                    ->setEtablissementCreation($etablissement)
                    ->setStatut($statut),
            );
        }
        $em->flush();
    }
}
