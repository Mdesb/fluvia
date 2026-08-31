<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN LECTEUR PLACÉ ENTRE DEUX ACTIVITÉS LES DESSERT TOUTES LES DEUX.
 *
 * Depuis que les produits déclarent les zones qu'ils ouvrent, un tourniquet commun à la piscine et
 * à la salle de sport refusait les abonnements salle : le contrôleur ne connaissait qu'un espace,
 * et la décision ne regardait que celui-là.
 *
 * ⚠ CE QUE CES TESTS NE PROUVENT PAS, ET QU'IL FAUT SAVOIR.
 *
 * Ils portent sur la DÉCISION seule. La jauge décrémentée, la portée de l'anti-passback et l'espace
 * inscrit sur le passage restent ceux de l'espace PRINCIPAL, même quand le titre est accepté au
 * titre d'un espace desservi. C'est délibéré : le porteur a franchi cette porte-là, et une jauge ne
 * se décrémente pas à un endroit où personne n'est passé.
 */
final class LecteurMultizoneTest extends AccesApiTestCase
{
    /**
     * LE TITRE LIMITÉ À UNE AUTRE ZONE PASSE, SI LE LECTEUR DESSERT AUSSI CETTE ZONE.
     */
    public function testUnLecteurQuiDessertLaZoneDuTitreLaisssePasser(): void
    {
        $autre = $this->limiterLeDroitAUneAutreZone();
        $this->faireDesservirParLeControleur($autre);

        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'] ?? null);
    }

    /**
     * ET SANS CE RATTACHEMENT, LE MÊME TITRE RESTE REFUSÉ.
     *
     * ⚠ LE TÉMOIN DE LA PAIRE, et il est indispensable. Sans lui, une décision qui accepterait
     * TOUJOURS — la régression exacte que ce lot pourrait introduire en élargissant trop — passerait
     * le premier test avec les félicitations.
     */
    public function testSansRattachementLeTitreResteRefuse(): void
    {
        $this->limiterLeDroitAUneAutreZone();

        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful('un refus laisse une trace : un refus qui ne s’enregistre pas ne s’explique pas');

        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat'] ?? null);
        self::assertSame('zone_non_autorisee', $reponse['codeMotif'] ?? null);
    }

    /**
     * `espacesOuverts()` contient le principal ET les desservis.
     *
     * L'union est calculée à un seul endroit pour que la décision s'y adosse ; si elle oubliait le
     * principal, tout titre non restreint continuerait de passer et les deux tests ci-dessus
     * resteraient verts. Ce troisième cas est ce qui rend l'oubli visible.
     */
    public function testLUnionContientLePrincipalEtLesDesservis(): void
    {
        $autre = $this->limiterLeDroitAUneAutreZone();
        $controleur = $this->faireDesservirParLeControleur($autre);

        $numeros = array_map(
            static fn (EspaceAcces $e): string => (string) $e->getId(),
            $controleur->espacesOuverts(),
        );
        sort($numeros);

        $attendus = [(string) $autre->getId(), $this->idEspaceAcces()];
        sort($attendus);

        self::assertSame($attendus, $numeros);
    }

