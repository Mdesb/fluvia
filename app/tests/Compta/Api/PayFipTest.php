<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\BordereauPayFiP;
use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * US-L4-03, RG-PAYFIP-03 (CA-6) : retour PayFiP « OK » → référence stockée, rapprochement automatique
 * ; retour manquant → transaction rejouable depuis le journal PayFiP.
 */
final class PayFipTest extends ComptaApiTestCase
{
    public function testRetourOkRapprocheLaTransaction(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Ce test utilisait `Uuid::v4()` comme vente d'origine — une vente **qui n'existe pas**. Il
        // encodait donc le defaut n°8 : la route creait un bordereau sur n'importe quel identifiant.
        // On lui donne desormais une vraie vente, ce qui est aussi ce que le metier decrit.
        $vente = $this->creerVenteValidee($client, $entete);

        $bordereau = $client->request('POST', '/api/compta/payfip/retour', $entete + [
            'json' => ['venteOrigine' => $vente['id'], 'referenceTransaction' => 'PAYFIP-TEST-001', 'statut' => 'ok'],
        ])->toArray();

        self::assertSame('ok', $bordereau['statutRetour']);
        self::assertTrue($bordereau['venteRapprochee']);
        self::assertSame('PAYFIP-TEST-001', $bordereau['referenceTransaction']);
    }

    /**
     * Non-regression du n°8 (D8, corrige le 23/08) — le plus grave des seize signalements, parce que
     * c'est le seul qui **ecrit** un fait financier au lieu d'en lire un.
     *
     * **Le defaut.** `POST /compta/payfip/retour` etait garde par `IS_AUTHENTICATED_FULLY` seul :
     * aucune permission, et aucune verification d'authenticite du rappel. Reference de transaction,
     * statut et vente d'origine venaient tous du corps. N'importe quel titulaire de compte pouvait
     * donc **declarer un paiement recu** sur n'importe quelle vente, dans n'importe quel etablissement
     * — et le bordereau etait cree s'il n'existait pas.
     *
     * **Ce que ce test ne couvre pas, et qu'il faut savoir.** Il verifie le cloisonnement, pas
     * l'authenticite du rappel. Un rappel de prestataire n'est pas un utilisateur connecte : la vraie
     * garde est une signature DGFiP, inconnue de nous et consignee au registre des bloqueurs externes.
     * La permission posee ici est un repli, valable tant que rien n'appelle cette route.
     */
    public function testUnRetourNePeutPasViserLaVenteDunAutreEtablissement(): void
    {
        [$client, $enteteA] = $this->adminSurA();
        $venteDeA = $this->creerVenteValidee($client, $enteteA);

        $enteteB = [
            'auth_bearer' => $enteteA['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ];

        $reponse = $client->request('POST', '/api/compta/payfip/retour', $enteteB + [
            'json' => [
                'venteOrigine' => $venteDeA['id'],
                'referenceTransaction' => 'PAYFIP-INTRUS-001',
                'statut' => 'ok',
            ],
        ]);

        self::assertSame(
            404,
            $reponse->getStatusCode(),
            'Une vente hors perimetre doit etre introuvable, jamais interdite : '
            . (string) $reponse->getContent(false),
        );

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertNull(
            $em->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => 'PAYFIP-INTRUS-001']),
            'Aucun bordereau ne doit avoir ete cree sur la vente d\'un autre etablissement.',
        );
    }

    public function testRetourManquantResteEnAttenteEtEstRejouable(): void
    {
        [$client, $entete] = $this->adminSurA();

        // ⚠ UNE VRAIE VENTE, COMME LE PREMIER TEST DE CE FICHIER. Il portait `Uuid::v4()` — une
        // vente qui n'existe pas — et a ete corrige le 23/08 ; celui-ci etait le reste. Depuis que
        // `BordereauPayFiP` est cloisonne par la vente d'origine (31/08), un bordereau orphelin
        // n'est visible d'aucun etablissement : il ne peut etre rattache a aucun, donc le montrer
        // a tous serait la fuite. Le propos du test — un retour manquant reste `en_attente` et
        // reste rejouable — est inchange.
        $vente = $this->creerVenteValidee($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $bordereau = new BordereauPayFiP();
        $bordereau->setVenteOrigine(Uuid::fromString($vente['id']));
        $bordereau->setReferenceTransaction('PAYFIP-TEST-002');
        $em->persist($bordereau);
        $em->flush();

        self::assertSame('en_attente', $bordereau->getStatutRetour()->value);

        $reponse = $client->request('POST', '/api/compta/payfip/' . $bordereau->getId() . '/rejouer', $entete)->toArray();
        self::assertSame(1, $reponse['nbTentativesRejeu']);
    }
}
