<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Service\ClotureGuard;
use App\Organisation\Entity\Etablissement;
use App\Tests\Caisse\CaisseClotureRoleApiTestCase;
use App\DataFixtures\SocleFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA CLÔTURE Z ALIMENTE L'ENCAISSE DE LA RÉGIE (spec-encaisse-cloture-z.md §8).
 *
 * ── CE QUE CETTE SUITE EXISTE POUR ATTRAPER ────────────────────────────────────────────────────
 *
 * Avant ce lot, `RegieHandler::enregistrerEncaissement()` n'avait AUCUN appelant en production.
 * L'encaisse d'une régie partait de zéro et ne savait que descendre. Et la suite de la régie était
 * verte — `RegieTest` appelle le handler lui-même, `ClotureTest` écrit le solde à la main. Elles
 * prouvaient que le mécanisme sait compter ; aucune ne prouvait que quelque chose l'alimente.
 *
 * ⚠ C'EST `testCa7` QUI PROUVE LE LOT. Les autres prouvent qu'on n'a rien cassé ; seul CA-7 parcourt
 * la chaîne entière — caisse → encaisse → plafond dépassé → clôture comptable refusée. Un vert sans
 * lui aurait rebranché un tuyau sans jamais vérifier que l'eau arrive.
 */
final class RegieEncaisseClotureZTest extends CaisseClotureRoleApiTestCase
{
    private const PLAFOND_CENTIMES = 20000; // 200,00 €

