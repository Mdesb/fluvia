<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Api;

use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\Enum\StatutEscalade;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Autorisation\AutorisationApiTestCase;

/**
 * `ServiceAutorisation::evaluer()` via les endpoints M2 (`AnnulerVenteProcessor`/
 * `RembourserVenteProcessor`) — CA-1, CA-2, CA-5, CA-6, CA-7, CA-8, plafond inclusif.
 */
final class DecisionVenteTest extends AutorisationApiTestCase
{
    /** CA-1 — Sous plafond (80 € ≤ 100 €), propre session : AUTORISE, Avoir créé, aucune DemandeEscalade. */
    public function testCa1SousPlafondAutorise(): void
    {
        $this->configurerLimiteAnnuler(plafond: '100.00', perimetre: PerimetreAutorisation::PropreSession, escaladeAuDela: true);

        [$client, $entete] = $this->connecte('caissier-ca1@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '80.00');

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('annulee', $avoir['statutVente']);
        self::assertSame('80.00', $avoir['montant']);

        self::assertSame(0, $this->em()->getRepository(DemandeEscalade::class)->count([]));
    }

    /** Montant strictement égal au plafond → AUTORISE (comparaison inclusive, §4.3 spec). */
    public function testMontantEgalAuPlafondAutorise(): void
    {
        $this->configurerLimiteAnnuler(plafond: '100.00', perimetre: PerimetreAutorisation::Global, escaladeAuDela: false);

        [$client, $entete] = $this->connecte('caissier-egal@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '100.00');

        $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertResponseStatusCodeSame(201);
    }

    /** CA-2 — Au-dessus du plafond (250 € > 100 €), escaladeAuDela=true : 403 escalade_requise, DemandeEscalade créée, annulation non exécutée. */
    public function testCa2DepassementAvecEscaladeCreeDemande(): void
    {
        $this->configurerLimiteAnnuler(plafond: '100.00', perimetre: PerimetreAutorisation::PropreSession, escaladeAuDela: true);

        [$client, $entete] = $this->connecte('caissier-ca2@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '250.00');

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertResponseStatusCodeSame(403);
        $corps = $reponse->toArray(false);
        self::assertSame('escalade_requise', $corps['decision']);
        self::assertSame('vente.annuler', $corps['operation']);
        self::assertSame('100.00', $corps['plafond']);
        self::assertSame('250.00', $corps['montant']);
        self::assertNotEmpty($corps['demandeEscalade']);

        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $corps['demandeEscalade']]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);
        self::assertSame(StatutEscalade::EnAttente, $demande->getStatut());
        self::assertSame('250.00', $demande->getMontant());
        self::assertSame('vente.annuler', $demande->getOperation()?->getCode());
        self::assertSame('caissier-ca2@test.itcotation.com', $demande->getAuteur()?->getEmail());

        // L'annulation n'a pas été exécutée : la vente reste validée (pas annulee/avoir_emis).
        $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertSame('validee', $vente['statut']);
    }

    /** CA-5 — Périmètre propre_session, vente d'un AUTRE opérateur (montant sous le plafond) → REFUSE 403, même si droit binaire présent. */
    public function testCa5HorsPerimetreRefuse(): void
    {
        $this->configurerLimiteAnnuler(plafond: '1000.00', perimetre: PerimetreAutorisation::PropreSession, escaladeAuDela: false);

        [$clientProprietaire, $enteteProprietaire] = $this->connecte('caissier-proprietaire@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($clientProprietaire, $enteteProprietaire);
        $venteId = $this->venteValideeMontant($clientProprietaire, $enteteProprietaire, $session['id'], '30.00');

        [$clientAutre, $enteteAutre] = $this->connecte('caissier-autre@test.itcotation.com');
        $clientAutre->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteAutre + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->em()->getRepository(DemandeEscalade::class)->count([]));
    }

    /**
     * CA-6 — Rétrocompatibilité (RG-AUTZ-09) : aucune LimiteAutorisation configurée pour
     * vente.rembourser → AUTORISE quel que soit le montant, aucune régression, aucune écriture
     * (DemandeEscalade) supplémentaire.
     */
    public function testCa6AucuneLimiteConfigureeComportementInchange(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '999.99');

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + ['json' => ['motif' => 'CA-6 sans limite']])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('999.99', $avoir['montant']);

        self::assertSame(0, $this->em()->getRepository(DemandeEscalade::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(LimiteAutorisation::class)->count([]));
    }

    /** CA-7 — escaladeAuDela=false, dépassement (150 € > 100 €) → REFUSE définitif, aucune DemandeEscalade. */
    public function testCa7DepassementSansEscaladePossibleRefuseDefinitivement(): void
    {
        $this->configurerLimiteAnnuler(plafond: '100.00', perimetre: PerimetreAutorisation::Global, escaladeAuDela: false);

        [$client, $entete] = $this->connecte('caissier-ca7@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '150.00');

        $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->em()->getRepository(DemandeEscalade::class)->count([]));
    }

    /** CA-8 — Deux remboursements partiels successifs de 60 € (plafond 100 €, pas de cumul) : chacun évalué isolément et AUTORISÉ. */
    public function testCa8RemboursementsPartielsSuccessifsEvaluesIsolement(): void
    {
        $this->configurerLimite('vente.rembourser', plafond: '100.00', perimetre: PerimetreAutorisation::Global, escaladeAuDela: false);

        [$client, $entete] = $this->connecte('caissier-ca8@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '150.00');

        $r1 = $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + ['json' => ['motif' => 'Partiel 1', 'montant' => '60.00']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('60.00', $r1->toArray()['montant']);

        $r2 = $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + ['json' => ['motif' => 'Partiel 2', 'montant' => '60.00']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('60.00', $r2->toArray()['montant']);

        self::assertSame(0, $this->em()->getRepository(DemandeEscalade::class)->count([]));
    }

    private function configurerLimiteAnnuler(string $plafond, PerimetreAutorisation $perimetre, bool $escaladeAuDela): void
    {
        $this->configurerLimite('vente.annuler', $plafond, $perimetre, $escaladeAuDela);
    }

    private function configurerLimite(string $operationCode, string $plafond, PerimetreAutorisation $perimetre, bool $escaladeAuDela): void
    {
        $em = $this->em();
        $operation = $em->getRepository(OperationSensible::class)->find($operationCode);
        self::assertInstanceOf(OperationSensible::class, $operation);
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $limite = (new LimiteAutorisation())
            ->setOperation($operation)
            ->setRole($this->roleCaissier())
            ->setEtablissement($etabA)
            ->setPlafondMontant($plafond)
            ->setPerimetre($perimetre)
            ->setEscaladeAuDela($escaladeAuDela)
            ->setAuteur($admin);
        $em->persist($limite);
        $em->flush();
    }
}
