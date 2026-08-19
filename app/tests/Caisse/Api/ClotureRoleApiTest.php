<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use App\Tests\Caisse\CaisseClotureRoleApiTestCase;

/**
 * Clôture de caisse (Z) à rôle gradué (spec-caisse-cloture-role.md §6.3) : réponse différenciée selon
 * `caisse.voir_z` (CA-1/2/5/6/9/10), alerte d'écart hors tolérance (CA-3/4/7), non-régression (CA-8),
 * défaut de tolérance (CA-11), gating des lectures de `ClotureZ` (RG-CAISSEZ-03).
 */
final class ClotureRoleApiTest extends CaisseClotureRoleApiTestCase
{
    /** CA-1/CA-2 (RG-CAISSEZ-01/02) — Caissier : accusé minimal ; Régisseur : donnée persistée retrouvée. */
    public function testCa1Ca2ReponseMinimaleCaissierPuisZCompletPourRegisseur(): void
    {
        $session = $this->ouvrirSession('50.00');

        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $reponse = $clientCaissier->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteCaissier + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '50.00']], 'versement' => '0.00'],
        ]);
        self::assertResponseIsSuccessful();
        $corps = $reponse->toArray();

        self::assertSame('close', $corps['etatSession']);
        self::assertArrayHasKey('cloture', $corps);
        self::assertArrayHasKey('session', $corps);
        self::assertArrayHasKey('horodatageCloture', $corps);
        self::assertArrayHasKey('message', $corps);
        self::assertSame('Caisse fermée.', $corps['message']);

        // Aucun champ monétaire, aucune fuite (RG-CAISSEZ-02).
        foreach (['totalVentes', 'totalRemboursements', 'comptages', 'ecartTotal', 'versement', 'fondReporte', 'etatDeRegie'] as $champ) {
            self::assertArrayNotHasKey($champ, $corps, sprintf('Le champ « %s » ne doit pas apparaître dans la réponse au caissier.', $champ));
        }

        // CA-2 : la donnée n'a jamais été perdue — un régisseur la retrouve intégralement.
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $z = $clientRegisseur->request('GET', '/api/cloture_zs/' . $corps['cloture'], $enteteRegisseur)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('0.00', $z['totalVentes']);
        self::assertSame('0.00', $z['ecartTotal']);
        self::assertNotEmpty($z['comptages']);
    }

    /** CA-3 (RG-CAISSEZ-05/06/07) — Tolérance 0,00 € par défaut : écart non nul crée une alerte + audit. */
    public function testCa3AlerteEcartCreeeQuandToleranceDepassee(): void
    {
        $session = $this->ouvrirSession('50.00');

        [$clientRegisseur, $enteteRegisseur, $idA] = $this->regisseurSurA();
        $z = $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '45.00']], 'versement' => '0.00'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('-5.00', $z['ecartTotal']);

        // Schéma/fixtures rechargés à chaque test (isolation) : la collection ne peut porter que
        // l'unique alerte créée ci-dessus — pas besoin du filtre `session` (cf. limitation connue de
        // `SearchFilter` sur les associations UUID de ce dépôt, non liée à ce lot).
        [$clientAdmin, $enteteAdmin] = $this->adminSurA();
        $alertes = $clientAdmin->request('GET', '/api/alerte_ecart_caisses', $enteteAdmin)->toArray();
        $membres = $alertes['member'] ?? $alertes['hydra:member'];
        self::assertCount(1, $membres);
        self::assertSame('/api/session_caisses/' . $session['id'], $membres[0]['session']);
        self::assertSame('-5.00', $membres[0]['ecartMontant']);
        self::assertSame('0.00', $membres[0]['toleranceAppliquee']);
        self::assertSame($idA, $this->extraireId($membres[0]['etablissement']));

        // Traçabilité (RG-CAISSEZ-07).
        $audits = $clientAdmin->request('GET', '/api/entree_audits', $enteteAdmin + [
            'query' => ['action' => 'caisse.alerte_ecart', 'cibleType' => 'AlerteEcartCaisse'],
        ])->toArray();
        $entreesAudit = $audits['member'] ?? $audits['hydra:member'];
        self::assertNotEmpty($entreesAudit);
    }

    /** CA-4 (RG-CAISSEZ-05bis) — Tolérance 5,00 € paramétrée : écart de 3,00 € → aucune alerte. */
    public function testCa4AucuneAlerteSousTolerance(): void
    {
        [$clientAdmin, $enteteAdmin] = $this->adminSurA();
        $clientAdmin->request('PATCH', '/api/point_de_ventes/' . $this->idPointDeVente(), $this->entetePatch($enteteAdmin) + [
            'json' => ['toleranceEcartCaisse' => '5.00'],
        ]);
        self::assertResponseIsSuccessful();

        $session = $this->ouvrirSession('50.00');
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $z = $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '53.00']], 'versement' => '0.00'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('3.00', $z['ecartTotal']);

        $alertes = $clientAdmin->request('GET', '/api/alerte_ecart_caisses', $enteteAdmin)->toArray();
        $membres = $alertes['member'] ?? $alertes['hydra:member'];
        self::assertCount(0, $membres);
    }

    /** CA-5/CA-9 (RG-CAISSEZ-09) — Régisseur (voir_z) : Z complet, comportement inchangé (non-régression). */
    public function testCa5RegisseurRecoitZComplet(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $z = $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '50.00']], 'versement' => '0.00'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        foreach (['totalVentes', 'totalRemboursements', 'comptages', 'ecartTotal', 'versement', 'fondReporte', 'etatDeRegie'] as $champ) {
            self::assertArrayHasKey($champ, $z);
        }
        self::assertSame('0.00', $z['ecartTotal']);
    }

    /** Rétrocompatibilité (RG-CAISSEZ-09, §3/§8 du plan) — admin porteur de `caisse.*` (wildcard) : Z complet. */
    public function testRetrocompatWildcardCaisseEtoileDonneZComplet(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientAdmin, $enteteAdmin] = $this->adminSurA();
        $z = $clientAdmin->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteAdmin + [
            'json' => ['comptages' => []],
        ])->toArray();
        self::assertResponseIsSuccessful();
        foreach (['totalVentes', 'comptages', 'ecartTotal', 'etatDeRegie'] as $champ) {
            self::assertArrayHasKey($champ, $z);
        }
    }

    /** CA-6 (RG-CAISSEZ-03) — Caissier sans voir_z : 403 sur GET cloture-z et GET etat-regie. */
    public function testCa6CaissierSansVoirZRefuseLectureClotureZ(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $z = $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '50.00']]],
        ])->toArray();
        self::assertResponseIsSuccessful();

        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $clientCaissier->request('GET', '/api/cloture_zs/' . $z['cloture'], $enteteCaissier);
        self::assertResponseStatusCodeSame(403);

        $clientCaissier->request('GET', '/api/clotures-z/' . $z['cloture'] . '/etat-regie', $enteteCaissier);
        self::assertResponseStatusCodeSame(403);
    }

    /** RG-CAISSEZ-03 (complète CA-6) — Collection ClotureZ également gatée à caisse.voir_z. */
    public function testGetCollectionClotureZGateeAVoirZ(): void
    {
        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $clientCaissier->request('GET', '/api/cloture_zs', $enteteCaissier);
        self::assertResponseStatusCodeSame(403);
    }

    /** CA-7 (RG-SOCLE-05) — Cloisonnement : le régisseur B (établissement B) ne voit pas l'alerte de A. */
    public function testCa7CloisonnementAlerteEcart(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '45.00']]],
        ]);
        self::assertResponseIsSuccessful();

        [$clientRegisseurB, $enteteRegisseurB] = $this->regisseurSurB();
        $alertes = $clientRegisseurB->request('GET', '/api/alerte_ecart_caisses', $enteteRegisseurB)->toArray();
        self::assertResponseIsSuccessful();
        $membres = $alertes['member'] ?? $alertes['hydra:member'];
        self::assertCount(0, $membres, 'Le régisseur B (établissement B) ne doit voir aucune alerte de l\'établissement A.');
    }

    /** CA-8 (RG-M2-06, non-régression) — Session déjà close : 2e tentative refusée (409), quel que soit le rôle. */
    public function testCa8SessionDejaCloseRefuseeQuelQueSoitLeRole(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $clientCaissier->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteCaissier + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '50.00']]],
        ]);
        self::assertResponseIsSuccessful();

        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => []],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** CA-9 (RG-CAISSEZ-04) — Caissier multi-moyens (espèces + chèque) : réponse toujours minimale. */
    public function testCa9CaissierMultiMoyensReponseResteMinimale(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $reponse = $clientCaissier->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteCaissier + [
            'json' => ['comptages' => [
                ['moyen' => 'especes', 'compte' => '50.00'],
                ['moyen' => 'cheque', 'compte' => '10.00'],
            ]],
        ]);
        self::assertResponseIsSuccessful();
        $corps = $reponse->toArray();
        foreach (['totalVentes', 'comptages', 'ecartTotal', 'versement', 'fondReporte', 'etatDeRegie'] as $champ) {
            self::assertArrayNotHasKey($champ, $corps);
        }
    }

    /** CA-10 (RG-CAISSEZ-04) — Sans ligne especes : 422 pour le caissier, 200 (réputé conforme) pour le régisseur. */
    public function testCa10ComptageEspecesRequisPourCaissierSeulement(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$clientCaissier, $enteteCaissier] = $this->caissierSurA();
        $clientCaissier->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteCaissier + [
            'json' => ['comptages' => [['moyen' => 'cheque', 'compte' => '10.00']]],
        ]);
        self::assertResponseStatusCodeSame(422);

        // La 1re tentative a été refusée avant tout `persist()`/`fermer()` : la session reste ouverte
        // (une seule session active par PDV, RG-M2-01) — on la réutilise pour la clôture régisseur.
        [$clientRegisseur, $enteteRegisseur] = $this->regisseurSurA();
        $clientRegisseur->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $enteteRegisseur + [
            'json' => ['comptages' => []],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** CA-11 (RG-CAISSEZ-08) — PointDeVente créé sans tolérance transmise : valeur persistée 0.00. */
    public function testCa11ToleranceParDefautAZero(): void
    {
        [$clientAdmin, $enteteAdmin, $idA] = $this->adminSurA();
        $pdv = $clientAdmin->request('POST', '/api/point_de_ventes', $enteteAdmin + [
            'json' => ['libelle' => 'PDV Tolerance Defaut', 'etablissement' => '/api/etablissements/' . $idA],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0.00', $pdv['toleranceEcartCaisse']);
    }

    private function extraireId(string $iri): string
    {
        $segments = explode('/', rtrim($iri, '/'));

        return (string) end($segments);
    }
}
