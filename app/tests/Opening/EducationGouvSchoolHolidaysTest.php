<?php

declare(strict_types=1);

namespace App\Tests\Opening;

use App\Opening\Adapter\EducationGouvSchoolHolidays;
use App\Opening\Enum\SchoolZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LA DÉPENDANCE QU'ON NE PEUT PAS PROVISIONNER SOI-MÊME TOMBERA UN DIMANCHE.
 *
 * Le calendrier scolaire vient de `data.education.gouv.fr`. Sans clé, sans contrat, sans facture —
 * et donc sans engagement de disponibilité. Ce qui compte n'est pas qu'il réponde : c'est ce que
 * fait le produit le jour où il ne répond pas.
 *
 * ── POURQUOI LE CLIENT EST SIMULÉ ───────────────────────────────────────────────────────────────
 *
 * Un test qui appellerait le vrai service serait rouge le jour d'une panne du ministère et vert le
 * reste du temps : il mesurerait la météo, pas le code. Chaque scénario ci-dessous est une panne
 * qu'on ne peut pas provoquer à la demande.
 *
 * ⚠ LE CACHE DE L'ÉCHEC EST LE PIÈGE PRINCIPAL. Mettre en cache une réponse et mettre en cache une
 * ABSENCE de réponse sont deux gestes opposés : le second fait durer trente jours chez nous une
 * panne de trente secondes chez eux, et l'écran dirait « indisponible » un mois après le retour du
 * service. Le code s'en garde ; rien ne l'en empêchait demain.
 *
 * ── CE QUI N'EST PAS VÉRIFIÉ ICI, ET POURQUOI ───────────────────────────────────────────────────
 *
 * Cette source n'alimente QUE les propositions d'écran (`OpeningCalendarHints`). `OpeningCalendar`,
 * qui décide si une porte s'ouvre, ne la consulte jamais : une panne du ministère ne peut donc ni
 * ouvrir ni fermer un accès. Ce paragraphe est ici pour celui qui brancherait un jour cette source
 * sur le contrôle d'accès — il doit lire d'abord ce qu'il ferait entrer dans la décision.
 */
