<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\BusinessTerm;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\Facture;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\LegalVatRateFixtureTrait;
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
    use LegalVatRateFixtureTrait;

    public function testUneStructureNeuveSaitDejaFacturer(): void
    {
        // ── LES TAUX NE VIENNENT PLUS D'UNE CONSTANTE, MAIS DU RÉFÉRENTIEL LÉGAL ────────────────
        //
        // ⚠ L'ATTENTE DE CE TEST N'A PAS BOUGÉ D'UN CHIFFRE, ET C'EST LE POINT. Les quatre taux
        // français du référentiel sont exactement ceux que posait l'ancienne constante
        // `TAUX_TVA_FRANCE` : la sortie est identique, seule la source a changé. Un test dont on
        // révise l'attente en même temps que le code ne prouve plus rien sur le remplacement.
        //
        // En production la table porte 62 taux sur 29 pays ; en test elle est vide, et
        // `SchemaDuHarnais` la tronque entre deux tests. Il faut donc la poser ici — sans quoi ce
        // test mesurerait un environnement que la production ne connaît pas.
        $this->seedFranceMetropolitanVatRates(
            static::getContainer()->get('doctrine')->getManager(),
        );

        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => [
                'nomCommercial' => 'Club de test — société privée',
                'denomination' => 'CLUB TEST SAS',
                'siret' => '81240390500019',
                'formeJuridique' => '5710',
                // L'annuaire renvoie l'adresse deja decoupee ; le formulaire d'ouverture
                // transmet l'ensemble du resultat tel quel.
                'rue' => '9 RUE DU COLONEL PIERRE AVIA',
                'complement' => '',
                'codePostal' => '75015',
                'ville' => 'PARIS',
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

        // ── L'ÉMETTEUR EST DÉJÀ RENSEIGNÉ, ET SES TAUX PORTENT LEUR CATÉGORIE EN 16931 ─────────────
        // Sans ces deux acquis, une structure neuve « sait vendre » mais ne sait pas ÉMETTRE : sa
        // facture partirait sans raison sociale, et son Factur-X serait refusé faute de catégorie
        // de TVA (BT-151). Les deux viennent de l'inscription, jamais d'une seconde saisie.
        self::assertSame('CLUB TEST SAS', $profil->getRaisonSociale(), 'la raison sociale vient de la denomination d\'inscription');
        self::assertSame('81240390500019', $profil->getSiret(), 'le SIRET complet est repris de l\'inscription');

        $categories = [];
        foreach ($taux as $t) {
            $categories[$t->getTaux()] = $t->getVatCategory();
        }
        self::assertSame(VatCategory::Standard, $categories['20.00'], 'un taux positif porte la catégorie EN 16931 « S » (BT-151)');
        self::assertSame(VatCategory::OutOfScope, $categories['0.00'], 'le hors-champ porte la catégorie « O », pas une exonération');

        // ── ET SON ADRESSE VENDEUR EST STRUCTURÉE, DONC SA FACTURE DEVIENT ÉMETTABLE ────────
        // L'inscription capture l'adresse déjà découpée par l'annuaire (rue / CP / ville) ; le pays
        // est celui de l'établissement. Ces quatre termes (BT-35/37/38/40) étaient le dernier
        // verrou : sans eux, le Factur-X d'une structure neuve partait en 422.
        self::assertSame(
            ['rue' => '9 RUE DU COLONEL PIERRE AVIA', 'complement' => '', 'cp' => '75015', 'ville' => 'PARIS', 'pays' => 'FR'],
            $profil->getAdresse(),
            'l\'adresse vendeur est reprise structurée de l\'inscription',
        );

        // Preuve par l'autorité, pas par relecture des champs : on demande à InvoiceReadiness ce
        // qui manque encore pour émettre, et aucun terme d'adresse vendeur ne doit y figurer.
        $manques = array_map(
            static fn (array $m): BusinessTerm => $m['terme'],
            (new InvoiceReadiness())->manques((new Facture())->setProfilExploitant($profil)),
        );
        // ⚠ Contre une liste vide, assertNotContains passerait sans rien regarder. On établit
        // d'abord qu'il y avait quelque chose à voir : la facture nue manque encore de son
        // acheteur et de ses lignes, donc le rapport n'est pas vide — l'absence des termes
        // vendeur est alors un vrai constat, pas un artefact de liste vide.
        self::assertNotEmpty($manques, 'le rapport doit signaler d\'autres manques, sinon le test ne prouve rien');
        foreach ([
            BusinessTerm::SellerStreet,
            BusinessTerm::SellerPostcode,
            BusinessTerm::SellerCity,
            BusinessTerm::SellerCountryCode,
        ] as $terme) {
            self::assertNotContains($terme, $manques, sprintf(
                '%s vient de l\'inscription : InvoiceReadiness ne doit plus le réclamer.',
                $terme->libelle(),
            ));
        }
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
