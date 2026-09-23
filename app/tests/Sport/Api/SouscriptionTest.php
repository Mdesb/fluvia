<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Sepa\Entity\MandatSepa;
use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Entity\SubscriptionContract;
use App\Signature\Entity\ElectronicSignature;
use App\Tests\Sport\SportApiTestCase;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Souscription d'un abonnement fitness (US-SPORT-01, CA-1, RG-M1-03) : abonnement `actif`, mandat SEPA
 * signé (IBAN jamais en clair en base), échéancier mensuel généré jusqu'à la fin d'engagement, droit
 * d'accès actif dès le rattachement synchrone (§0 point 5 du plan).
 */
final class SouscriptionTest extends SportApiTestCase
{
    /**
     * LA SOUSCRIPTION SIGNE ET SCELLE LE MANDAT ET LE CONTRAT.
     *
     * Jusqu'ici, poser IBAN + titulaire marquait le mandat « actif » sans aucune preuve de
     * consentement, et aucun contrat n'existait. Ce témoin prouve le câblage bout-en-bout : le
     * processor appelle les signers, deux signatures scellées atterrissent en base, le contrat est
     * gelé et lié à la sienne, et l'image manuscrite du tunnel est portée.
     */
    public function testLaSouscriptionSigneEtScelleLeMandatEtLeContrat(): void
    {
        [$client, $entete] = $this->adminSurA();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $enfant = $em->getRepository(Client::class)->findOneBy(['prenom' => CrmFixtures::ENFANT_PRENOM]);

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'adherent' => '/api/clients/' . $enfant->getId(),
                'payeur' => '/api/clients/' . $payeur->getId(),
                'formule' => '/api/formules/' . $produitGold->getFormule()->getId(),
                'dureeEngagementMois' => 12,
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Jean Dupont',
                // Le tunnel enverra l'image manuscrite ; ici on prouve que le chemin la porte.
                'signatureMandat' => 'faux-png-mandat',
                'signatureContrat' => 'faux-png-contrat',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $abonnementId = $client->getResponse()->toArray()['id'];
        $em->clear();

        $abonnement = $em->getRepository(Membership::class)->find($abonnementId);
        self::assertNotNull($abonnement);
        $etablissement = $abonnement->getEtablissement();

        // Deux signatures pour cet établissement : le mandat et le contrat, chacune scellée.
        $signatures = $em->getRepository(ElectronicSignature::class)->findBy(['etablissement' => $etablissement]);
        $parType = [];
        foreach ($signatures as $sig) {
            $parType[$sig->getDocumentType()->value] = $sig;
        }
        self::assertArrayHasKey('sepa_mandate', $parType, 'le mandat est signé');
        self::assertArrayHasKey('subscription_contract', $parType, 'le contrat est signé');
        foreach ($parType as $sig) {
            self::assertNotSame('', $sig->getSeal(), 'chaque signature est scellée (HMAC)');
            self::assertNotSame('', $sig->getDocumentHash(), 'chaque signature est liée à un document');
        }
        // La signature du mandat porte l'image manuscrite reçue du tunnel.
        self::assertSame('faux-png-mandat', $parType['sepa_mandate']->getSignatureImage());

        // Le contrat existe, gelé, lié à sa signature, et son hash colle à son texte.
        $contrat = $em->getRepository(SubscriptionContract::class)->findOneBy(['subscription' => $abonnement]);
        self::assertNotNull($contrat, 'un contrat signé est créé');
        self::assertNotSame('', $contrat->getDocumentText());
        self::assertSame(
            hash('sha256', $contrat->getDocumentText()),
            $contrat->getSignature()?->getDocumentHash(),
            'la preuve du contrat colle à son texte gelé',
        );
    }