final class EducationGouvSchoolHolidaysTest extends TestCase
{
    /**
     * Une panne se DIT. Rendre une liste vide sans le signaler afficherait « aucune vacance
     * scolaire cette année » — une absence déguisée en information, qui ne se découvre jamais
     * puisqu'elle ne provoque ni erreur ni ralentissement.
     */
    public function testLeMinistereMuetRendUneAbsenceQuiSeDit(): void
    {
        $appels = 0;
        $adaptateur = new EducationGouvSchoolHolidays(
            new MockHttpClient(function () use (&$appels): MockResponse {
                ++$appels;

                throw new TransportException('Connexion impossible.');
            }),
            new ArrayAdapter(),
        );

        $resultat = $adaptateur->periods(SchoolZone::B, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertFalse($resultat['available'], 'Une panne doit se dire, pas se taire.');
        self::assertSame([], $resultat['periods']);
        self::assertNotSame('', trim($resultat['reason'] ?? ''), 'Sans motif, l’écran ne peut rien écrire d’autre que « aucune vacance ».');
        self::assertSame(1, $appels);
    }

    /**
     * ⚠ LE TEST QUI COMPTE LE PLUS DE CE FICHIER.
     *
     * La ligne à supprimer pour introduire le défaut est un `return` anticipé — exactement le genre
     * de simplification qui passe en revue sans qu'on y regarde. Le second appel DOIT repartir au
     * réseau.
     */
    public function testLEchecNEstJamaisMisEnCache(): void
    {
        $appels = 0;
        $adaptateur = new EducationGouvSchoolHolidays(
            new MockHttpClient(function () use (&$appels): MockResponse {
                ++$appels;

                throw new TransportException('Connexion impossible.');
            }),
            new ArrayAdapter(),
        );

        $du = new \DateTimeImmutable('2026-01-01');
        $au = new \DateTimeImmutable('2026-12-31');
        $adaptateur->periods(SchoolZone::B, $du, $au);
        $adaptateur->periods(SchoolZone::B, $du, $au);

        self::assertSame(2, $appels, 'Une absence de réponse a été mise en cache : la panne du ministère dure maintenant trente jours chez nous.');
    }

    /**
     * Une réponse VALIDE, elle, se met en cache : un arrêté par an ne se redemande pas à chaque
     * affichage d'agenda à un service public gratuit.
     */
    public function testUneReponseValideNEstDemandeeQuUneFois(): void
    {
        $appels = 0;
        $adaptateur = new EducationGouvSchoolHolidays(
            new MockHttpClient(function () use (&$appels): MockResponse {
                ++$appels;

                return new MockResponse(json_encode(['results' => [
                    ['description' => 'Vacances de la Toussaint', 'start_date' => '2026-10-17', 'end_date' => '2026-11-02'],
                ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
            }),
            new ArrayAdapter(),
        );

        $du = new \DateTimeImmutable('2026-01-01');
        $au = new \DateTimeImmutable('2026-12-31');
        $premier = $adaptateur->periods(SchoolZone::B, $du, $au);
        $second = $adaptateur->periods(SchoolZone::B, $du, $au);

        self::assertTrue($premier['available']);
        self::assertSame($premier, $second);
        self::assertSame(1, $appels);
    }

    /**
     * LE DÉFAUT VU À L'ÉCRAN LE 28/08 : « Vacances d'Été » sur deux lignes.
     *
     * Le jeu publie une ligne PAR ACADÉMIE. Pour les petites vacances les dates coïncident et un
     * regroupement suffisait ; pour l'été, non — les académies ne rentrent pas le même jour. Les
     * deux lignes doivent devenir une seule période, du plus tôt au plus tard : ce qu'un exploitant
     * lit, c'est « il y a des enfants en vacances quelque part dans la zone ».
     */
    public function testLesLignesAcademiquesNeFontQuUneSeulePeriode(): void
    {
        $adaptateur = $this->adaptateurRendant([
            ['description' => 'Vacances d’Été', 'start_date' => '2026-07-04', 'end_date' => '2026-09-01'],
            ['description' => 'Vacances d’Été', 'start_date' => '2026-07-04', 'end_date' => '2026-08-31'],
            ['description' => 'Vacances de la Toussaint', 'start_date' => '2026-10-17', 'end_date' => '2026-11-02'],
            ['description' => 'Vacances de la Toussaint', 'start_date' => '2026-10-17', 'end_date' => '2026-11-02'],
        ]);

        $periodes = $adaptateur->periods(SchoolZone::B, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'))['periods'];

        self::assertCount(2, $periodes, 'Huit lignes académiques empileraient huit bandes sur le calendrier.');
        self::assertSame('Vacances d’Été', $periodes[0]['label']);
        self::assertSame('2026-07-04', $periodes[0]['start']);
        self::assertSame('2026-09-01', $periodes[0]['end'], 'La fin la plus tardive est celle qui compte.');
    }

    /**
     * LA BORNE DE LA FUSION. Deux périodes homonymes séparées de douze mois sont deux vacances, pas
     * une. Fusionner sur le seul libellé écrirait « Vacances de Noël, du 19/12/2025 au 03/01/2027 »
     * — une bande d'un an en fond de calendrier.
     */
    public function testDeuxNoelsAUnAnDEcartRestentDeuxPeriodes(): void
    {
        $adaptateur = $this->adaptateurRendant([
            ['description' => 'Vacances de Noël', 'start_date' => '2025-12-19', 'end_date' => '2026-01-04'],
            ['description' => 'Vacances de Noël', 'start_date' => '2026-12-18', 'end_date' => '2027-01-03'],
        ]);

        $periodes = $adaptateur->periods(SchoolZone::B, new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2027-06-30'))['periods'];

        self::assertCount(2, $periodes);
        self::assertSame('2026-01-04', $periodes[0]['end']);
    }

    /**
     * Une réponse qui arrive mais ne ressemble à rien n'est pas une réponse. Le jeu de données peut
     * changer de forme sans prévenir : il est public et gratuit, personne ne nous doit un préavis.
     */
    public function testUneReponseInattendueEstTraiteeCommeUneIndisponibilite(): void
    {
        $adaptateur = new EducationGouvSchoolHolidays(
            new MockHttpClient(new MockResponse(
                json_encode(['total_count' => 12], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            )),
            new ArrayAdapter(),
        );

        $resultat = $adaptateur->periods(SchoolZone::A, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertFalse($resultat['available']);
        self::assertSame([], $resultat['periods']);
        self::assertNotSame('', trim($resultat['reason'] ?? ''));
    }

    /**
     * Les lignes incomplètes sont écartées SANS emporter les autres : une seule date manquante ne
     * doit pas effacer le calendrier de l'année.
     */
    public function testUneLigneIncompleteNEmportePasLesAutres(): void
    {
        $adaptateur = $this->adaptateurRendant([
            ['description' => 'Vacances d’Hiver', 'start_date' => null, 'end_date' => '2026-02-22'],
            ['description' => '', 'start_date' => '2026-04-11', 'end_date' => '2026-04-27'],
            ['description' => 'Vacances de Printemps', 'start_date' => '2026-04-11', 'end_date' => '2026-04-27'],
        ]);

        $periodes = $adaptateur->periods(SchoolZone::B, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'))['periods'];

        self::assertCount(1, $periodes);
        self::assertSame('Vacances de Printemps', $periodes[0]['label']);
    }

    /**
     * @param list<array<string, mixed>> $lignes
     */
    private function adaptateurRendant(array $lignes): EducationGouvSchoolHolidays
    {
        return new EducationGouvSchoolHolidays(
            new MockHttpClient(new MockResponse(
                json_encode(['results' => $lignes], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            )),
            new ArrayAdapter(),
        );
    }
}
