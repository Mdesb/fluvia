<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D45 — corriger un moyen de paiement n'est pas une modification, et la date est celle du geste.
 *
 * Le cas réel : un caissier saisit « espèces » alors que le client a payé par carte. Le montant de la
 * vente n'est pas en cause, seule sa ventilation l'est — donc ni modification (la chaîne NF525 la
 * refuserait, et elle aurait raison), ni avoir (il annulerait et rejouerait le chiffre d'affaires
 * pour une erreur qui n'a rien changé au montant).
 */
final class CorrectionReglementTest extends VenteApiTestCase
{
    /** La correction s'ajoute, la vente ne bouge pas, et la chaîne reste vérifiable. */
    public function testLaCorrectionSAjouteSansToucherLaVente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $avant = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();

        $correction = $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => [
                'moyenDebite' => 'especes',
                'moyenCredite' => 'cb',
                'montant' => '5.50',
                'motif' => 'Saisi en especes, regle par carte',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('especes', $correction['moyenDebite']);
        self::assertSame('cb', $correction['moyenCredite']);

        // La vente d'origine est intacte : c'est toute la différence avec une modification.
        $apres = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertSame($avant['total'] ?? null, $apres['total'] ?? null, 'Le montant de la vente ne bouge pas.');
        self::assertSame($avant['statut'], $apres['statut']);
    }

    /** La date est celle du geste, jamais celle de la vente (D45). */
    public function testLaCorrectionEstDateeDuJourDuGeste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        // La vente est antidatée AVANT sa validation : une fois validée, `InalterabiliteListener`
        // refuse d'y toucher — je l'ai constaté en écrivant ce test, qui échouait sur
        // « Modification interdite : la vente est validée (NF525) ; champ "date" figé ». C'est
        // précisément la garantie sur laquelle repose D45, et elle m'a arrêtée moi aussi.
        //
        // Si la correction reprenait la date de la vente, elle atterrirait dans un Z déjà tiré —
        // c'est-à-dire qu'il faudrait refaire l'histoire du fonds de caisse.
        $vente = $this->venteReglee($client, $entete, 'especes', new \DateTimeImmutable('2026-01-05 10:00:00'));

        $correction = $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '1.00', 'motif' => 'Erreur de saisie'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertStringStartsNotWith('2026-01-05', $correction['dateHeure'], 'La correction ne reprend pas la date de la vente.');
        self::assertStringStartsWith((new \DateTimeImmutable())->format('Y-m-d'), $correction['dateHeure']);
    }

    /**
     * On ne déplace pas plus que ce qui a été réglé sur le moyen — et c'est le **net des corrections
     * déjà passées** qui borne, pas le paiement d'origine.
     *
     * Sans ce contrôle, deux corrections successives déplaceraient deux fois la même somme, et on
     * créditerait la carte depuis des espèces qui n'ont jamais été encaissées.
     */
    public function testOnNeDeplacePasPlusQueCeQuiAEteRegle(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $corps = ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '5.50', 'motif' => 'Erreur de saisie'];

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        // Deuxième passage : il ne reste plus rien en espèces sur cette vente.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('disponible', $client->getResponse()->toArray(false)['detail'] ?? '');
    }

    /** Motif obligatoire : ce geste déplace de l'argent, il doit rester défendable en contrôle. */
    public function testLeMotifEstObligatoire(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '1.00', 'motif' => '   '],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** Deux fois le même moyen : il n'y a rien à corriger, et l'écriture serait du bruit dans la chaîne. */
    public function testDeuxMoyensIdentiquesSontRefuses(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => ['moyenDebite' => 'especes', 'moyenCredite' => 'especes', 'montant' => '1.00', 'motif' => 'Test'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * D46 — une correction peut pointer l'écart de caisse qu'elle explique.
     *
     * « Si le Z d'hier a constaté 50 € de manquant, la correction d'aujourd'hui doit pouvoir dire
     * *c'est ce manquant-là*. » Un écart expliqué cesse d'être un écart — c'est ce qui transforme une
     * liste d'alertes qu'on finit par ignorer en une liste qui se vide.
     */
    public function testLaCorrectionPeutDesignerLEcartQuElleExplique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');
        $idAlerte = $this->alerteEcart($this->idSession($vente), $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM));

        $correction = $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => [
                'moyenDebite' => 'especes',
                'moyenCredite' => 'cb',
                'montant' => '5.50',
                'motif' => 'Le manquant du Z d hier vient de cette saisie',
                'alerteEcart' => '/api/alerte_ecart_caisses/' . $idAlerte,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($idAlerte, $correction['alerteEcartRef'], 'La correction designe l ecart qu elle explique.');
    }

    /**
     * L'écart est désigné **par le client** : il se confronte donc au périmètre. 404 et non 403 —
     * répondre « interdit » confirmerait qu'une alerte existe chez le voisin, et une alerte de caisse
     * dit combien il lui manque.
     */
    public function testUnEcartDunAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');
        $idAlerteB = $this->alerteEcart($this->idSession($vente), $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_B_NOM));

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => [
                'moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '1.00',
                'motif' => 'Test', 'alerteEcart' => '/api/alerte_ecart_caisses/' . $idAlerteB,
            ],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Une alerte d'écart, posée directement.
     *
     * `AlerteEcartCaisse.cloture` est NOT NULL — l'alerte n'existe pas sans son Z, et c'est juste :
     * un écart de caisse est **constaté par une clôture**, jamais dans le vide. On monte donc le
     * couple minimal session + clôture plutôt que de contourner la contrainte.
     */
    private function alerteEcart(string $idSession, string $idEtablissement): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idEtablissement);
        self::assertNotNull($etablissement);

        // On réutilise la session déjà ouverte pour la vente : une caisse n'en supporte qu'une à la
        // fois, et en rouvrir une seconde échoue — vérifié, c'est ce qui faisait tomber ce test.
        $sessionEntite = $em->getRepository(\App\Caisse\Entity\SessionCaisse::class)->find($idSession);
        self::assertNotNull($sessionEntite);

        $cloture = new \App\Caisse\Entity\ClotureZ();
        $cloture->setSession($sessionEntite);
        $em->persist($cloture);

        $alerte = new \App\Caisse\Entity\AlerteEcartCaisse();
        // `auteurCloture` est NOT NULL : une alerte d'écart nomme toujours qui a clôturé. C'est juste —
        // un manquant sans auteur n'est pas exploitable.
        $auteur = $em->getRepository(\App\Securite\Entity\Utilisateur::class)
            ->findOneBy(['email' => \App\DataFixtures\SocleFixtures::ADMIN_EMAIL]);
        self::assertNotNull($auteur);

        $alerte->setCloture($cloture)->setSession($sessionEntite)
            ->setEtablissement($etablissement)
            ->setAuteurCloture($auteur)
            ->setEcartMontant('-50.00')->setToleranceAppliquee('10.00');
        $em->persist($alerte);
        $em->flush();

        return (string) $alerte->getId();
    }

    /**
     * D46 / D55 — l'écart doit **savoir** qu'il est expliqué, sinon la liste ne descend jamais.
     *
     * `claude-H` a écrit l'écran complet puis refusé de le livrer : sans ce champ, l'utilisateur
     * explique un écart et la ligne reste, le lendemain elle est encore là avec les nouvelles.
     * C'est la liste qui apprend à son lecteur à l'ignorer — celle que sa propre règle interdit.
     */
    public function testUnEcartSaitQuIlEstExplique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->venteReglee($client, $entete, 'especes');
        $idAlerte = $this->alerteEcart($this->idSession($vente), $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM));

        $avant = $client->request('GET', '/api/alerte_ecart_caisses/' . $idAlerte, $entete)->toArray();
        self::assertFalse($avant['expliquee'], 'Un ecart sans correction n est pas explique.');

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/corriger-reglement', $entete + [
            'json' => [
                'moyenDebite' => 'especes', 'moyenCredite' => 'cb', 'montant' => '5.50',
                'motif' => 'Le manquant vient de cette saisie',
                'alerteEcart' => '/api/alerte_ecart_caisses/' . $idAlerte,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $apres = $client->request('GET', '/api/alerte_ecart_caisses/' . $idAlerte, $entete)->toArray();
        self::assertTrue($apres['expliquee'], 'Un ecart explique cesse d etre un ecart.');

        // Et dans la liste : c'est là que ça compte, puisque c'est elle qui doit se vider.
        $liste = $client->request('GET', '/api/alerte_ecart_caisses', $entete + ['query' => ['itemsPerPage' => 100]])->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        $trouve = null;
        foreach ($membres as $membre) {
            if ($membre['id'] === $idAlerte) {
                $trouve = $membre;
            }
        }
        self::assertNotNull($trouve, 'L alerte doit rester listee.');
        self::assertTrue($trouve['expliquee'], 'Le calcul doit valoir aussi en collection, pas seulement en detail.');
    }

    /** @param array<string, mixed> $vente */
    private function idSession(array $vente): string
    {
        $session = $vente['session'] ?? null;
        $reference = \is_array($session) ? ($session['id'] ?? '') : (string) $session;

        return str_contains((string) $reference, '/') ? basename((string) $reference) : (string) $reference;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function venteReglee(object $client, array $entete, string $moyen, ?\DateTimeImmutable $date = null): array
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => $moyen, 'montant' => '5.50'],
        ]);
        self::assertResponseIsSuccessful();

        if ($date !== null) {
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get('doctrine')->getManager();
            $entite = $em->getRepository(Vente::class)->find($vente['id']);
            self::assertNotNull($entite);
            $entite->setDate($date);
            $em->flush();
        }

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }
}