    /**
     * LA SOUSCRIPTION ÉMET UN BILLET D'ACCÈS (support QR) RATTACHÉ AU STATUT.
     *
     * Sans lui, le `StatutAccesFitness` naissait avec `droitAcces = null` : aucun support, et la
     * porte ne s'ouvrait pas (le terminal lit un support, jamais l'abonnement). Ce témoin prouve
     * qu'un droit d'accès est créé ET qu'un support QR signé — vérifiable par le terminal
     * (`GenerateurCodeSupport`) — est émis et dénormalisé sur le statut pour l'affichage/impression.
     */
    public function testLaSouscriptionEmetUnBilletDAccesRattacheAuStatut(): void
    {
        [$client, $entete] = $this->adminSurA();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'payeur' => '/api/clients/' . $payeur->getId(),
                'formule' => '/api/formules/' . $produitGold->getFormule()->getId(),
                'dureeEngagementMois' => 12,
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Jean Dupont',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $abonnementId = $client->getResponse()->toArray()['id'];
        $em->clear();

        $abonnement = $em->getRepository(Membership::class)->find($abonnementId);
        $statut = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement]);
        self::assertNotNull($statut, 'un statut d\'accès est créé');
        self::assertNotNull($statut->getDroitAcces(), 'le droit d\'accès est créé et rattaché : la porte peut s\'ouvrir');

        $code = $statut->getSupportIdentifiant();
        self::assertNotNull($code, 'un support QR (billet) est émis à la souscription');
        self::assertNotSame('', $code);

        // ⚠ LE VRAI CRITÈRE : le code est SIGNÉ et VÉRIFIABLE par ce que lit le terminal. Un code
        // non signé serait refusé à la porte (SignatureInvalide) — un billet qui n'ouvre rien.
        /** @var GenerateurCodeSupport $generateur */
        $generateur = static::getContainer()->get(GenerateurCodeSupport::class);
        self::assertTrue($generateur->estCodeSigne($code), 'le code du billet est un code signé');
        self::assertTrue($generateur->verifier($code), 'le terminal accepterait la signature du billet');
    }

    public function testCa1SouscriptionCreeAbonnementActifMandatEtEcheancier(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertNotNull($produitGold);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $enfant = $em->getRepository(Client::class)->findOneBy(['prenom' => CrmFixtures::ENFANT_PRENOM]);
        $adherent = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $enfant]);
        self::assertNotNull($payeur);
        self::assertNotNull($adherent);

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'adherent' => '/api/clients/' . $enfant->getId(),
                'payeur' => '/api/clients/' . $payeur->getId(),
                'formule' => '/api/formules/' . $produitGold->getFormule()->getId(),
                'dureeEngagementMois' => 12,
                // montant retire : le prix est resolu depuis la grille tarifaire (arbitrage 01/09).
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Jean Dupont',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $abonnement = $client->getResponse()->toArray();
        self::assertSame('actif', $abonnement['statut']);
        self::assertArrayHasKey('mandatSepa', $abonnement);

        $abonnementId = $abonnement['id'];

        // ── TÉMOIN DU NOUVEAU CONTRAT : l'adhérent est un CLIENT, résolu en bénéficiaire ─────────
        // On a désigné le CLIENT de l'enfant ; le serveur doit l'avoir résolu (forPurchase) en le
        // bénéficiaire attaché à cet enfant — celui-là même que la souscription au comptoir emploie.
        $abonnementEntite = $em->getRepository(Membership::class)->find($abonnementId);
        self::assertNotNull($abonnementEntite);
        self::assertSame(
            (string) $adherent->getId(),
            (string) $abonnementEntite->getAdherent()?->getId(),
            'Le client adhérent désigné doit être résolu en son bénéficiaire (forPurchase).',
        );

        // Mandat signé, IBAN jamais exposé (embarqué dans la réponse abonnement, groupe `abonnement:read`).
        self::assertSame('actif', $abonnement['mandatSepa']['statut']);
        self::assertSame('0189', $abonnement['mandatSepa']['iban4Derniers']);
        self::assertArrayNotHasKey('ibanToken', $abonnement['mandatSepa']);
        self::assertArrayNotHasKey('ibanChiffre', $abonnement['mandatSepa']);

        $mandatIri = $abonnement['mandatSepa']['@id'];
        $client->request('GET', $mandatIri, $entete);
        self::assertResponseIsSuccessful();
        $mandat = $client->getResponse()->toArray();
        self::assertSame('actif', $mandat['statut']);
        self::assertSame('0189', $mandat['iban4Derniers']);
        self::assertArrayNotHasKey('ibanToken', $mandat);
        self::assertArrayNotHasKey('ibanChiffre', $mandat);
        self::assertArrayNotHasKey('iban', $mandat);

        // Échéancier mensuel généré jusqu'à la fin d'engagement (12 échéances).
        $client->request('GET', '/api/echeance_sepas', $entete + ['query' => ['abonnement' => $abonnementId, 'itemsPerPage' => 50]]);
        self::assertResponseIsSuccessful();

        /** @var list<EcheanceSepa> $echeances */
        $echeances = $em->getRepository(EcheanceSepa::class)->findBy(['abonnement' => $abonnementId]);
        self::assertCount(12, $echeances);
        foreach ($echeances as $echeance) {
            self::assertSame('a_venir', $echeance->getStatut()->value);
            self::assertSame(3990, $echeance->getMontantCentimes());
        }

        // StatutAccesFitness ouvert, actif par défaut (droit d'accès rattaché ensuite en agence).
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statutAcces);
        self::assertTrue($statutAcces->isActif());

        // IBAN jamais persisté en clair (jeton HMAC), mais bien chiffré de façon réversible (coffre
        // IBAN) — nécessaire pour que le pain.008 porte le vrai IBAN lors d'une remise réelle.
        $mandatEntite = $em->getRepository(MandatSepa::class)->find($mandat['id']);
        self::assertNotNull($mandatEntite);
        self::assertStringNotContainsString('FR7630006000011234567890189', $mandatEntite->getIbanToken());
        self::assertNotNull($mandatEntite->getIbanChiffre());
        self::assertStringNotContainsString('FR7630006000011234567890189', (string) $mandatEntite->getIbanChiffre());
        /** @var \App\Sepa\Service\ChiffreurIbanInterface $chiffreur */
        $chiffreur = static::getContainer()->get(\App\Sepa\Service\ChiffreurIbanInterface::class);
        self::assertSame('FR7630006000011234567890189', $chiffreur->dechiffrer((string) $mandatEntite->getIbanChiffre()));
    }

    public function testDroitAccesActifDesLeRattachementSynchrone(): void
    {
        [$client, $entete] = $this->adminSurA();

        $abonnementId = $this->idAbonnementDemo();
        $droitId = $this->idDroitAccesDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/rattacher-droit-acces', $entete + [
            'json' => ['droitAcces' => '/api/droit_acces/' . $droitId],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statutAcces);
        self::assertNotNull($statutAcces->getDroitAcces());
        self::assertSame('valide', $statutAcces->getDroitAcces()->getStatutProjection()->value);
    }

    /**
     * LE PAYEUR HORS PÉRIMÈTRE EST INTROUVABLE (cloisonnement, constat 5).
     *
     * `payeur` était résolu par `find()` sans contrôle : un admin du groupe A souscrivait un
     * abonnement — mandat, échéancier, accès, billet QR — au nom du client d'un AUTRE groupe, et la
     * 201 fuyait sa PII. Ce témoin prouve le refus (404, D3) et l'absence de toute création.
     */
    public function testLaSouscriptionRefuseUnPayeurHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        $formuleId = (string) $produitGold->getFormule()->getId();

        // Un client d'un AUTRE groupe (Groupe B), hors du périmètre de l'admin de A.
        $groupeB = $em->getRepository(Groupe::class)->findOneBy(['nom' => CrmFixtures::GROUPE_B_NOM]);
        $etabC = $em->getRepository(Etablissement::class)->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);
        self::assertNotNull($groupeB);
        $payeurB = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupeB)
            ->setEtablissementCreation($etabC)
            ->setNom('Concurrent')
            ->setPrenom('Client');
        $em->persist($payeurB);
        $em->flush();
        $payeurBId = (string) $payeurB->getId();

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'payeur' => '/api/clients/' . $payeurBId,
                'formule' => '/api/formules/' . $formuleId,
                'dureeEngagementMois' => 12,
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Client Concurrent',
            ],
        ]);
        // 404 et non 403 (D3) : hors périmètre = introuvable.
        self::assertResponseStatusCodeSame(404);

        // Et RIEN n'a été créé pour ce client hors périmètre.
        $em->clear();
        $payeurBApres = $em->getRepository(Client::class)->find($payeurBId);
        self::assertNull(
            $em->getRepository(Membership::class)->findOneBy(['payeur' => $payeurBApres]),
            'aucun abonnement ne doit exister pour le client hors périmètre',
        );
    }

    /**
     * RATTACHER UN NOUVEAU DROIT RÉVOQUE L'ANCIEN BILLET QR.
     *
     * Depuis l'émission du QR à la souscription, `droitAcces` porte un support avec un appairage
     * ACTIF. L'écraser sans révoquer laissait ce QR ouvrir la porte à jamais — même résilié. Ce
     * témoin prouve qu'au rattachement d'un nouveau droit, l'ancien est dévalidé et son appairage
     * coupé.
     */
    public function testLeRattachementRevoqueLAncienBilletQr(): void
    {
        [$client, $entete] = $this->adminSurA();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);

        // 1. Souscrire → un statut avec un droit QR + un appairage actif.
        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'payeur' => '/api/clients/' . $payeur->getId(),
                'formule' => '/api/formules/' . $produitGold->getFormule()->getId(),
                'dureeEngagementMois' => 12,
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Jean Dupont',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $abonnementId = $client->getResponse()->toArray()['id'];
        $em->clear();

        $statut = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statut);
        $ancienDroit = $statut->getDroitAcces();
        self::assertNotNull($ancienDroit, 'la souscription a posé un droit QR');
        $ancienDroitId = (string) $ancienDroit->getId();
        self::assertNotNull(
            $em->getRepository(Appairage::class)->findOneBy(['droit' => $ancienDroit, 'actif' => true]),
            'le billet QR a un appairage actif avant rattachement',
        );

        // 2. Rattacher un nouveau droit libre.
        $nouveauDroitId = $this->idDroitAccesDemo();
        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/rattacher-droit-acces', $entete + [
            'json' => ['droitAcces' => '/api/droit_acces/' . $nouveauDroitId],
        ]);
        self::assertResponseIsSuccessful();
        $em->clear();

        // 3. L'ancien droit QR est dévalidé et son appairage ne peut plus ouvrir.
        $ancien = $em->getRepository(DroitAcces::class)->find($ancienDroitId);
        self::assertNotNull($ancien);
        self::assertSame('devalide', $ancien->getStatutProjection()->value, 'l\'ancien billet QR est dévalidé');
        self::assertNull(
            $em->getRepository(Appairage::class)->findOneBy(['droit' => $ancien, 'actif' => true]),
            'l\'appairage de l\'ancien billet QR est révoqué : il n\'ouvre plus',
        );

        // Le statut pointe désormais le nouveau droit, valide.
        $statut2 = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statut2->getDroitAcces());
        self::assertSame((string) $nouveauDroitId, (string) $statut2->getDroitAcces()->getId());
        self::assertSame('valide', $statut2->getDroitAcces()->getStatutProjection()->value);
    }
}
