<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Entity\ParametrePmvEtablissement;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-04/05/06, RG-M4-03/04 : porte-monnaie virtuel comme moyen de paiement.
 */
final class PmvTest extends CrmApiTestCase
{
    /** CA-7, RG-M4-03 — Recharge : solde crédité, mouvement journalisé, nouvelle échéance calculée. */
    public function testCa7RechargeCreeMouvementEtEcheance(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $reponse = $client->request('POST', '/api/clients/' . $payeurId . '/pmv/recharger', $entete + [
            'json' => ['montant' => '20.00', 'canal' => 'caisse'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('20.00', $reponse['montant']);
        self::assertSame('70.00', $reponse['soldeApres']);
        self::assertNotNull($reponse['dateEcheance']);

        $mouvements = $client->request('GET', '/api/clients/' . $payeurId . '/pmv/mouvements', $entete)->toArray();
        $recharges = array_filter($mouvements['mouvements'], static fn (array $m): bool => $m['type'] === 'recharge');
        self::assertCount(2, $recharges); // 1 en fixtures + celle-ci.
    }

    /** CA-8, RG-M4-03 — Débit total refusé si solde insuffisant ; paiement partiel PMV + complément possible. */
    public function testCa8DebitRefuseSiInsuffisantPaiementPartielPossible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        // Solde PMV 50.00 (fixtures) ; vente d'une entrée (4.95 TTC après la promotion guichet -10 %
        // de VenteFixtures, US-L2-03/CA-4). Un montant de règlement explicite (60.00) très supérieur
        // au solde ET au reste dû est refusé (422, RG-M4-03) — puis paiement au montant réel accepté.
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 1);

        $refusPaiement = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv', 'montant' => '60.00'],
        ]);
        self::assertSame(422, $refusPaiement->getStatusCode(), 'CA-8 : débit PMV total refusé (solde insuffisant).');

        // Paiement PMV au montant réel (reste dû) : accepté.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv'],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $pmv = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray();
        self::assertSame('45.05', $pmv['solde']);
    }

    /** CA-9, RG-M4-03 — Annulation d'une vente payée en PMV re-crédite intégralement, mouvement traçable. */
    public function testCa9AnnulationRecrediteIntegralement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 1);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $pmvApresPaiement = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray();
        self::assertSame('45.05', $pmvApresPaiement['solde']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/annuler', $entete + ['json' => ['motif' => 'Test CA-9']]);
        self::assertResponseIsSuccessful();

        $pmvApresAnnulation = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray();
        self::assertSame('50.00', $pmvApresAnnulation['solde'], 'CA-9 : re-crédit intégral.');

        $mouvements = $client->request('GET', '/api/clients/' . $payeurId . '/pmv/mouvements', $entete)->toArray();
        $remboursements = array_filter($mouvements['mouvements'], static fn (array $m): bool => $m['type'] === 'remboursement_vente');
        self::assertNotEmpty($remboursements, 'CA-9 : MouvementPmv(remboursement_vente) traçable.');
    }

    /** CA-10, RG-M4-04 — Un PMV expiré est refusé en paiement (n'apparaît pas comme moyen utilisable). */
    public function testCa10PmvExpireRefuseEnPaiement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $pmv = $this->entite(\App\Crm\Entity\PorteMonnaieVirtuel::class, ['client' => $this->entite(\App\Crm\Entity\Client::class, ['email' => \App\Crm\DataFixtures\CrmFixtures::PAYEUR_EMAIL])]);
        $pmv->setDateEcheance(new \DateTimeImmutable('-1 day'));
        $pmv->setStatut(\App\Crm\Enum\StatutPmv::Expire);
        $em->flush();
        self::ensureKernelShutdown();

        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 1);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv']]);
        self::assertSame(422, $reponse->getStatusCode(), 'CA-10 : PMV expiré refusé en paiement.');
    }

    /** CA-11, RG-M4-04, US-L5-06 — Recharge d'un PMV expiré : réactivation autorisée ou blocage motivé selon paramètre établissement. */
    public function testCa11RechargePmvExpireSelonParametreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $this->entite(\App\Crm\Entity\Client::class, ['email' => \App\Crm\DataFixtures\CrmFixtures::PAYEUR_EMAIL]);
        $pmv = $this->entite(\App\Crm\Entity\PorteMonnaieVirtuel::class, ['client' => $payeur]);
        $pmv->setDateEcheance(new \DateTimeImmutable('-1 day'));
        $pmv->setStatut(\App\Crm\Enum\StatutPmv::Expire);
        $em->flush();
        self::ensureKernelShutdown();

        // Cas 1 : établissement paramétré « interdite » (valeur de repli des fixtures) -> bloqué, journalisé.
        [$client, $entete] = $this->adminSurA();
        $refus = $client->request('POST', '/api/clients/' . $payeurId . '/pmv/recharger', $entete + ['json' => ['montant' => '10.00']]);
        self::assertSame(422, $refus->getStatusCode());

        $mouvementsApresRefus = $client->request('GET', '/api/clients/' . $payeurId . '/pmv/mouvements', $entete)->toArray();
        self::assertNotEmpty(array_filter($mouvementsApresRefus['mouvements'], static fn (array $m): bool => str_contains((string) $m['motif'], 'RG-M4-04')), 'CA-11 : comportement journalisé même en cas de blocage.');

        // Cas 2 : établissement paramétré « autorisée » -> réactivation avec nouvelle échéance.
        // NB : `$em` ci-dessus provient du kernel initial, invalidé par `ensureKernelShutdown()` —
        // on en récupère un nouveau sur le kernel actif avant toute nouvelle mutation directe.
        /** @var \Doctrine\ORM\EntityManagerInterface $em2 */
        $em2 = static::getContainer()->get('doctrine')->getManager();
        $etabA = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $parametre = $this->entite(ParametrePmvEtablissement::class, ['etablissement' => $etabA]);
        $parametre->setRechargeExpireeAutorisee(true);
        $em2->flush();
        self::ensureKernelShutdown();

        [$client, $entete] = $this->adminSurA();
        $reactivation = $client->request('POST', '/api/clients/' . $payeurId . '/pmv/recharger', $entete + ['json' => ['montant' => '10.00']])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('actif', $reactivation['statutPmv']);
        self::assertNotNull($reactivation['dateEcheance']);
    }
}
