<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GET /factures/verifier-chaine?profilExploitant=<id>` NE VÉRIFIE QUE LES PROFILS DU PÉRIMÈTRE (D3).
 *
 * Mesuré le 08/10/2026 : l'identifiant fourni était lu tel quel. Un lecteur d'un établissement
 * obtenait la vérification de la chaîne de n'importe quel exploitant — son nombre de factures et ses
 * anomalies. Hors périmètre, la réponse est désormais celle d'un profil inexistant : 404.
 */
final class ChainCheckScopeTest extends FacturationApiTestCase
{
    public function testAnotherOperatorsChainIsNotFound(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $siteB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        $other = (new ProfilExploitant())->setSiren('552100554')->setEtablissementPrincipal($siteB)
            ->setType(TypeExploitant::GroupePrive)->setReferentielComptable(ReferentielComptable::Pcg);
        $em->persist($other);
        $em->flush();
        $otherId = (string) $other->getId();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $headers = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP),
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        $client->request('GET', '/api/factures/verifier-chaine?profilExploitant=' . $otherId, $headers);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/factures/verifier-chaine?profilExploitant=' . $this->idProfilExploitant(), $headers);
        self::assertResponseIsSuccessful();
    }
}
