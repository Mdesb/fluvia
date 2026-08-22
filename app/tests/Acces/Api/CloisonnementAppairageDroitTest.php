<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Non-régression de l'IDOR corrigé le 22/08 sur `AppairageProcessor` (D8, C20).
 *
 * **Le défaut.** L'identifiant `droit` arrive dans le corps de la requête et était résolu par un
 * `find()` direct, sans jamais être confronté à l'établissement actif. `PerimetreAccesExtension` ne
 * couvre pas ce cas : elle ne s'applique qu'aux requêtes API Platform `Get`/`GetCollection`, pas à une
 * résolution faite dans un Processor. Un agent travaillant sur l'établissement B pouvait donc appairer
 * un support à un `DroitAcces` de l'établissement A — c'est-à-dire **lier son propre badge au droit
 * d'accès d'autrui**.
 *
 * **Pourquoi ce montage précis.** L'administrateur socle est affecté sur A **et** sur B : les deux
 * en-têtes lui sont donc légitimes. C'est exactement ce qui rend la reproduction fidèle — rien n'est
 * usurpé, seul l'établissement **actif** diffère de celui du droit visé. Un utilisateur bricolé pour
 * l'occasion prouverait autre chose.
 *
 * **Pourquoi 404 et non 403.** Un 403 confirmerait au demandeur que ce droit existe ailleurs et
 * transformerait la route en oracle d'énumération. Le test l'exige explicitement : c'est la moitié de
 * la correction, et celle qu'un refactoring distrait ferait sauter en premier.
 */
final class CloisonnementAppairageDroitTest extends AccesApiTestCase
{
    public function testAppairageSurUnDroitDunAutreEtablissementEstIntrouvable(): void
    {
        [$client, $enteteA] = $this->adminSurA();
        $droitDeA = '/api/droit_acces/' . $this->idDroit();

        // --- Contrôle positif -------------------------------------------------------------------
        // Sans lui, un 404 provoqué par n'importe quelle autre cause (route absente, droit
        // inexistant, corps invalide) ferait passer ce test pour vert à tort.
        $legitime = $client->request('POST', '/api/acces/appairages', $enteteA + [
            'json' => [
                'identifiantSupport' => 'RFID-C20-LEGITIME',
                'typeSupport' => 'RFID',
                'droit' => $droitDeA,
                'mode' => 'autonome',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $legitime->getContent(false));

        // --- L'IDOR ---------------------------------------------------------------------------
        // Même jeton, même droit ; seul l'établissement actif change. L'en-tête B est légitime pour
        // cet utilisateur : ce qui ne l'est pas, c'est d'atteindre depuis B un droit qui vit sur A.
        $enteteB = [
            'auth_bearer' => $enteteA['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ];

        $intrusion = $client->request('POST', '/api/acces/appairages', $enteteB + [
            'json' => [
                'identifiantSupport' => 'RFID-C20-INTRUSION',
                'typeSupport' => 'RFID',
                'droit' => $droitDeA,
                'mode' => 'autonome',
            ],
        ]);

        self::assertSame(
            404,
            $intrusion->getStatusCode(),
            'Un droit hors périmètre doit être introuvable, jamais interdit : '
            . (string) $intrusion->getContent(false),
        );
    }
}