    /** CA-1 — le compté moins le fond reporté entre dans l'encaisse. */
    public function testCa1LesEspecesComptessMoinsLeFondReporteEntrentDansLEncaisse(): void
    {
        $regie = $this->regiePourA(self::PLAFOND_CENTIMES);
        $this->rattacherLaRegieAuGuichet($regie);
        self::assertSame(0, $regie->getSoldeEncaisseCentimes(), 'Précondition : l’encaisse part de zéro.');

        $session = $this->ouvrirSession('50.00');
        [$client, $entete] = $this->regisseurSurA();
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => [
                'comptages' => [['moyen' => 'especes', 'compte' => '180.00']],
                'fondReporte' => '50.00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $regie = $this->regieFraiche();
        self::assertSame(
            13000,
            $regie->getSoldeEncaisseCentimes(),
            '180,00 € comptés moins 50,00 € laissés dans le tiroir : le régisseur emporte 130,00 €.',
        );
    }

    /**
     * CA-2 — LE TEST LE PLUS IMPORTANT DE CETTE SUITE, ET IL NE PARLE PAS DE RÉGIE.
     *
     * `enregistrerEncaissement()` lève une 422 sur un montant `<= 0`. Appelée sans garde depuis la
     * Z, toute session sans espèces empêcherait de FERMER LA CAISSE le soir — un incident
     * d'exploitation bien plus grave que le défaut corrigé ici.
     *
     * ⚠ Il n'affirme pas « la garde existe » : il affirme que la clôture RÉPOND 200. C'est le
     * symptôme que l'exploitant subirait, pas le code qui l'évite — un témoin qui ré-épelle
     * l'implémentation tombe à la première réécriture et n'a jamais rien mesuré.
     */
    public function testCa2UneSessionSansEspecesSeCloturequandMeme(): void
    {
        $regie = $this->regiePourA(self::PLAFOND_CENTIMES);
        $this->rattacherLaRegieAuGuichet($regie);

        $session = $this->ouvrirSession('0.00');
        [$client, $entete] = $this->regisseurSurA();
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '0.00']], 'fondReporte' => '0.00'],
        ]);

        self::assertResponseIsSuccessful('Une caisse sans espèces doit pouvoir être fermée : sinon le guichet reste ouvert le soir.');
        self::assertSame(0, $this->regieFraiche()->getSoldeEncaisseCentimes());
    }

    /** CA-3 — tout le tiroir est reporté : le régisseur n'emporte rien. */
    public function testCa3RienNeSortQuandToutLeTiroirEstReporte(): void
    {
        $regie = $this->regiePourA(self::PLAFOND_CENTIMES);
        $this->rattacherLaRegieAuGuichet($regie);

        $session = $this->ouvrirSession('50.00');
        [$client, $entete] = $this->regisseurSurA();
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '50.00']], 'fondReporte' => '50.00'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->regieFraiche()->getSoldeEncaisseCentimes());
    }

    /**
     * CA-5 — LE CAS DE LA TRÈS GRANDE MAJORITÉ DES CLIENTS, et celui qu'on casserait sans le voir.
     *
     * Un exploitant privé ou un délégataire n'a pas de régie. Aucune régie n'est créée ici, et le
     * guichet n'en porte aucune : la clôture doit se comporter exactement comme avant ce lot.
     */
    public function testCa5UnGuichetSansRegieCloturecommeAvant(): void
    {
        $session = $this->ouvrirSession('50.00');
        [$client, $entete] = $this->regisseurSurA();
        $z = $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => ['comptages' => [['moyen' => 'especes', 'compte' => '180.00']], 'fondReporte' => '50.00'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('close', $z['etatSession']);
    }

    /**
     * CA-7 — LA CHAÎNE ENTIÈRE, ET LE SEUL TEST QUI PROUVE QUE CE LOT SERT À QUELQUE CHOSE.
     *
     * Caisse → encaisse de la régie → plafond dépassé → `ClotureGuard` refuse la clôture comptable
     * de la période. Avant ce lot, ce chemin était impossible à parcourir : l'encaisse restant à
     * zéro, `depassePlafond()` ne pouvait jamais être vrai et le point bloquant n'existait qu'en
     * théorie.
     */
    public function testCa7UneZAuDessusDuPlafondBloqueLaClotureComptable(): void
    {
        $regie = $this->regiePourA(self::PLAFOND_CENTIMES);
        $this->rattacherLaRegieAuGuichet($regie);
        $periode = $this->periodeOuvertePourA();

        /** @var ClotureGuard $guard */
        $guard = static::getContainer()->get(ClotureGuard::class);
        self::assertSame(
            [],
            $guard->pointsBloquants($periode),
            'Précondition : rien ne bloque la clôture comptable tant que la caisse n’a pas versé dans la régie.',
        );

        $session = $this->ouvrirSession('50.00');
        [$client, $entete] = $this->regisseurSurA();
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + [
            'json' => [
                // 300,00 € comptés − 50,00 € reportés = 250,00 € dans l'encaisse, pour un plafond
                // de 200,00 €.
                'comptages' => [['moyen' => 'especes', 'compte' => '300.00']],
                'fondReporte' => '50.00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(25000, $this->regieFraiche()->getSoldeEncaisseCentimes());

        /** @var ClotureGuard $guard */
        $guard = static::getContainer()->get(ClotureGuard::class);
        $bloquants = $guard->pointsBloquants($this->periodeOuvertePourA());
        self::assertCount(1, $bloquants, 'La régie au-dessus de son plafond doit bloquer la clôture comptable.');
        self::assertStringContainsString('versement requis', $bloquants[0]);
    }

    /** CA-8 — une session close ne se re-clôture pas, donc l'encaisse n'est pas comptée deux fois. */
    public function testCa8UneSecondeClotureNeRecompteRien(): void
    {
        $regie = $this->regiePourA(self::PLAFOND_CENTIMES);
        $this->rattacherLaRegieAuGuichet($regie);

        $session = $this->ouvrirSession('50.00');
        [$client, $entete] = $this->regisseurSurA();
        $corps = ['json' => [
            'comptages' => [['moyen' => 'especes', 'compte' => '180.00']],
            'fondReporte' => '50.00',
        ]];

        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + $corps);
        self::assertResponseIsSuccessful();
        self::assertSame(13000, $this->regieFraiche()->getSoldeEncaisseCentimes());

        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + $corps);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            13000,
            $this->regieFraiche()->getSoldeEncaisseCentimes(),
            'La seconde clôture est refusée : l’encaisse ne doit pas avoir bougé.',
        );
    }

    /**
     * CLOISONNEMENT, PREMIÈRE LIGNE — ET CE N'EST PAS CELLE QUE J'ATTENDAIS.
     *
     * J'avais écrit ce test en attendant un 422 de `RevenueOfficeWithinTenant`. Il rend un **400
     * « Item not found »** : `AccountingScopeExtension` cloisonne les lectures de `RegieRecettes`
     * par l'établissement actif, donc la régie de B n'existe simplement pas pour une requête portant
     * l'en-tête de A. API Platform ne peut pas résoudre l'IRI, et rien n'atteint le validateur.
     *
     * Le test affirme donc ce qui se produit VRAIMENT — un rattachement croisé est refusé — plutôt
     * que le mécanisme que j'avais supposé. Un témoin qui nomme le mauvais organe passe au vert le
     * jour où le bon disparaît.
     */
    public function testUneRegieDUnAutreEtablissementEstRefusee(): void
    {
        $regieB = $this->regiePourEtablissement(SocleFixtures::ETAB_B_NOM, self::PLAFOND_CENTIMES);

        [$client, $entete] = $this->adminSurA();
        $client->request('PATCH', '/api/point_de_ventes/' . $this->idPointDeVente(), $this->entetePatch($entete) + [
            'json' => ['regie' => '/api/regie_recettes/' . $regieB->getId()],
        ]);

        self::assertResponseStatusCodeSame(400, 'Le cloisonnement comptable rend la régie de B invisible sous l’en-tête de A.');

        $pdv = $this->entite(PointDeVente::class, ['id' => $this->idPointDeVente()]);
        self::assertNull($pdv->getRegie(), 'Le guichet ne doit pas s’être rattaché à la régie d’un autre établissement.');
    }

    /**
     * CLOISONNEMENT, SECONDE LIGNE — LA CONTRAINTE ELLE-MÊME, QUE L'API N'ATTEINT JAMAIS.
     *
     * ⚠ SANS CE TEST, `RevenueOfficeWithinTenant` SERAIT DU CODE QUE RIEN NE FAIT REFUSER. Le test
     * ci-dessus passe, et il passerait à l'identique si la contrainte n'existait pas : c'est
     * l'extension de cloisonnement qui le fait passer. Un contrôle dont aucun témoin ne montre le
     * refus est une fausse sécurité — on le croit posé, et personne ne saurait dire quand il a
     * cessé de l'être.
     *
     * On l'exerce donc directement, hors HTTP, là où l'extension ne s'interpose pas : c'est aussi
     * exactement le chemin d'une fixture, d'une commande ou d'un futur processor sur mesure — et
     * `Providers sur mesure et cloisonnement` a déjà montré qu'une extension Doctrine ne protège
     * pas ce qui ne passe pas par elle.
     */
    public function testLaContrainteRefuseUnRattachementCroiseHorsApi(): void
    {
        $regieB = $this->regiePourEtablissement(SocleFixtures::ETAB_B_NOM, self::PLAFOND_CENTIMES);
        $pdvDeA = $this->entite(PointDeVente::class, ['id' => $this->idPointDeVente()]);

        $violations = static::getContainer()->get('validator')->validate($pdvDeA->setRegie($regieB));

        self::assertCount(1, $violations);
        self::assertSame('regie', $violations[0]->getPropertyPath());

        // ⚠ LE TÉMOIN NÉGATIF. Un validateur qui refuserait TOUT rattachement passerait l'assertion
        // ci-dessus, et casserait en silence le cas normal. On montre donc aussi ce qu'il ÉPARGNE.
        $regieA = $this->regiePourA(self::PLAFOND_CENTIMES);
        self::assertCount(0, static::getContainer()->get('validator')->validate($pdvDeA->setRegie($regieA)));
    }

    // ── Préconditions ─────────────────────────────────────────────────────────────────────────

    private function regiePourA(int $plafondCentimes): RegieRecettes
    {
        return $this->regiePourEtablissement(SocleFixtures::ETAB_A_NOM, $plafondCentimes);
    }

    private function regiePourEtablissement(string $nomEtablissement, int $plafondCentimes): RegieRecettes
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertNotNull($etablissement);

        // `etablissementPrincipal` est UNIQUE : un profil peut déjà exister pour cet établissement,
        // posé par une fixture. Le réutiliser plutôt que d'en créer un second, qui échouerait.
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement]);
        if ($profil === null) {
            $profil = (new ProfilExploitant())->setEtablissementPrincipal($etablissement);
            $em->persist($profil);
        }

        $regie = (new RegieRecettes())
            ->setProfilExploitant($profil)
            ->setLibelle('Régie de ' . $nomEtablissement)
            ->setPlafondEncaisseCentimes($plafondCentimes);
        $em->persist($regie);
        $em->flush();

        return $regie;
    }

    /**
     * Le rattachement passe par l'API, pas par l'`EntityManager` : c'est le chemin que l'exploitant
     * emprunte depuis Paramètres › Caisse, et le seul qui traverse la contrainte de cloisonnement.
     */
    private function rattacherLaRegieAuGuichet(RegieRecettes $regie): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('PATCH', '/api/point_de_ventes/' . $this->idPointDeVente(), $this->entetePatch($entete) + [
            'json' => ['regie' => '/api/regie_recettes/' . $regie->getId()],
        ]);
        self::assertResponseIsSuccessful('Le rattachement du guichet à sa régie est une précondition, pas ce qui est mesuré.');

        $pdv = $this->entite(PointDeVente::class, ['id' => $this->idPointDeVente()]);
        self::assertNotNull($pdv->getRegie(), 'Précondition non tenue : le guichet n’a pas été rattaché.');
    }

    private function periodeOuvertePourA(): PeriodeComptable
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement]);
        self::assertNotNull($profil);

        $periode = $em->getRepository(PeriodeComptable::class)->findOneBy(['profilExploitant' => $profil]);
        if ($periode === null) {
            $periode = (new PeriodeComptable())
                ->setProfilExploitant($profil)
                ->setDateDebut(new \DateTimeImmutable('first day of this month'))
                ->setDateFin(new \DateTimeImmutable('last day of this month'));
            $em->persist($periode);
            $em->flush();
        }

        return $periode;
    }

    private function regieFraiche(): RegieRecettes
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement]);
        $regie = $em->getRepository(RegieRecettes::class)->findOneBy(['profilExploitant' => $profil]);
        self::assertNotNull($regie);

        return $regie;
    }
}
