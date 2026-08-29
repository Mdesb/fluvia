<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OUVRIR UNE STRUCTURE DOIT PRODUIRE UN SITE QUI PEUT VENDRE.
 *
 * Ce parcours n'avait AUCUN test. C'est pour cela que le trou s'est vu à l'écran et pas ici :
 * « Avant de pouvoir vendre » demande un taux de TVA, un taux exige un profil exploitant, et aucun
 * écran ne permettait d'en créer un. Mesuré sur la préproduction avant d'écrire ce test :
 *
 *     POST /api/taux_tvas, taux en nombre  -> 400  « must be "string", "integer" given »
 *     POST /api/taux_tvas, taux en chaîne  -> 422  « profilExploitant: This value should not be null »
 *
 * Deux murs l'un derrière l'autre, et le second sans porte. La mise en service s'arrêtait là pour
 * tout le monde.
 */
final class OuvertureStructureTest extends SecuriteApiTestCase
{
    public function testUneStructureNeuveSaitDejaFacturer(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => [
                'nomCommercial' => 'Club de test — société privée',
                'denomination' => 'CLUB TEST SAS',
                'siret' => '81240390500019',
                'formeJuridique' => '5710',
            ],
        ]);

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent(false));

        $profil = $this->profilDe('Club de test — société privée');

        self::assertSame('812403905', $profil->getSiren(), 'le SIREN est la racine du SIRET relevé au greffe');

        // ⚠ CE N'EST PAS UNE ÉTIQUETTE. `SelecteurPaiementEnLigne` choisit le prestataire de paiement
        // sur ce type : le défaut `RegieDirecte` aurait envoyé une société privée encaisser par
        // PayFiP, le portail de l'État.
        self::assertSame(TypeExploitant::GroupePrive, $profil->getType());
        self::assertSame(ReferentielComptable::Pcg, $profil->getReferentielComptable());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $taux = $em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil]);

        $valeurs = array_map(static fn (TauxTva $t): string => $t->getTaux(), $taux);
        sort($valeurs);

        self::assertSame(['0.00', '2.10', '5.50', '10.00', '20.00'], $valeurs, 'les cinq taux légaux français');
    }

    /**
     * UNE COLLECTIVITÉ N'EST PAS UNE SOCIÉTÉ.
     *
     * Sans ce second cas, poser `GroupePrive` en dur passerait le premier test — et une régie
     * directe se retrouverait avec le plan comptable général et un prestataire de paiement privé,
     * là où la loi lui impose PayFiP. Le type se déduit de la nature juridique relevée au greffe :
     * les codes INSEE commençant par 4 désignent les personnes morales de droit public.
     */
    public function testUneCollectiviteResteEnRegieDirecte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => [
                'nomCommercial' => 'Piscine municipale de test',
                'denomination' => 'COMMUNE DE TEST',
                'siret' => '21240390500019',
                'formeJuridique' => '4210',
            ],
        ]);

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent(false));

        $profil = $this->profilDe('Piscine municipale de test');

        self::assertSame(TypeExploitant::RegieDirecte, $profil->getType());
        self::assertSame(ReferentielComptable::M57, $profil->getReferentielComptable());
    }

    private function profilDe(string $nomEtablissement): ProfilExploitant
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement, 'la structure n’a pas été créée');

        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement]);
        self::assertInstanceOf(
            ProfilExploitant::class,
            $profil,
            'aucun profil exploitant : la structure ne peut pas avoir de taux de TVA, donc pas vendre',
        );

        return $profil;
    }
}
