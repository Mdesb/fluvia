<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Caisse\Enum\EtatSession;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Padel\Entity\ParametragePadel;
use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE CRÉNEAU DE PADEL DEVIENT UNE LIGNE COMPTABLE — R15.
 *
 * Maxime : « vérifier que la durée des parties permet de faire un produit qui rentre bien dans la
 * comptabilité ». Mesuré de bout en bout, ça ne rentrait nulle part : `ReserverTerrainProcessor`
 * posait `montantDu` et `ModeDecompteReservation::VenteUnite`, puis s'arrêtait. Aucune `Vente`.
 * Réserver un terrain produisait une **dette que rien n'encaissait**, et un mode de décompte qui
 * annonçait une vente inexistante — alors que `LocationMateriel` et `InscriptionTournoi`, dans le
 * même module, rattachent bien la leur.
 *
 * ⚠ CE QUI DISTINGUE CE TEST D'UN TEST QUI PASSERAIT SANS LE CORRECTIF.
 * Vérifier « une vente existe » ne suffirait pas : `VenteReservationHandler` tire un `Uuid::v4()`
 * au hasard quand aucun produit ne lui est fourni, et rendrait une vente parfaitement valide
 * pointant vers un produit **qui n'existe pas** — donc sans catégorie comptable, donc invisible à
 * la ventilation. C'est l'ÉGALITÉ avec `ParametragePadel::$produitTerrainRef` qui est le témoin,
 * et rien d'autre.
 *
 * ⚠ ET `produitTerrainRef` EXISTAIT DÉJÀ. Getter, setter, et deux docblocks qui le citent en
 * exemple à imiter — personne ne le lisait. Les fixtures ne le posent même pas.
 */
final class VenteReservationTerrainTest extends PadelApiTestCase
{
    public function testLaReservationRattacheSaVenteAvecLeProduitParametre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $produitTerrain = $this->poserProduitTerrain();
        $session = $this->sessionOuverteSur(SocleFixtures::ETAB_A_NOM);