    /**
     * LA COLLECTION EST-ELLE ECRIVABLE PAR L'API — ET RETIRABLE ?
     *
     * ⚠ LES TROIS CAS CI-DESSUS APPELLENT `addServedSpace()` EN PHP. Ils prouvent que le domaine
     * calcule juste ; ils ne traversent jamais le sérialiseur. Or c'est là qu'était le défaut :
     *
     *     PATCH /api/controleurs/{id}  { servedSpaces: [...] }  ->  200, servedSpaces: []
     *
     * La propriété s'appelait `servedSpaces` et les accesseurs `addServedSpace`. L'inflecteur
     * anglais de Symfony ne singularise QUE LE DERNIER MOT : de `servedSpaces` il tire
     * `espacesDesservi`, donc il cherchait `addEspacesDesservi`. Les noms ne se rencontraient jamais,
     * la collection n'était pas modifiable, et **rien ne levait**.
     *
     * ⚠ PLUS VICIEUX QUE LES DEUX AUTRES « 200 MENTEURS ». Le premier manquait un `remove` : la
     * relecture le voit. Ici les deux accesseurs existaient, et la relecture disait « ils sont là » —
     * ce qui était vrai. Ce qui manquait n'était pas une méthode, c'était la rencontre entre deux
     * noms, et elle se produit dans une bibliothèque qu'on ne lit pas.
     *
     * Trouvé par allaccess-8e en interrogeant l'inflecteur, pas en relisant le code.
     *
     * ⚠ LE RETRAIT COMPTE AUTANT QUE L'AJOUT, et il compte séparément : une collection sans `remove`
     * accepte l'ajout et ignore la suppression, toujours en rendant 200.
     */
    public function testLesZonesDesserviesSEcriventEtSeRetirentParLApi(): void
    {
        [$client, $entete] = $this->adminSurA();
        $autre = $this->limiterLeDroitAUneAutreZone();
        $iri = '/api/espace_acces/'.$autre->getId();

        // ⚠ LES EN-TETES SE FUSIONNENT, ILS NE SE REMPLACENT PAS. `$entete + [...]` est une union :
        // sur la cle `headers`, la gauche gagne et le `Content-Type` serait jete -- la requete
        // partirait en `ld+json` et API Platform rendrait 415. Et remplacer `headers` tout court
        // perdrait l'en-tete d'etablissement, donc le cloisonnement fermerait la requete : le test
        // echouerait pour une raison qui n'a rien a voir avec ce qu'il mesure.
        $patch = static fn (array $espaces): array => [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['servedSpaces' => $espaces],
        ];

        // ── L'AJOUT ─────────────────────────────────────────────────────────────────────────────
        $client->request('PATCH', '/api/controleurs/'.$this->idControleur(), $patch([$iri]));
        self::assertResponseIsSuccessful();

        $apresAjout = $client->getResponse()->toArray()['servedSpaces'] ?? [];
        self::assertCount(
            1,
            $apresAjout,
            "Le serveur rend 200 : c'est ce qu'il ENREGISTRE qui décide, pas ce qu'il répond.",
        );

        // Relu depuis le serveur, et non depuis la réponse de l'écriture : une réponse peut refléter
        // l'objet en mémoire sans que rien ne soit parti en base.
        $relu = $client->request('GET', '/api/controleurs/'.$this->idControleur(), $entete)->toArray();
        self::assertCount(1, $relu['servedSpaces'] ?? [], 'La zone desservie doit survivre à la relecture.');

        // ── LE RETRAIT ──────────────────────────────────────────────────────────────────────────
        $client->request('PATCH', '/api/controleurs/'.$this->idControleur(), $patch([]));
        self::assertResponseIsSuccessful();

        $apresRetrait = $client->request('GET', '/api/controleurs/'.$this->idControleur(), $entete)->toArray();
        self::assertSame(
            [],
            $apresRetrait['servedSpaces'] ?? null,
            'Sans `remove`, la collection accepte l\'ajout et ignore la suppression — en rendant 200.',
        );
    }

    /**
     * Crée un espace distinct de celui de l'équipement et y limite le droit des fixtures.
     *
     * ⚠ `espaceSocle` est obligatoire en base : on reprend celui de l'espace existant plutôt que
     * d'en inventer un — le test porte sur le rattachement, pas sur la topologie du socle.
     */
    private function limiterLeDroitAUneAutreZone(): EspaceAcces
    {
        $em = $this->em();

        $existant = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $existant);

        $autre = (new EspaceAcces())
            ->setLibelle('Salle de sport, voisine du tourniquet')
            ->setEspaceSocle($existant->getEspaceSocle());
        $em->persist($autre);

        $droit = $em->getRepository(DroitAcces::class)->find($this->idDroit());
        self::assertInstanceOf(DroitAcces::class, $droit);

        // ⚠ « LIMITER À UNE AUTRE ZONE » DOIT REMPLACER, PAS AJOUTER — ET CE N'ÉTAIT PAS LE CAS.
        //
        // Tant que le jeu de données ne déclarait aucune zone, `add` suffisait : le droit passait de
        // « aucune » à « une autre », donc de « ouvre tout » à « ouvre ailleurs ». Depuis D87 la
        // fixture déclare la zone de l'équipement — `add` donnait alors un droit qui ouvre LES DEUX,
        // et le test attendait un refus en obtenant une validation.
        //
        // Le nom promettait « limiter » ; le code ajoutait. La différence ne se voyait que parce
        // qu'une donnée voisine était vide.
        foreach ($droit->getAuthorisedSpaces()->toArray() as $dejaOuvert) {
            $droit->removeAuthorisedSpace($dejaOuvert);
        }
        $droit->addAuthorisedSpace($autre);

        $em->flush();

        return $autre;
    }

    private function faireDesservirParLeControleur(EspaceAcces $espace): Controleur
    {
        $em = $this->em();

        $controleur = $em->getRepository(Controleur::class)->find($this->idControleur());
        self::assertInstanceOf(Controleur::class, $controleur);

        $controleur->addServedSpace($espace);
        $em->flush();

        return $controleur;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
