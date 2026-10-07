<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\LegalVatRateFixtureTrait;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNE STRUCTURE RECOIT LES TAUX DE SON PAYS, ET UN PARAMETRAGE QUI LUI PERMET DE FACTURER.
 *
 * ── LE DEFAUT MESURE LE 14/09/2026 ──────────────────────────────────────────────────────────────
 *
 * `StructureOnboarding` posait `TAUX_TVA_FRANCE` — 20 / 10 / 5,5 / 2,1, libelles francais — a toute
 * structure, quel que soit son pays, et `BackfillAccountingProfilesCommand` en portait une seconde
 * copie. Rien ne pouvait rougir : les jeux d'essai sont francais et `Etablissement::pays` vaut `FR`
 * par defaut, si bien que le cas qui demasque etait ABSENT de toute la base de test.
 *
 * ⚠ C'EST LA DEUXIEME FOIS SUR CE PRODUIT. Une regle de TVA verrouillee sur un pays avait deja ete
 * trouvee — 2 233 tests verts sur un produit invendable hors de France. Le temoin doit donc etre un
 * bareme REELLEMENT different, pas un second pays qui appliquerait les memes chiffres.
 *
 * ── POURQUOI LES DOM ET PAS LA BELGIQUE ─────────────────────────────────────────────────────────
 *
 * La Belgique serait le cas naturel. Elle ne passe pas : `creerProfilComptable()` derive le SIREN
 * du SIRET et abandonne s'il ne fait pas neuf chiffres, et un exploitant belge n'a pas de SIREN —
 * c'est un reste ouvert de T8, pas un oubli d'ici. Les DOM donnent le meme temoin sans ce detour :
 * meme pays, meme SIREN valide, et un bareme qui n'a AUCUNE valeur commune avec la metropole hormis
 * un 2,10 qui n'y designe meme pas la meme categorie.
 *
 * ── ET LE PARAMETRAGE, QUI N'EXISTAIT PAS DU TOUT ───────────────────────────────────────────────
 *
 * `new ParametreFacturationEtablissement` ne figurait que dans les jeux d'essai. Une structure
 * ouverte pour de vrai n'avait donc aucune ligne de parametres — ni taux par defaut, ni compte de
 * produit — et ne pouvait rien facturer. Le symptome n'arrivait qu'a la premiere facture, par une
 * tache de nuit, des semaines plus tard.
 */
final class VatRateSeedingOnStructureOpeningTest extends SecuriteApiTestCase
{
    use LegalVatRateFixtureTrait;

    /**
     * LE TEMOIN : UN ETABLISSEMENT ULTRAMARIN NE FACTURE PAS A 20 %.
     *
     * ⚠ L'ASSERTION QUI COMPTE EST CELLE QUI EXCLUT LE 20 %. Se contenter d'affirmer la presence du
     * 8,50 laisserait passer un semeur qui poserait LES DEUX baremes — une liste qui contient le bon
     * taux et six autres a l'air complete, et l'erreur ne se verrait qu'au moment de choisir.
     */
    public function testUnEtablissementUltramarinRecoitSonBaremeEtPasCeluiDeLaMetropole(): void
    {
        $em = $this->em();
        $this->seedFranceMetropolitanVatRates($em);
        $this->seedFranceOverseasVatRates($em);

        $profil = $this->ouvrir('Club Guadeloupe — DOM', ['pays' => 'FR', 'territoireFiscal' => 'DOM']);

        $valeurs = $this->tauxDe($profil);
        sort($valeurs);

        self::assertSame(
            ['0.00', '1.05', '2.10', '8.50'],
            $valeurs,
            'le bareme des DOM (CGI art. 296), plus le hors-champ — et rien de la metropole',
        );
        self::assertNotContains('20.00', $valeurs, 'le taux normal metropolitain n\'existe pas aux Antilles');
        self::assertNotContains('5.50', $valeurs, 'le taux reduit metropolitain non plus');
    }

    /**
     * LE PARAMETRAGE DE FACTURATION EXISTE, ET IL EST RENSEIGNE.
     *
     * Les trois assertions repondent aux trois refus mesures sur la preproduction : pas de ligne de
     * parametres du tout, pas de taux par defaut, pas de compte de produit.
     */
    public function testLeParametrageDeFacturationEstPoseAvecLeTauxNormalDuPays(): void
    {
        $em = $this->em();
        $this->seedFranceMetropolitanVatRates($em);

        $profil = $this->ouvrir('Club parametrage — metropole', []);

        $parametre = $em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil]);

        self::assertInstanceOf(
            ParametreFacturationEtablissement::class,
            $parametre,
            'sans ligne de parametres, chaque lecture retombe sur `?->` et la premiere facture est refusee',
        );

        self::assertSame(
            '20.00',
            $parametre->getTauxTvaDefaut()?->getTaux(),
            'le defaut est le taux NORMAL du pays, tire du referentiel legal — jamais un taux choisi au hasard',
        );

        // ⚠ CE QUE CETTE ASSERTION EPROUVE VRAIMENT, C'EST UN ORDRE DE FLUSH. Le compte se resout
        // par une REQUETE : si `BillingSettingsSeeder` est appele avant que le plan de comptes soit
        // flushe, il ne trouve rien et laisse le defaut a `null` — sans lever. Retirer le `flush()`
        // de `StructureOnboarding` ne casse aucun autre test ; il casse celui-ci.
        self::assertSame(
            '706000',
            $parametre->getCompteProduitDefaut()?->getNumero(),
            'le compte de produit par defaut doit etre resolu APRES que le plan de comptes est ecrit',
        );
    }

    /**
     * UN PAYS MAL FORME EST REFUSE, PAS ENREGISTRE.
     *
     * L'entite porte un `Assert\Regex`, mais elle est persistee a la main : aucun validateur ne
     * tourne sur ce chemin. Sans refus explicite, « France » serait stocke tel quel, le referentiel
     * ne rendrait rien, et la structure s'ouvrirait sans le moindre taux — en 201.
     */
    public function testUnPaysMalFormeEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => [
                'nomCommercial' => 'Club pays invalide',
                'denomination' => 'CLUB PAYS SAS',
                'siret' => '81240390500019',
                'formeJuridique' => '5710',
                'pays' => 'France',
            ],
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent(false));
        self::assertStringContainsString('deux lettres', (string) $client->getResponse()->getContent(false));
    }

    /**
     * @param array<string, mixed> $enPlus
     */
    private function ouvrir(string $nom, array $enPlus): ProfilExploitant
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => $enPlus + [
                'nomCommercial' => $nom,
                'denomination' => 'SAS ' . strtoupper(substr($nom, 0, 10)),
                'siret' => '81240390500019',
                'formeJuridique' => '5710',
            ],
        ]);

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent(false));

        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement]);
        self::assertInstanceOf(ProfilExploitant::class, $profil);

        return $profil;
    }

    /** @return list<string> */
    private function tauxDe(ProfilExploitant $profil): array
    {
        return array_map(
            static fn (TauxTva $t): string => $t->getTaux(),
            $this->em()->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil]),
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
