<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Command\BuildFrameAncestorsMap;
use App\Boutique\Entity\Vitrine;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * QUI A LE DROIT D'ENCADRER UNE BOUTIQUE — et pourquoi la réponse par défaut est « personne ».
 *
 * **Le défaut qu'on répare.** Sans en-tête `frame-ancestors`, n'importe quel site peut afficher la
 * boutique d'un client dans une iframe, sous son propre nom et sur son propre domaine. Le visiteur
 * paie sur une page qu'il croit être celle du site encadrant. Rien ne casse, rien n'alerte — c'est
 * exactement pourquoi personne ne le remarque.
 *
 * > **Une page qu'on peut encadrer sans le dire est une page qu'on peut porter au nom d'un autre.**
 *
 * **Fermé par défaut, et c'est le sens sûr de l'erreur.** Une intégration qui ne s'affiche pas se
 * signale à la première tentative et se corrige en déclarant un domaine. Une boutique encadrable par
 * tout le monde ne se signale jamais.
 */
final class IntegrationIframeTest extends BoutiqueApiTestCase
{
    /**
     * **Une boutique sans domaine déclaré n'apparaît pas dans la carte nginx.**
     *
     * C'est l'assertion qui porte la règle : la carte ne contient que ce qui a été ouvert
     * explicitement, et le `default` ferme tout le reste.
     */
    public function testUneVitrineSansDomaineNEstPasEncadrable(): void
    {
        $carte = $this->carteNginx();

        self::assertStringContainsString('default', $carte);
        self::assertStringContainsString("\"'none'\"", $carte, 'Le défaut de la carte doit interdire tout encadrement.');
        self::assertStringNotContainsString('/b/', $carte, 'Aucune vitrine n a declare de domaine : la carte ne doit ouvrir personne.');
    }

    /**
     * **Une boutique qui a déclaré ses domaines apparaît, et elle seule.**
     *
     * Le versant qui empêche la règle de tout fermer : sans lui, une commande qui ne rendrait jamais
     * de ligne passerait le test précédent.
     */
    public function testUneVitrineQuiADeclareSesDomainesApparaitDansLaCarte(): void
    {
        $vitrine = $this->vitrineDeA();
        $vitrine->setSlug('piscine-des-tests');
        $vitrine->setDomainesIntegration(['https://mairie-test.fr', 'https://www.mairie-test.fr']);
        $this->em()->flush();

        $carte = $this->carteNginx();

        self::assertStringContainsString('~^/b/piscine\-des\-tests(/|$)', $carte);
        self::assertStringContainsString('"https://mairie-test.fr https://www.mairie-test.fr"', $carte);
        // Le slug est echappe pour nginx : sans quoi un tiret ou un point dans un slug ferait
        // correspondre la regle a une autre boutique.
        self::assertStringContainsString("\"'none'\"", $carte, 'Le defaut ferme reste en place.');
    }

    /**
     * **Une origine mal écrite est refusée à la saisie.**
     *
     * Le navigateur compare l'origine caractère par caractère : `exemple.fr` sans schéma ne
     * correspond à rien. L'iframe reste blanche et l'exploitant conclut que la fonctionnalité ne
     * marche pas — une panne sans message, à six semaines de la saisie.
     */
    public function testUneOrigineSansSchemaEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('PATCH', '/api/boutique/vitrines/' . $this->vitrineDeA()->getId(), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['domainesIntegration' => ['exemple.fr']],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **`http://` est refusé comme `https://` est accepté.**
     *
     * Autoriser un encadrement en clair rouvrirait au premier intermédiaire du réseau ce que la
     * directive était censée fermer.
     */
    public function testUneOrigineEnClairEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('PATCH', '/api/boutique/vitrines/' . $this->vitrineDeA()->getId(), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['domainesIntegration' => ['http://exemple.fr']],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Une barre finale collée d'un copier-coller ne casse pas l'autorisation.**
     *
     * `https://exemple.fr/` n'est pas une origine : le navigateur ne la reconnaîtrait pas, et
     * l'exploitant chercherait la panne ailleurs. On normalise à l'entrée plutôt que d'expliquer.
     */
    public function testUneBarreFinaleEstRetiree(): void
    {
        $vitrine = $this->vitrineDeA();
        $vitrine->setDomainesIntegration(['https://exemple.fr/', '  https://autre.fr  ', '']);

        self::assertSame(['https://exemple.fr', 'https://autre.fr'], $vitrine->getDomainesIntegration());
    }

    // --- outillage ------------------------------------------------------------------------------

    private function carteNginx(): string
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $testeur = new CommandTester(new BuildFrameAncestorsMap($em));
        $testeur->execute([]);

        return $testeur->getDisplay();
    }

    private function vitrineDeA(): Vitrine
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $vitrine = $this->em()->getRepository(Vitrine::class)->findOneBy(['etablissement' => $etablissement]);
        self::assertInstanceOf(Vitrine::class, $vitrine, 'Vitrine de l etablissement A introuvable.');

        return $vitrine;
    }
}
