<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * « MOT DE PASSE OUBLIE » — la seule route du produit appelee SANS etre authentifie.
 *
 * ⚠ ELLE ANNONCAIT UN ENVOI QUI N'A JAMAIS LIEU, ET AUCUN ECRAN NE L'APPELAIT.
 *
 * Le message « Si ce compte existe, un e-mail a ete envoye » est une formule anti-enumeration :
 * elle repond la meme chose que le compte existe ou non, pour qu'on ne puisse pas decouvrir les
 * adresses inscrites en les essayant. Cette propriete est juste et ces tests la protegent.
 *
 * Mais aucun e-mail ne partait — `MAILER_DSN=null://null` avale tout — et `Login.jsx` n'offrait meme
 * pas ce parcours : 108 lignes, zero occurrence d'« oubli ». Quelqu'un qui perdait son mot de passe
 * n'avait rien a cliquer, et rien ne le lui disait.
 */
final class DemandeReinitialisationTest extends SecuriteApiTestCase
{
    /**
     * ⚠ LA REPONSE PORTE LE FAIT, PARCE QUE CET ECRAN NE PEUT PAS LE LIRE AILLEURS.
     *
     * Partout ailleurs, « un expediteur est-il configure » se lit dans `/me`, qu'aucun ecran ne peut
     * ne pas avoir recu. Ici personne n'est connecte : `/me` n'a rien rendu. La reponse de la
     * demande est donc la seule source honnete — et l'ecrire en constante cote ecran reproduirait
     * exactement le defaut qu'on corrige.
     */
    public function testLaReponsePorteSiUnExpediteurEstConfigure(): void
    {
        $client = static::createClient();

        $client->request('POST', '/mot-de-passe/oublie', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['email' => SocleFixtures::ADMIN_EMAIL],
        ]);

        self::assertResponseStatusCodeSame(202);
        $corps = $client->getResponse()->toArray();

        self::assertArrayHasKey('envoiCourrielBranche', $corps, 'sans ce champ, l ecran de connexion devrait deviner');
        self::assertIsBool($corps['envoiCourrielBranche']);
        self::assertNotEmpty($corps['message']);
    }

    /**
     * ⚠ L'ANTI-ENUMERATION TIENT, ET C'EST CE QUE CE TEST PROTEGE VRAIMENT.
     *
     * Dire la verite sur l'expediteur ne doit rien reveler sur le COMPTE. « Un expediteur est-il
     * configure » est un fait global de l'instance : il ne depend pas de l'adresse saisie. Les deux
     * reponses doivent donc rester indiscernables, octet pour octet.
     *
     * Si un jour quelqu'un ajoute « compte introuvable » pour etre serviable, ce test tombe — et
     * c'est exactement le moment ou il faut qu'il tombe.
     */
    public function testUnCompteInconnuEtUnCompteConnuRendentLaMemeChose(): void
    {
        $client = static::createClient();

        $client->request('POST', '/mot-de-passe/oublie', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['email' => SocleFixtures::ADMIN_EMAIL],
        ]);
        self::assertResponseStatusCodeSame(202);
        $connu = $client->getResponse()->toArray();

        $client->request('POST', '/mot-de-passe/oublie', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['email' => 'personne-de-ce-nom@exemple.invalid'],
        ]);
        self::assertResponseStatusCodeSame(202);
        $inconnu = $client->getResponse()->toArray();

        self::assertSame($connu, $inconnu, 'les deux reponses doivent etre indiscernables : sinon on enumere les comptes');
    }

    /**
     * Une adresse vide ne doit pas se distinguer non plus — ni par le code, ni par le corps.
     */
    public function testUneAdresseVideRendLaMemeReponse(): void
    {
        $client = static::createClient();

        $client->request('POST', '/mot-de-passe/oublie', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['email' => SocleFixtures::ADMIN_EMAIL],
        ]);
        $reference = $client->getResponse()->toArray();

        $client->request('POST', '/mot-de-passe/oublie', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [],
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($reference, $client->getResponse()->toArray());
    }
}
