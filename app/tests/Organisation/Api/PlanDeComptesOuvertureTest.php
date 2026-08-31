<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\NatureOperation;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Service\AccountingChartSeeder;
use App\Organisation\Entity\Etablissement;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNE STRUCTURE NEUVE DOIT POUVOIR GÉNÉRER SES ÉCRITURES, PAS SEULEMENT AVOIR UN PROFIL.
 *
 * J'avais annoncé « la mise en service est débloquée » après avoir posé le profil exploitant et les
 * taux de TVA. C'était vrai du verrou levé et faux de la chaîne : sur un établissement réellement
 * créé, il n'existait AUCUN compte comptable et AUCUN journal, et rien dans l'application ne
 * permettait d'en saisir — seuls les jeux d'essai en posaient.
 *
 * L'écran de comptabilité disait « aucun profil d'exploitant ». Le profil une fois posé, il aurait
 * dit « aucun journal », puis « aucune période ». J'avais mesuré ce que j'avais réparé et conclu sur
 * ce que je n'avais pas mesuré.
 *
 * ⚠ CE TEST N'ÉPROUVE PAS MA LISTE, IL ÉPROUVE LE CONTRAT DU MOTEUR.
 *
 * Compter dix comptes et six journaux vérifierait que le seeder fait ce que le seeder dit. Ce qui
 * compte est ailleurs : que `CompteLookupService` RÉSOLVE les préfixes que les régimes lui
 * demandent. Le jour où quelqu'un renomme un numéro dans la liste, c'est ici que ça doit tomber.
 */
final class PlanDeComptesOuvertureTest extends SecuriteApiTestCase
{
    /**
     * LES SIX RÉSOLUTIONS QUE LE MOTEUR FAIT À CHAQUE ÉCRITURE.
     */
    public function testLeMoteurResoutSesComptesSurUneStructureNeuve(): void
    {
        $profil = $this->ouvrirEtRecupererLeProfil('Club plan de comptes — privé', '5710');
        $lookup = $this->lookup();

        // ⚠ ON ASSERTE LE NUMÉRO EXACT, PAS « ÇA COMMENCE PAR ».
        //
        // Écrit d'abord avec `assertStringStartsWith`, ce test restait VERT quand je retirais
        // `511000` de la liste : `511200` (chèques à encaisser) commence aussi par `511`, et
        // `compteParPrefixe()` prend le premier venu dans l'ordre croissant. Le moteur aurait donc
        // débité les chèques pour tout encaissement, sans rien signaler.
        //
        // C'est le préfixe qui est ambigu, pas le test — et c'est justement ce qu'il doit épingler.
        $attendus = [
            '411' => '411000',
            '4457' => '445710',
            '487' => '487000',
            '511' => '511000',
            '512' => '512000',
            '706' => '706000',
        ];

        foreach ($attendus as $prefixe => $numero) {
            self::assertSame(
                $numero,
                $lookup->compteParPrefixe($profil, (string) $prefixe)->getNumero(),
                sprintf('le préfixe « %s » doit désigner %s, et lui seul', $prefixe, $numero),
            );
        }
    }

    /**
     * LES CINQ NATURES D'OPÉRATION TROUVENT LEUR JOURNAL.
     */
    public function testChaqueNatureDOperationTrouveSonJournal(): void
    {
        $profil = $this->ouvrirEtRecupererLeProfil('Club journaux — privé', '5710');
        $lookup = $this->lookup();

        $codes = [];
        foreach (NatureOperation::cases() as $nature) {
            $codes[] = $lookup->journal($profil, match ($nature) {
                NatureOperation::Ventes => 'VTE',
                NatureOperation::Encaissements => 'ENC',
                NatureOperation::Regie => 'REG',
                NatureOperation::PcaOd => 'PCA',
                NatureOperation::Extourne => 'EXT',
            })->getCode();
        }
        sort($codes);

        self::assertSame(['ENC', 'EXT', 'PCA', 'REG', 'VTE'], $codes);
    }

    /**
     * REPOSER LE PLAN NE DOIT RIEN DUPLIQUER.
     *
     * La commande de reprise rappelle le seeder sur des profils déjà en service, autant de fois
     * qu'on la lance. Sans idempotence, chaque passage doublerait le plan de comptes — et
     * `compteParPrefixe()` désignerait alors l'un des doublons au hasard de l'ordre d'insertion.
     *
     * ⚠ Le témoin est la première assertion : si le seeder n'avait rien posé du tout, la seconde
     * serait vraie sans rien prouver.
     */
    public function testReposerLePlanNeDupliqueRien(): void
    {
        $profil = $this->ouvrirEtRecupererLeProfil('Club idempotence — privé', '5710');
        $em = $this->em();

        $avantComptes = \count($em->getRepository(CompteComptable::class)->findBy(['profilExploitant' => $profil]));
        $avantJournaux = \count($em->getRepository(Journal::class)->findBy(['profilExploitant' => $profil]));

        self::assertGreaterThan(0, $avantComptes, 'témoin : l’ouverture a bien posé un plan de comptes');
        self::assertGreaterThan(0, $avantJournaux, 'témoin : l’ouverture a bien posé des journaux');

        /** @var AccountingChartSeeder $seeder */
        $seeder = static::getContainer()->get(AccountingChartSeeder::class);
        $pose = $seeder->poser($profil);
        $em->flush();

        self::assertSame(['journaux' => 0, 'comptes' => 0], $pose, 'un second passage ne crée rien');
        self::assertCount($avantComptes, $em->getRepository(CompteComptable::class)->findBy(['profilExploitant' => $profil]));
        self::assertCount($avantJournaux, $em->getRepository(Journal::class)->findBy(['profilExploitant' => $profil]));
    }

    /**
     * UNE COLLECTIVITÉ LIT LES MÊMES NUMÉROS SOUS D'AUTRES NOMS.
     *
     * Les numéros ne changent pas — le moteur les résout par préfixe, et en inventer d'autres
     * casserait la résolution sans rien apporter. Seuls les libellés suivent l'usage public.
     */
    public function testUneCollectiviteVoitLesLibellesPublics(): void
    {
        $profil = $this->ouvrirEtRecupererLeProfil('Régie plan de comptes', '4120');

        $compte = $this->lookup()->compteParPrefixe($profil, '411');

        self::assertSame('411000', $compte->getNumero(), 'le numéro est celui que le moteur cherche');
        self::assertSame('Redevables', $compte->getLibelle(), 'une collectivité n’a pas de « clients »');
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    private function ouvrirEtRecupererLeProfil(string $nom, string $formeJuridique): ProfilExploitant
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/organisation/structures', $entete + [
            'json' => [
                // ⚠ `nomCommercial`, PAS `nom` : le service lit celui-la et retombe sur la
                // raison sociale s'il est absent. Ecrit d'abord avec `nom`, ce test passait quand
                // meme -- `strtoupper` ne touche pas les accents et la collation du schema est
                // insensible a la casse, si bien que la recherche retrouvait la denomination.
                // Une denomination franchement distincte rend la confusion impossible.
                'nomCommercial' => $nom,
                'denomination' => 'SAS ' . strtoupper(substr($nom, 0, 10)),
                'siret' => '81240390500019',
                'formeJuridique' => $formeJuridique,
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

    private function lookup(): CompteLookupService
    {
        /** @var CompteLookupService $lookup */
        $lookup = static::getContainer()->get(CompteLookupService::class);

        return $lookup;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
