<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /factures/{id}/facturx` (Phase 1) : le document OPPOSABLE — le PDF/A-3 Factur-X (EN 16931), le
 * même que produit la commande `facturation:einvoicing:emettre --facturx`, désormais téléchargeable
 * depuis l'écran. La CONFORMITÉ du fichier (PDF/A-3B + schematron EN 16931 + Factur-X) est prouvée
 * hors-ligne par `infra/valider-facturx.sh` ; ici on prouve le CÂBLAGE : la route rend bien un PDF
 * binaire aux bons en-têtes, et le cloisonnement ferme en 404 (comme `/rendu`).
 */
final class FacturXTelechargementApiTest extends FacturationApiTestCase
{
    /**
     * Une facture COMPLÈTE (destinataire avec adresse) est émettable : la route rend son Factur-X.
     */
    public function testTelechargementRendUnPdfFacturX(): void
    {
        [$client, $entete] = $this->adminSurA();

        // ⚠ DEUX TERMES EN 16931 MANQUENT DANS LES JEUX DE DÉMONSTRATION, ET C'EST LE CHANTIER DONNÉES
        // DE LA PHASE 1 : (1) l'adresse de l'acheteur (fournie dans le destinataire ci-dessous) ;
        // (2) la catégorie de TVA du taux (BT-151), nulle par défaut sur `TauxTva`. On la pose ici sur
        // le taux employé par la vente, pour prouver le chemin nominal du téléchargement.
        $taux = $this->entite(TauxTva::class, ['libelle' => 'Taux normal 20 %']);
        $taux->setVatCategory(VatCategory::Standard);
        static::getContainer()->get('doctrine')->getManager()->flush();

        // Facture réelle et émise sur A, destinataire complet.
        $vente = $this->creerVenteValidee($client, $entete);
        $facture = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id'], 'destinataire' => [
                'type' => 'personne_morale', 'raisonSociale' => 'Client de test', 'siret' => '12345678900011',
                'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            ]],
        ])->toArray();

        $reponse = $client->request('GET', '/factures/' . $facture['id'] . '/facturx', $entete);

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $enTetes = $reponse->getHeaders(false);
        self::assertStringStartsWith('application/pdf', $enTetes['content-type'][0] ?? '');
        self::assertStringContainsString(
            sprintf('attachment; filename="%s.pdf"', $facture['numero']),
            $enTetes['content-disposition'][0] ?? '',
        );

        $octets = (string) $reponse->getContent(false);
        self::assertStringStartsWith('%PDF-', $octets, 'le corps doit être un PDF binaire.');
        // Un Factur-X porte un XML embarqué : il pèse bien plus qu'un PDF vide. Le témoin validé par
        // veraPDF faisait ~22 ko ; on refuse un fichier suspicieusement petit sans réimplémenter veraPDF.
        self::assertGreaterThan(5000, \strlen($octets), 'un Factur-X complet dépasse largement 5 ko.');
    }

    /**
     * Une facture INCOMPLÈTE est refusée — et le refus NOMME le terme manquant **jusqu'à l'écran**.
     *
     * ⚠ LE TÉMOIN PORTE SUR LA PHRASE, PAS SUR LE STATUT. Le contrôleur levait
     * `UnprocessableEntityHttpException` : le 422 arrivait, la phrase non — en production, Symfony ne
     * relaie pas le message d'une exception, et l'exploitant lisait « Unprocessable Content ». Tout le
     * soin mis par `InvoiceNotEmittableException` à nommer chaque terme et son emplacement se perdait
     * exactement là où il servait : devant la personne qui doit corriger la fiche.
     *
     * Un test sur le seul code de statut serait resté vert pendant tout ce temps. C'est pourquoi
     * celui-ci lit `detail`.
     */
    public function testTelechargementRefuseUneFactureIncompleteEtNommeCeQuiManque(): void
    {
        [$client, $entete] = $this->adminSurA();

        // ⚠ ON NE POSE PAS `VatCategory` : BT-151 reste nulle, et c'est le terme qui manquera.
        // Le test nominal ci-dessus la pose explicitement — la différence entre les deux EST le cas.
        $vente = $this->creerVenteValidee($client, $entete);
        $facture = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id'], 'destinataire' => [
                'type' => 'personne_morale', 'raisonSociale' => 'Client de test', 'siret' => '12345678900011',
                'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            ]],
        ])->toArray();

        $reponse = $client->request('GET', '/factures/' . $facture['id'] . '/facturx', $entete);

        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $corps = $reponse->toArray(false);
        self::assertStringContainsString(
            'terme(s) obligatoire(s) manquent',
            (string) ($corps['detail'] ?? ''),
            'le refus doit porter la phrase qui nomme ce qui manque, pas le libellé générique du statut.',
        );
    }

    /**
     * TÉMOIN DE CLOISONNEMENT (RG-SOCLE-05) : un lecteur `facturation.lire` affecté à B passe la
     * sécurité de route mais n'a aucun droit sur une facture de A. On répond 404 — jamais 200, jamais
     * 403 (un 403 confirmerait l'existence de la facture d'un voisin, montants et PII compris), comme
     * le fait déjà `/factures/{id}/rendu`.
     */
    public function testFactureDunAutreEtablissementRenvoie404(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $vente = $this->creerVenteValidee($clientA, $enteteA);
        $facture = $clientA->request('POST', '/api/factures/depuis-vente', $enteteA + [
            'json' => ['vente' => '/api/ventes/' . $vente['id'], 'destinataire' => [
                'type' => 'personne_morale', 'raisonSociale' => 'Client de test', 'siret' => '12345678900011',
                'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            ]],
        ])->toArray();

        [$emailB, $mdpB] = $this->creerLecteurFacturationSurB();
        $clientB = static::createClient();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $this->jeton($clientB, $emailB, $mdpB), 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $reponse = $clientB->request('GET', '/factures/' . $facture['id'] . '/facturx', $enteteB);
        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    public function testFactureInexistanteRenvoie404(): void
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('GET', '/factures/' . Uuid::v4() . '/facturx', $entete);
        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un lecteur facturation.lire affecté à B seul. */
    private function creerLecteurFacturationSurB(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $permLire = $em->getRepository(Permission::class)->findOneBy(['module' => 'facturation', 'action' => 'lire']);
        self::assertInstanceOf(Permission::class, $permLire, 'La permission facturation.lire doit etre semee.');

        $role = (new Role())->setNom('Lecteur facturation B (test facturx)');
        $role->addPermission($permLire);
        $em->persist($role);

        $email = 'facturx-b-' . uniqid() . '@itcotation.com';
        $mdp = 'aaa';
        $user = (new Utilisateur())->setEmail($email)->setNom('Lecteur Facturation B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, $mdp));
        $em->persist($user);

        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        return [$email, $mdp];
    }
}
