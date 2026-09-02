<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\State\ConfigCreancierSepaProcessor;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `ConfigCreancierSepa` (plan-sepa.md §2/§6) : IBAN créancier masqué en lecture (4 derniers seulement),
 * variante dérivée par défaut du `ProfilExploitant.type` de l'établissement (surchargeable), les 2
 * variantes de démonstration (fixtures) reflètent bien le régime attendu.
 */
final class ConfigCreancierSepaTest extends SepaApiTestCase
{
    public function testConfigRegieSurEtablissementARegieDirecte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $configId = (string) $this->entite(ConfigCreancierSepa::class, ['etablissement' => $etabA])->getId();
        $client->request('GET', '/api/config_creancier_sepas/' . $configId, $entete);
        self::assertResponseIsSuccessful();
        $config = $client->getResponse()->toArray();
        self::assertSame('regie', $config['variante']);
        self::assertSame('COLLECTIVITE DEMO / VILLE-MODELE', $config['collectiviteNom']);
        self::assertSame('REGIE PISCINE A', $config['ultimateCreancierNom']);
    }

    public function testConfigPriveeSurEtablissementBSansProfilExploitant(): void
    {
        [$client, $entete] = $this->adminSurB();

        $etabB = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_B_NOM]);
        $configId = (string) $this->entite(ConfigCreancierSepa::class, ['etablissement' => $etabB])->getId();
        $client->request('GET', '/api/config_creancier_sepas/' . $configId, $entete);
        self::assertResponseIsSuccessful();
        $config = $client->getResponse()->toArray();
        self::assertSame('prive', $config['variante']);
        self::assertNull($config['collectiviteNom'] ?? null);
    }

    public function testIbanCreancierJamaisExposeSeuls4DerniersLisibles(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/config_creancier_sepas', $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString('creancierIbanToken', $corps);
        self::assertStringNotContainsString('creancierIbanChiffre', $corps);
        self::assertStringNotContainsString('FR76', $corps, 'Aucun IBAN en clair dans la réponse API.');
        self::assertStringContainsString('0097', $corps, '4 derniers chiffres lisibles.');
    }

    public function testIbanCreancierChiffreEstStockeEtReversibleMaisJamaisRenvoyeEnApi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $configEntite = $this->entite(ConfigCreancierSepa::class, ['etablissement' => $etabA]);
        self::assertNotNull($configEntite->getCreancierIbanChiffre());

        /** @var \App\Sepa\Service\ChiffreurIbanInterface $chiffreur */
        $chiffreur = static::getContainer()->get(\App\Sepa\Service\ChiffreurIbanInterface::class);
        self::assertSame('FR7600000000000000000000097', $chiffreur->dechiffrer((string) $configEntite->getCreancierIbanChiffre()));

        $client->request('GET', '/api/config_creancier_sepas/' . $configEntite->getId(), $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString('creancierIbanChiffre', $corps);
        self::assertStringNotContainsString('FR7600000000000000000000097', $corps);
    }

    public function testVarianteDeriveeParDefautDuProfilExploitantQuandNonFournie(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $region = $em->getRepository(Region::class)->findOneBy([]);
        self::assertNotNull($region);

        // Établissement C, rattaché au ProfilExploitant régie existant (couvre A) : dérivation REGIE.
        $etabC = (new Etablissement())->setNom('Etab Test Derivation Regie')->setRegion($region)->setActif(true);
        $em->persist($etabC);
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy([]);
        self::assertNotNull($profil);
        $profil->addEtablissementRattache($etabC);
        $em->flush();

        $configRegie = new ConfigCreancierSepa();
        $configRegie->setEtablissement($etabC)
            ->setIcs('FR00ZZZ999999')
            ->setCreancierNom('TEST DERIVATION')
            ->setCreancierBic('BDFEFRPPCCT');
        $processor = static::getContainer()->get(ConfigCreancierSepaProcessor::class);
        $resultat = $processor->process($configRegie, self::operationBidon());
        self::assertSame(VarianteCreancierSepa::Regie, $resultat->getVariante());

        // Établissement D, aucun ProfilExploitant : dérivation PRIVE (défaut sûr).
        $etabD = (new Etablissement())->setNom('Etab Test Derivation Prive')->setRegion($region)->setActif(true);
        $em->persist($etabD);
        $em->flush();

        $configPrive = new ConfigCreancierSepa();
        $configPrive->setEtablissement($etabD)
            ->setIcs('FR00ZZZ888888')
            ->setCreancierNom('TEST DERIVATION D')
            ->setCreancierBic('CMCIFRPPXXX');
        $resultat2 = $processor->process($configPrive, self::operationBidon());
        self::assertSame(VarianteCreancierSepa::Prive, $resultat2->getVariante());
    }

    /**
     * UN SECOND POST SUR LE MEME ETABLISSEMENT REND UN CONFLIT LISIBLE, PAS UN 500.
     *
     * La table porte `uniq_config_creancier_etablissement`. Sans controle dans le processeur, la
     * demande remonte jusqu'au flush et la contrainte la refuse en `UniqueConstraintViolationException`
     * — qu'API Platform rend en **500 « Internal Server Error »**, un corps sans un mot sur la cause.
     *
     * Mesure du 02/09 : j'ai lu ce 500 comme une panne du serveur et je suis alle chercher dans les
     * journaux du conteneur pour apprendre ce que la reponse aurait pu me dire. Un 500 annonce « le
     * logiciel est casse » ; ici rien n'est casse, la demande est refusee pour une raison que
     * l'appelant peut corriger seul — il voulait un PATCH.
     */
    public function testUnSecondPostSurUnEtablissementDejaUneConfigRendUnConflit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);

        // ⚠ TEMOIN DE LA PRECONDITION. Sans lui, ce test passerait aussi bien si l'etablissement A
        // n'avait AUCUNE configuration : le POST reussirait, on lirait 201, et l'assertion sur 409
        // serait la seule a tomber — en accusant le processeur au lieu de la fixture.
        self::assertNotNull(
            $this->entite(ConfigCreancierSepa::class, ['etablissement' => $etabA]),
            "L'etablissement A n'a pas de configuration : ce test ne mesure pas un doublon.",
        );

        $client->request('POST', '/api/config_creancier_sepas', $entete + ['json' => [
            'etablissement' => '/api/etablissements/' . $etabA->getId(),
            'ics' => 'FR00ZZZ777777',
            'creancierNom' => 'DOUBLON',
            'creancierBic' => 'BDFEFRPPCCT',
        ]]);

        self::assertResponseStatusCodeSame(409);

        $corps = $client->getResponse()->toArray(false);
        $detail = (string) ($corps['detail'] ?? $corps['description'] ?? '');

        // Le message doit fermer la mauvaise piste et ouvrir la bonne : nommer la configuration qui
        // existe, et dire par quel geste la modifier.
        self::assertStringContainsString('deja une configuration', $detail);
        self::assertStringContainsString('PATCH', $detail);
    }

    private static function operationBidon(): \ApiPlatform\Metadata\Post
    {
        return new \ApiPlatform\Metadata\Post();
    }
}
