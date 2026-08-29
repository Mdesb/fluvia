<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Patinoire\Entity\Affutage;
use App\Tests\Patinoire\PatinoireApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Affûtage : prestation client vs maintenance du parc (US-PATIN-06/07, RG-PAT-06, décision actée). */
final class AffutageTest extends PatinoireApiTestCase
{
    public function testPrestationClientSansImpactParc(): void
    {
        [$client, $entete, $idA] = $this->technicienSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $avant = $client->getResponse()->toArray();

        [, $enteteTech] = [null, $entete];
        $client->request('GET', '/me', $entete);
        $idTechnicien = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/affutages', $entete + [
            'json' => [
                'type' => 'prestation_client',
                'technicien' => '/api/utilisateurs/' . $idTechnicien,
                'etablissement' => '/api/etablissements/' . $idA,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $affutage = $client->getResponse()->toArray();
        self::assertSame('prestation_client', $affutage['type']);
        self::assertSame('en_cours', $affutage['statut']);

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $apres = $client->getResponse()->toArray();
        self::assertSame($avant['quantiteDisponible'], $apres['quantiteDisponible'], 'CA-6 : aucun impact sur le parc de location.');

        $client->request('POST', '/api/patinoire/affutages/' . $affutage['id'] . '/terminer', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('termine', $client->getResponse()->toArray()['statut']);
    }

    public function testMaintenanceParcSortEtRentreDuDisponible(): void
    {
        [$client, $entete] = $this->technicienSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $avant = $client->getResponse()->toArray();
        self::assertSame(5, $avant['quantiteDisponible']);

        $client->request('GET', '/me', $entete);
        $idTechnicien = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/affutages', $entete + [
            'json' => [
                'type' => 'maintenance_parc',
                'technicien' => '/api/utilisateurs/' . $idTechnicien,
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $affutage = $client->getResponse()->toArray();
        self::assertSame('maintenance_parc', $affutage['type']);
        self::assertNull($affutage['ligneVente'] ?? null, 'CA-7 : aucune ligne de vente associée (opération interne).');

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $pendant = $client->getResponse()->toArray();
        self::assertSame(1, $pendant['quantiteEnAffutage']);
        self::assertSame(4, $pendant['quantiteDisponible'], 'CA-7 : sort du disponible dès la mise en affûtage.');

        $client->request('POST', '/api/patinoire/affutages/' . $affutage['id'] . '/terminer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $apres = $client->getResponse()->toArray();
        self::assertSame(0, $apres['quantiteEnAffutage']);
        self::assertSame(5, $apres['quantiteDisponible'], 'CA-7 : redevient disponible une fois « bon ».');
    }

    /**
     * L'etablissement d'un affutage vient de la session, et le corps n'a plus a le porter.
     *
     * **Ce que j'ai cru trouver, et ce qui est vrai.** La branche `prestation_client` resolvait
     * `etablissement` depuis le corps par un `find()` sans confrontation au perimetre — la forme
     * exacte d'une ecriture transfrontiere. J'ai ecrit ce test pour la demontrer : il a montre
     * l'inverse. Le champ est **deja** garde en amont, par la denormalisation d'API Platform, dans
     * ses deux formes : l'IRI d'un etablissement etranger donne 400 « Item not found » (la lecture
     * d'`Etablissement` etant cloisonnee), et un UUID nu donne 400 « Invalid IRI ». Le `find()` nu
     * n'est donc jamais atteint avec une reference etrangere.
     *
     * Le correctif reste juste, mais pour une autre raison que celle que je croyais : il retire une
     * **lecture brute du corps** portant sur un champ de perimetre — une forme qui ne doit pas
     * s'installer comme exemple — et il supprime un champ obligatoire que l'appelant ne pouvait de
     * toute facon renseigner qu'avec son propre etablissement. C'est de la simplification et de la
     * defense en profondeur, pas la fermeture d'une faille.
     */
    public function testLEtablissementVientDeLaSessionEtNonDuCorps(): void
    {
        [$client, $entete, $idA] = $this->technicienSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $client->request('GET', '/me', $entete);
        $idTechnicien = $client->getResponse()->toArray()['id'];

        // Aucun `etablissement` dans le corps : c'est le coeur du test.
        $client->request('POST', '/api/patinoire/affutages', $entete + [
            'json' => [
                'type' => 'prestation_client',
                'technicien' => '/api/utilisateurs/' . $idTechnicien,
            ],
        ]);
        self::assertResponseIsSuccessful('Le corps na plus a porter letablissement.');

        $id = $client->getResponse()->toArray()['id'];
        $em->clear();
        $affutage = $em->getRepository(Affutage::class)->find($id);
        self::assertInstanceOf(Affutage::class, $affutage);
        self::assertSame(
            $idA,
            (string) $affutage->getEtablissement()?->getId(),
            'L affutage est rattache a l etablissement actif de l appelant.'
        );
    }
}