        $idReservation = $this->reserverTerrain($client, $entete, (string) $session->getId());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation, 'témoin : la réservation existe bien');

        $vente = $reservation->getVenteRattachee();
        self::assertNotNull(
            $vente,
            'une réservation payante doit porter sa vente — sans elle, le montant dû n’est encaissé nulle part',
        );

        $lignes = $vente->getLignes()->toArray();
        self::assertCount(1, $lignes, 'une réservation de terrain, une ligne');

        // ⚠ L'ORIGINE DE LA RECETTE (§8.12). Sans la ressource, « combien le padel a-t-il rapporté »
        // reste sans réponse : la ventilation comptable ne voit que la catégorie du produit, et un
        // créneau de padel ne porte AUCUNE activité — c'est la ressource qui mène au sport, via
        // `TerrainPadel::$sport`.
        $terrain = $em->getRepository(\App\Padel\Entity\TerrainPadel::class)->find($this->idTerrain());
        self::assertNotNull($terrain, 'témoin : le terrain existe');
        self::assertSame(
            (string) $terrain->getRessource()?->getId(),
            (string) $lignes[0]->getRessource(),
            'la ligne doit dire QUEL ÉQUIPEMENT a été occupé — sinon le padel est invisible dans '
            . 'les recettes, et la question ne pourra plus jamais être posée sur ces ventes',
        );

        self::assertSame(
            (string) $produitTerrain,
            (string) $lignes[0]->getProduit(),
            'LE PRODUIT DOIT ÊTRE CELUI DU PARAMÉTRAGE. Un Uuid tiré au hasard donnerait une vente '
            . 'valide pointant vers un produit inexistant : aucune catégorie comptable, donc rien '
            . 'dans la ventilation — et ce test passerait quand même si on ne comparait pas.',
        );
    }

    /**
     * ⚠ LE TÉMOIN NÉGATIF : RÉSERVER SANS CAISSE RESTE POSSIBLE.
     *
     * Le processeur générique (`ReserverProcessor`) lève un 422 quand une réservation payante
     * n'est pas accompagnée d'une session. L'appliquer ici refuserait toute réservation faite
     * ailleurs qu'au comptoir — aucun des trois tests qui empruntent cette route n'en passe une,
     * et l'écran ne sait pas en envoyer.
     *
     * Ce test existe pour que ce choix soit visible : si quelqu'un rend la session obligatoire, il
     * casse ce test et lit pourquoi, au lieu de casser les réservations en ligne.
     */
    public function testSansSessionLaReservationResteAcceptee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->poserProduitTerrain();
        $idReservation = $this->reserverTerrain($client, $entete, null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        self::assertNull(
            $reservation->getVenteRattachee(),
            'sans caisse ouverte, la réservation reste due — et c’est assumé, pas oublié',
        );
    }

    /**
     * ⚠ D8 — LA QUATRIÈME PORTE DE LA MÊME FAMILLE, ET ELLE S'OUVRAIT EN AJOUTANT CE PARAMÈTRE.
     *
     * L'établissement de la session détermine celui de la vente créée. Résoudre la session par un
     * `find()` nu ne donnerait pas seulement accès à celle d'un autre établissement : cela y
     * **créerait une écriture**. Les trois autres portes (`ReserverProcessor`,
     * `MouvementCaisseProcessor`, `EmettreVenteNoShowProcessor`) ont été fermées les 19 et 23/08.
     *
     * 404 et non 403 : un 403 confirmerait que la session existe ailleurs.
     */
    public function testUneSessionDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->poserProduitTerrain();
        $sessionB = $this->sessionOuverteSur(SocleFixtures::ETAB_B_NOM);

        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
                'session' => (string) $sessionB->getId(),
            ],
        ]);

        self::assertResponseStatusCodeSame(
            404,
            'la session d’un autre établissement doit être introuvable — sinon on y crée une écriture',
        );
    }

    /**
     * ⚠ SANS PRODUIT PARAMÉTRÉ, LA VENTE EST REFUSÉE — elle n'est plus inventée.
     *
     * `VenteReservationHandler` faisait `setProduit($produitRef ?? Uuid::v4())` : sans produit, la
     * ligne désignait un produit qui n'existe pas — aucune catégorie comptable, donc absente de la
     * ventilation, et `LineLabelStamper` laissait le libellé nul, ce qu'il documente lui-même.
     *
     * Arbitrage de Maxime le 04/09 : produit obligatoire. Le paramètre est désormais NON NULLABLE,
     * et chaque appelant doit dire quoi faire quand il n'en a pas.
     *
     * ⚠ CE TEST NE PEUT PASSER QUE SI LE REFUS EXISTE. Sans lui la réservation aboutirait, avec une
     * vente parfaitement valide pointant vers un produit fantôme, et rien ne le signalerait.
     *
     * ⚠ Et une réservation SANS session reste acceptée — c'est ce que prouve le test voisin. Un
     * paramétrage manquant ne doit bloquer que l'encaissement, pas la réservation.
     */
    public function testSansProduitParametreLEncaissementEstRefuse(): void
    {
        [$http, $entete] = $this->adminSurA();
        $http->disableReboot();

        // On ne pose PAS `produitTerrainRef` : c'est l'état de la préproduction, mesuré le 04/09.
        $session = $this->sessionOuverteSur(SocleFixtures::ETAB_A_NOM);

        $http->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + [
            'json' => [
                'debut' => (new \DateTimeImmutable('next tuesday'))->setTime(19, 0)->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
                'session' => (string) $session->getId(),
            ],
        ]);

        self::assertResponseStatusCodeSame(
            422,
            'sans produit paramétré, la vente doit être REFUSÉE — pas créée sur un produit inventé',
        );
    }

    /** Réserve un terrain, avec ou sans session de caisse, et rend l'identifiant de la réservation. */
    private function reserverTerrain(object $client, array $entete, ?string $session): string
    {
        $corps = [
            'debut' => (new \DateTimeImmutable('next monday'))->setTime(19, 0)->format(DATE_ATOM),
            'dureeMinutes' => 90,
            'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
        ];
        if ($session !== null) {
            $corps['session'] = $session;
        }

        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        return basename((string) $client->getResponse()->toArray()['reservation']);
    }

    /**
     * Pose le produit du catalogue sous lequel le créneau se vend, et rend son identifiant.
     *
     * ⚠ LES FIXTURES NE LE POSENT PAS — c'est le symptôme d'origine : le champ existe depuis
     * toujours, et rien ne le lisait, donc rien ne le remplissait non plus.
     */
    private function poserProduitTerrain(): Uuid
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $parametrage = $em->getRepository(ParametragePadel::class)
            ->findOneBy(['etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]);
        self::assertNotNull($parametrage, 'témoin : le paramétrage padel de l’établissement A existe');

        $produit = Uuid::v4();
        $parametrage->setProduitTerrainRef($produit);
        $em->flush();

        return $produit;
    }

    /** Une session de caisse OUVERTE sur l'établissement demandé (RG-M2-01). */
    private function sessionOuverteSur(string $nomEtablissement): SessionCaisse
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etablissement = $em->getRepository(Etablissement::class)->find($this->idEtablissement($nomEtablissement));
        self::assertNotNull($etablissement);
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertNotNull($admin);

        $pdv = (new PointDeVente())->setLibelle('PDV test padel')->setEtablissement($etablissement);
        $em->persist($pdv);
        $caisse = (new Caisse())->setLibelle('Caisse test padel')->setPointDeVente($pdv)->setEtat(EtatCaisse::Ouverte);
        $em->persist($caisse);

        // ⚠ L'ÉTAT EST POSÉ EXPLICITEMENT : le correctif appelle `estOuverte()`, qui compare à
        // `EtatSession::Ouverte`. S'en remettre à la valeur par défaut ferait dépendre ce test d'un
        // détail d'une autre entité, et le rendrait faux le jour où ce défaut change.
        $session = (new SessionCaisse())->setNumero('S-PADEL-' . uniqid())
            ->setPointDeVente($pdv)->setCaisse($caisse)
            ->setRegisseur($admin)->setOperateur($admin)
            ->setFondDeCaisse('0.00')->setEtablissement($etablissement)
            ->setEtat(EtatSession::Ouverte);
        $em->persist($session);
        $em->flush();

        return $session;
    }
}
