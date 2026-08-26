<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Service\DirectSalePoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D44-bis — vendre sans caisse, et **une seule phrase pour justifier qu'on le puisse**.
 *
 * Le cas réel : un gérant de salle de sport encaisse trois abonnements par carte dans le mois et n'a
 * jamais vu un tiroir-caisse. Jusqu'ici il ne pouvait pas vendre du tout — non pas par règle, mais
 * parce que `vente_vente.session_id` était `NOT NULL`.
 *
 * La séparation ne tient qu'à ceci : **sans espèces, il n'y a rien à compter, donc rien à clôturer.**
 * Ce fichier existe pour que cette phrase reste vraie. Si le refus des espèces tombait, on aurait
 * ouvert un chemin pour encaisser du liquide sans fonds de caisse, sans Z et sans personne pour en
 * répondre — et il ne resterait aucune raison d'avoir exigé une session de qui que ce soit.
 */
final class VenteDirecteTest extends VenteApiTestCase
{
    /** Le chemin nominal : sans session, réglée par carte, validée et scellée. */
    public function testUneVenteDirecteSOuvreSansSessionEtSeValide(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNull($vente['session'] ?? null, 'Une vente directe n\'a pas de session.');
        self::assertStringStartsWith('D-', $vente['numero'], 'Le numéro distingue la vente directe à l\'œil.');

        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            // Montant omis : `PaiementHandler` regle alors le reste du. Le figer a 5,50 EUR aurait fait
            // dependre ce test du prix d un produit de demonstration et d une promotion de guichet qui
            // ne s applique pas au meme canal — un rouge qui n aurait rien dit de la vente directe.
            'json' => ['moyen' => 'cb'],
        ]);
        self::assertResponseIsSuccessful();

        $validee = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $validee['statut']);

        // Le scellement NF525 exige un point de vente : sans lui la validation aurait rendu 422. C'est
        // exactement pourquoi la vente directe a besoin d'un point de vente **dédié**, et non d'aucun.
        self::assertNotNull($validee['pointDeVente'] ?? null, 'Une vente directe reste scellée sur un point de vente.');
    }

    /**
     * **Le test qui porte toute la décision.** Les espèces sont refusées hors session.
     *
     * Et le contrôle négatif compte autant : le même moyen, sur une vente de caisse, passe. Ce qui
     * refuse n'est donc pas le moyen — c'est l'absence d'un tiroir qui en répondrait.
     */
    public function testLesEspecesSontRefuseesSansSessionEtAccepteesEnCaisse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $directe = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterLigne($client, $entete, $directe['id']);

        $client->request('POST', '/api/ventes/' . $directe['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('D44-bis', $client->getResponse()->toArray(false)['detail'] ?? '');

        // Contrôle négatif — en caisse, le même moyen est parfaitement légitime.
        $session = $this->ouvrirSession($client, $entete);
        $enCaisse = $this->creerVente($client, $entete, $session['id']);
        $this->ajouterLigne($client, $entete, $enCaisse['id']);
        $client->request('POST', '/api/ventes/' . $enCaisse['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * **Les chèques aussi sont refusés, et c'est le cas qui a failli passer.**
     *
     * La première version du contrôle demandait au moyen s'il autorisait le rendu de monnaie. Les
     * quatre chèques du référentiel portent `autoriseRendu = false` : ils seraient donc passés, et une
     * vente directe aurait accepté du papier **sans que personne ne le détienne**.
     *
     * Un chèque n'est pas une écriture, c'est un objet : il se reçoit, se garde, se compte, se remet
     * en banque. « Rien à compter, donc rien à clôturer » devient faux dès qu'un chèque entre.
     */
    public function testLesChequesSontRefusesEnVenteDirecte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterLigne($client, $entete, $vente['id']);

        foreach (['cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs'] as $moyen) {
            $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
                'json' => ['moyen' => $moyen],
            ]);
            self::assertResponseStatusCodeSame(422, sprintf('« %s » est du papier : il exige un tiroir.', $moyen));
        }

        // Et la carte passe, sur la meme vente : ce n'est pas la vente directe qui refuse tout.
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'cb'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Le point de vente dédié est créé une fois et réutilisé.
     *
     * Deux points de vente « Vente directe » sur un même établissement scinderaient la chaîne NF525 en
     * deux moitiés chacune vérifiable, l'ensemble ne l'étant plus — le genre de dégât qu'on ne
     * constate qu'au contrôle.
     */
    public function testLePointDeVenteDedieEstUniqueParEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $premiere = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $seconde = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($premiere['pointDeVente'], $seconde['pointDeVente']);
        self::assertNotSame($premiere['numero'], $seconde['numero'], 'La numérotation avance par point de vente.');

        $points = $this->em()->getRepository(PointDeVente::class)->findBy(['libelle' => DirectSalePoint::LABEL]);
        self::assertCount(1, $points, 'Un seul point de vente dédié, sinon la chaîne se scinde.');
    }

    /**
     * Le droit est distinct de `vente.creer` — sinon tout caissier vendrait hors caisse.
     *
     * L'utilisateur de ce test a `vente.creer` et `vente.encaisser` : il ouvre parfaitement un panier
     * **sur une session**. C'est la seule absence du droit dédié qui le refuse hors session, et cette
     * distinction est le contrôle réel — un utilisateur sans aucun droit aurait rendu le même 403 pour
     * une raison qui n'a rien à voir.
     */
    public function testVendreSansSessionExigeUnDroitDedie(): void
    {
        [$client, $entete] = $this->connecteCaissierSansVenteDirecte();

        $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]]);
        self::assertResponseStatusCodeSame(403);

        // Le même utilisateur, sur une session : rien ne lui est refusé.
        $session = $this->ouvrirSession($client, $entete);
        $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $session['id']],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Une vente directe n'est pas marquée imprimée.
     *
     * Le seuil d'impression vaut 0 € par défaut, c'est-à-dire « imprimer systématiquement ». Sans
     * précaution, **toute** vente directe serait déclarée imprimée alors qu'aucun comptoir n'a de
     * ticket à sortir — un fait faux écrit dans une base comptable, ce qui est pire qu'une absence.
     */
    public function testUneVenteDirecteNEstPasDeclareeImprimee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            // Montant omis : `PaiementHandler` regle alors le reste du. Le figer a 5,50 EUR aurait fait
            // dependre ce test du prix d un produit de demonstration et d une promotion de guichet qui
            // ne s applique pas au meme canal — un rouge qui n aurait rien dit de la vente directe.
            'json' => ['moyen' => 'cb'],
        ]);
        $validee = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();

        self::assertFalse($validee['imprime'] ?? true);
    }

    /** @param array<string, mixed> $entete */
    private function ajouterLigne(Client $client, array $entete, string $venteId): void
    {
        $client->request('POST', '/api/ventes/' . $venteId . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function connecteCaissierSansVenteDirecte(): array
    {
        $em = $this->em();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $role = (new Role())->setNom('Caissier sans vente directe');
        foreach ([['vente', 'lire'], ['vente', 'creer'], ['vente', 'encaisser'], ['caisse', '*']] as $couple) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => $couple[0], 'action' => $couple[1]]);
            if ($permission instanceof Permission) {
                $role->addPermission($permission);
            }
        }
        $em->persist($role);

        /** @var UserPasswordHasherInterface $hacheur */
        $hacheur = static::getContainer()->get(UserPasswordHasherInterface::class);
        $email = 'caissier.sans.directe@test.itcotation.com';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($email)->setActif(true);
        $utilisateur->setMotDePasse($hacheur->hashPassword($utilisateur, 'aaa'));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabA));
        $em->flush();

        $client = static::createClient();
        $client->disableReboot();
        $entete = [
            'auth_bearer' => $this->jeton($client, $email, 'aaa'),
            'headers' => [ContexteEtablissement::HEADER => (string) $etabA->getId()],
        ];

        return [$client, $entete];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
