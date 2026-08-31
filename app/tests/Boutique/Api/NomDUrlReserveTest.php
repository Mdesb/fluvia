<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Config\ReservedHostnames;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Service\VitrineResolver;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * D106 — le nom d'une boutique dans l'URL ne peut pas être un hôte technique.
 *
 * Le back-office, l'API et les boutiques partagent le même espace de noms. Une boutique nommée
 * « pro » servirait le back-office, ou l'inverse — et ça ne se découvre pas en revue de code, mais
 * le jour où un commercial saisit le nom d'un nouveau client.
 *
 * ⚠ **Le premier test de ce fichier est celui qui doit PASSER.** Un refus trop large est un défaut
 * qui ne ressemble pas à un défaut : `str_contains()` aurait refusé `api-boutique` et
 * `mail-du-parc`, et l'exploitant n'aurait vu qu'un produit capricieux. Les tests de refus seraient
 * restés verts pendant tout ce temps.
 */
final class NomDUrlReserveTest extends BoutiqueApiTestCase
{
    /**
     * ⚠ `$entete + [...]` NE MARCHE PAS ICI, et l'echec ne ressemble pas a sa cause.
     *
     * L'union de tableaux PHP garde les cles de GAUCHE. `adminSurA()` rend deja un `headers`, donc
     * un `Content-Type` ajoute a droite est jete en silence. API Platform recoit alors le defaut
     * `application/ld+json` et rend **415** — un refus sur le format de la requete, avant que la
     * validation ne soit atteinte. Le test echoue en affichant « 415 au lieu de 422 », ce qui se lit
     * comme « le refus metier ne marche pas » alors que le code mesure n'a jamais tourne.
     */
    private function patcherLeNom(string $nom): int
    {
        [$client, $entete] = $this->adminSurA();

        $options = $entete;
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $options['json'] = ['slug' => $nom];

        $this->derniereReponse = $client->request(
            'PATCH',
            '/api/boutique/vitrines/' . $this->idVitrineA(),
            $options
        );

        return $this->derniereReponse->getStatusCode();
    }

    private ?object $derniereReponse = null;

    /**
     * Le cas qui doit passer : un nom qui CONTIENT un mot réservé reste légitime.
     */
    public function testUnNomQuiContientUnMotReserveResteAccepte(): void
    {
        foreach (['api-boutique', 'mail-du-parc', 'assets-nautiques', 'test-piscine', 'proximite'] as $nom) {
            self::assertFalse(
                ReservedHostnames::isReserved($nom),
                sprintf('« %s » est légitime : le refus doit être une égalité exacte, jamais une inclusion.', $nom)
            );
        }
    }

    public function testLesDouzeNomsDeD106SontRefuses(): void
    {
        $attendus = ['pro', 'api', 'www', 'app', 'admin', 'mail', 'static', 'assets', 'cdn', 'status', 'dev', 'test'];
        self::assertSame($attendus, ReservedHostnames::NAMES, 'La liste de D106, verbatim.');

        foreach ($attendus as $nom) {
            self::assertTrue(ReservedHostnames::isReserved($nom), sprintf('« %s » doit être refusé.', $nom));
        }
    }

    /**
     * Le chemin de l'exploitant : il écrit son propre nom.
     */
    public function testUnExploitantNePeutPasRenommerSaBoutiqueEnHoteTechnique(): void
    {
        self::assertSame(422, $this->patcherLeNom('api'), 'D106 : « api » entrerait en collision avec l’API.');
        self::assertStringContainsString('réservé', $this->derniereReponse->getContent(false));
    }

    public function testUnNomDUrlMalFormeEstRefuse(): void
    {
        foreach (['Piscine Municipale', 'piscine/municipale', 'piscine--municipale', '-piscine', 'PISCINE'] as $mauvais) {
            self::assertSame(422, $this->patcherLeNom($mauvais), sprintf('« %s » n’est pas un nom d’URL valide.', $mauvais));
        }
    }

    /**
     * Sans ce cas, un refus total serait vert.
     */
    public function testUnNomDUrlNormalResteAccepte(): void
    {
        self::assertSame(200, $this->patcherLeNom('piscine-municipale-des-oliviers'));
        self::assertSame('piscine-municipale-des-oliviers', $this->derniereReponse->toArray()['slug']);
    }

    /**
     * ⚠ LE CHEMIN QUE PERSONNE NE REGARDE : le nom est FABRIQUÉ, pas saisi.
     *
     * `EstablishmentStampProcessor` appelle la fabrique depuis un *processor*, donc APRÈS la
     * validation. Un établissement nommé « Pro » aurait traversé l'`Assert` sans jamais être vu par
     * lui. C'est la moitié du trou que le refus sur l'entité ne bouche pas.
     */
    public function testUnEtablissementNommeProNeFabriquePasLHoteDuBackOffice(): void
    {
        $resolveur = new VitrineResolver($this->em());

        foreach (['Pro', 'API', 'www', 'Admin'] as $nomEtablissement) {
            $fabrique = $resolveur->fabriquerSlug($nomEtablissement);
            self::assertFalse(
                ReservedHostnames::isReserved($fabrique),
                sprintf('Un établissement nommé « %s » a produit « %s ».', $nomEtablissement, $fabrique)
            );
        }

        self::assertSame('pro-boutique', $resolveur->fabriquerSlug('Pro'));
        self::assertSame('piscine-municipale', $resolveur->fabriquerSlug('Piscine Municipale'), 'Un nom normal n’est pas suffixé.');
    }

    /**
     * Les deux contrôles vivent sur des chemins différents et pourraient diverger sans que rien ne
     * le dise : la fabrique produirait un nom que la saisie refuse, et l'exploitant ne pourrait plus
     * enregistrer sa propre boutique sans la renommer.
     */
    public function testCeQueLaFabriqueProduitEstToujoursAcceptableALaSaisie(): void
    {
        $resolveur = new VitrineResolver($this->em());
        $validateur = static::getContainer()->get('validator');

        foreach (['Pro', 'Piscine Municipale', 'Centre  Aquatique !', '中心', 'Établissement Été'] as $nom) {
            $vitrine = new Vitrine();
            $vitrine->setSlug($resolveur->fabriquerSlug($nom));

            $surLeSlug = [];
            foreach ($validateur->validate($vitrine) as $violation) {
                if ($violation->getPropertyPath() === 'slug') {
                    $surLeSlug[] = (string) $violation->getMessage();
                }
            }

            self::assertSame([], $surLeSlug, sprintf(
                'La fabrique a produit « %s » depuis « %s », que la saisie refuse.',
                (string) $vitrine->getSlug(),
                $nom
            ));
        }
    }
}
