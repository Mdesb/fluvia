<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * CQ-7 / D26 — « la validité après recharge se configure ». Se configure veut dire : par l'API du
 * produit-carte, par l'exploitant, sans intervention technique. C'est ce que vérifient ces tests —
 * l'effet de ce paramètre sur une recharge réelle relève du module Accès (voir le rapport de CQ-7).
 */
final class RechargeValidityTest extends OffreApiTestCase
{
    /** D26 — une carte créée sans rien préciser prolonge : le défaut est livré, pas à activer. */
    public function testDefautProlongationALaCreation(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $carte = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Carte sans paramètre de recharge'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_CARTE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
                'carte' => ['nbPaye' => 10, 'nbCredite' => 10],
            ],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('extend', $carte['carte']['rechargeValidityMode']);
    }

    /** L'option de D26 : l'exploitant qui préfère que la validité d'origine tienne le dit ici. */
    public function testConservationConfigurableEtRelue(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $cree = $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Carte à validité figée'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_CARTE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
                'carte' => [
                    'nbPaye' => 10,
                    'nbCredite' => 10,
                    'validiteDuree' => 'P1Y',
                    'rechargeValidityMode' => 'keep',
                ],
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('keep', $cree['carte']['rechargeValidityMode']);

        // Relu depuis la base, et pas seulement renvoyé par le POST : c'est la persistance de la
        // colonne qu'on vérifie, la sérialisation ne prouve rien à elle seule.
        $relu = $client->request('GET', '/api/produits/' . $cree['id'], $entete)->toArray();
        self::assertSame('keep', $relu['carte']['rechargeValidityMode']);
    }

    /**
     * Une valeur hors énumération est refusée — le paramètre est fermé, pas une chaîne libre.
     *
     * 400 et non 422 : le refus vient de la **dénormalisation** (« The data must belong to a backed
     * enumeration »), pas du validateur — la valeur n'atteint jamais l'entité. C'est vérifié, pas
     * supposé : les autres refus de ce module sont des 422, et une carte incohérente en est un.
     */
    public function testValeurHorsEnumerationRefusee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('POST', '/api/produits', $entete + [
            'json' => [
                'libelle' => ['fr' => 'Carte au mode inventé'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_CARTE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
                'carte' => ['nbPaye' => 10, 'nbCredite' => 10, 'rechargeValidityMode' => 'prolonger'],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
