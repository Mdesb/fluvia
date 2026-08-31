<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;

/**
 * UN DROIT LU DE DEUX FAÇONS N'EST PAS UN DROIT.
 *
 * Signalé depuis le navigateur : 403 sur le tableau de bord d'un établissement, avec un compte
 * `ROLE_ADMIN`. La seule erreur de console de la session — donc le genre de défaut qu'on ne
 * découvre qu'en ouvrant l'écran.
 *
 * Le contrôleur pose deux barrières, et elles ne lisaient pas le même modèle :
 *
 *   1. `isGranted('PERM', 'reporting.lire')` passe par `CalculateurDroits::autorise()`, qui
 *      comprend les jokers — un code `*.lire` couvre `reporting.lire`. Franchie.
 *   2. `PerimetreReportingResolver` comparait lui-même le module à « reporting » et sautait donc le
 *      joker. Aucun établissement autorisé, donc « hors périmètre ».
 *
 * Le rôle « Administrateur groupe » des fixtures ne porte, en lecture, que `*.lire` : il était
 * autorisé à l'entrée et refusé trois lignes plus bas, sur son propre site.
 *
 * Ce test vaut pour la classe entière de défauts, pas pour ce seul écran : il échoue dès que
 * quelqu'un réintroduit une seconde lecture du modèle de droits quelque part sur ce chemin.
 */
final class DashboardJokerTest extends VenteApiTestCase
{
    public function testUnRoleAuJokerVoitLeTableauDeBordDeSonEtablissement(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        $reponse = $client->request('GET', '/reporting/dashboards/etablissement/' . $idA, $entete);

        self::assertSame(
            200,
            $reponse->getStatusCode(),
            'un administrateur portant « *.lire » est refusé sur le tableau de bord de son propre site',
        );

        $corps = $reponse->toArray();
        self::assertSame($idA, $corps['etablissementId']);
        self::assertSame(SocleFixtures::ETAB_A_NOM, $corps['etablissementNom']);
    }

    /**
     * LE PÉRIMÈTRE CONTINUE DE REFUSER.
     *
     * La correction élargit la lecture des droits ; elle ne doit pas ouvrir les établissements où
     * l'on n'est pas affecté. Sans cette seconde vérification, remplacer le filtre par « tout le
     * monde peut tout lire » passerait le premier test — et le sens sûr de l'erreur est celui qui
     * restreint.
     */
    public function testUnEtablissementHorsPerimetreResteRefuse(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Le lecteur n'est affecté qu'à A ; il réclame le tableau de bord de B.
        $client->request('GET', '/reporting/dashboards/etablissement/' . $idB, [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ]);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
    }
}
