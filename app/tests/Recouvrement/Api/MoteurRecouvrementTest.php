<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\Acces\Enum\StatutProjectionDroit;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\RedevableRegistry;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Recouvrement\Entity\RepresentationSepa;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Tests\Recouvrement\RecouvrementApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de recouvrement partagé (`App\Recouvrement`, refactor extraction depuis `App\Sport`) : rejet
 * → incident → (selon politique) accès bloqué → régularisation → ré-accès. Chemin d'entrée du rejet ici
 * : `POST /sport/echeances/{id}/simuler-rejet` (point d'entrée propre à Sport, qui route vers le moteur
 * générique) — toute la suite (représentation, résolution, tableau de bord, politique) passe par les
 * routes génériques `/recouvrement/*`.
 */
final class MoteurRecouvrementTest extends RecouvrementApiTestCase
{
    public function testRejetCreeUnIncidentEtProgrammeUneRepresentationSansBloquerLaccesParDefaut(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->premiereEcheanceContratDemo();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04', 'libelleRetour' => 'Fonds insuffisants'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('representation', $incident['statut']);
        self::assertFalse($incident['accesBloque'], 'Politique par défaut = apres_representation_echouee : accès non bloqué avant échec.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'Une représentation est programmée automatiquement selon le calendrier.');
    }

    public function testEchecDeRepresentationBloqueLaccesEtBasculeEnRecouvrement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $representation = $this->representationDe($incidentId);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $incident = $em->getRepository(IncidentImpaye::class)->find($incidentId);
        self::assertSame('recouvrement', $incident->getStatut()->value);
        self::assertTrue($incident->isAccesBloque());
    }

    public function testRefusDesLe1erEchecSiPolitiqueLeParametre(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $politique = $em->getRepository(PolitiqueRecouvrement::class)->findOneBy([]);
        $politique->setMomentRefusAcces(MomentRefusAcces::Apres1erEchec);
        $em->flush();

        $echeance = $this->premiereEcheanceContratDemo();
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertTrue($incident['accesBloque'], 'Accès bloqué immédiatement (apres_1er_echec).');

        $em->clear();
        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'La représentation reste programmée même en refus immédiat.');
    }

    public function testResolutionUnClicRegulariseEtRestaureLaccesGeneriquement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);
        $representation = $this->representationDe($incidentId);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        // ⚠ CE TEST ATTENDAIT `app_1_clic` PARCE QUE C'ÉTAIT LA SEULE VALEUR POSSIBLE, PAS PARCE QUE
        //    C'ÉTAIT LA BONNE. L'opération était déclarée `input: false` et le handler posait le canal
        //    en dur : les trois autres valeurs de l'énumération — virement, caisse, autre — étaient
        //    inatteignables, et l'écran affichait éternellement la même. Le canal est désormais
        //    déclaré, et c'est cette déclaration qu'on vérifie.
        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + [
            'json' => ['canal' => 'virement', 'moyenPaiement' => 'virement', 'reference' => 'VIR-2026-42'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('virement', $incident['canalResolution'], 'Le canal déclaré doit être celui enregistré.');
        self::assertSame('virement', $incident['moyenResolution']);
        self::assertSame('VIR-2026-42', $incident['referenceResolution']);
        self::assertFalse($incident['accesBloque']);
    }

    /**
     * ⚠ LE TÉMOIN QUI MANQUAIT LE PLUS : le bouchon d'encaissement CB répondait « oui » sans condition.
     * « Réglé » ne débitait donc personne et ne pouvait jamais échouer — l'écran annonçait un
     * encaissement, la dette disparaissait, l'accès se rouvrait, et aucun euro n'avait bougé.
     */
    public function testLeCanalCarteRefuseTantQuAucunPrestataireNEstRaccorde(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + [
            'json' => ['canal' => 'app_1_clic', 'moyenPaiement' => 'cb', 'reference' => 'TPE-1'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('prestataire de paiement', $client->getResponse()->getContent(false));

        // Et le dossier n'a pas bougé : un refus qui laisserait l'incident résolu serait pire que rien.
        $client->request('GET', '/api/incident_impayes/' . $incidentId, $entete);
        self::assertNotSame('resolu', $client->getResponse()->toArray()['statut']);
    }

    /** Un canal absent ou inconnu est refusé — il ne retombe pas silencieusement sur une valeur. */
    public function testUnCanalManquantEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + [
            'json' => ['moyenPaiement' => 'virement'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testResolutionRefuseeSiIncidentDejaResolu(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $corps = $entete + ['json' => ['canal' => 'virement', 'moyenPaiement' => 'virement']];

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $corps);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $corps);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForcerReouvertureExigeUnMotif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => '']]);
        self::assertResponseStatusCodeSame(422, 'RG-SOCLE-07 : le motif est requis pour une réouverture forcée journalisée.');

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => 'Geste commercial exceptionnel']]);
        self::assertResponseIsSuccessful();
    }

    /**
     * ⚠ RÉGLER UN IMPAYÉ NE ROUVRE PAS LA PORTE SI UN AUTRE RESTE DÛ.
     *
     * Le drapeau `accesBloque` se pose PAR DOSSIER ; la porte se ferme PAR REDEVABLE. Chaque
     * écriture du drapeau est bien appariée à sa propagation — mais l'appariement ne suffit pas
     * quand un même client porte plusieurs dossiers : rien n'empêche deux incidents simultanés,
     * `MoteurRecouvrementHandler` créant l'incident sans chercher s'il en existe un ouvert.
     *
     * Un abonnement mensuel rejeté deux mois de suite suffit. En réglant celui de mars, le client
     * retrouvait son accès alors qu'avril restait dû — et le tableau de bord continuait de compter
     * un « accès bloqué » dont la porte était ouverte.
     *
     * ⚠ ON OBSERVE LA PORTE, PAS LE DRAPEAU. Le drapeau du second incident reste `true` dans les
     * deux versions du code : l'assertion serait vraie avant comme après, et ne mesurerait rien.
     * Ce qui change, c'est `DroitAcces.statutProjection` — l'état réel du tourniquet.
     */
    public function testResoudreUnImpayeNeRouvrePasLaccesSiUnAutreResteDu(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->findOneBy([], ['dateSouscription' => 'ASC']);
        self::assertNotNull($abonnement);
        $echeances = $em->getRepository(EcheanceSepa::class)
            ->findBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC'], 2);
        self::assertCount(2, $echeances, 'témoin : il faut deux échéances du même contrat pour produire deux impayés sur un même redevable');

        // Deux rejets, deux représentations échouées : deux dossiers bloquants sur LE MÊME client.
        $ids = [];
        foreach ($echeances as $echeance) {
            $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
                'json' => ['codeRetour' => 'AM04'],
            ]);
            self::assertResponseIsSuccessful();
            $incidentId = $client->getResponse()->toArray()['id'];
            $ids[] = $incidentId;

            $representation = $this->representationDe($incidentId);
            $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
                'json' => ['resultat' => 'echouee'],
            ]);
            self::assertResponseIsSuccessful();
        }

        // On règle le PREMIER seulement.
        $client->request('POST', '/api/recouvrement/incidents/' . $ids[0] . '/resoudre', $entete + [
            'json' => ['canal' => 'virement', 'moyenPaiement' => 'virement'],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $second = $em->getRepository(IncidentImpaye::class)->find($ids[1]);
        self::assertNotNull($second);
        self::assertTrue($second->isAccesBloque(), 'témoin : le second dossier bloque toujours, sinon ce test ne mesure rien');

        // ⚠ ON EMPRUNTE LE MÊME CHEMIN QUE LA PRODUCTION. `DroitAcces` ne porte pas le type ni la
        // référence du redevable — la correspondance vit dans le registre. Interroger la table
        // directement demanderait de recoder cette résolution dans le test, avec les mêmes chances
        // de se tromper et aucune d'être corrigé.
        $registre = static::getContainer()->get(RedevableRegistry::class);
        $droit = $registre->droitAcces($second->getTypeRedevable(), $second->getReferenceRedevable());
        self::assertNotNull($droit, 'témoin : sans droit d’accès résolu, la propagation n’aurait rien à changer et le test serait vide');

        self::assertSame(
            StatutProjectionDroit::Devalide,
            $droit->getStatutProjection(),
            'La porte s’est rouverte alors qu’un impayé reste dû : régler un dossier a réactivé le redevable entier.',
        );
    }

    /**
     * ⚠ DEUX ÉCHÉANCES REJETÉES DU MÊME CLIENT FONT DEUX INCIDENTS, ET C'EST VOULU.
     *
     * `MoteurRecouvrementHandler` crée l'incident sans chercher s'il en existe un ouvert pour ce
     * redevable. Ça ressemble à l'oubli classique du contrôle d'existant — d'où ce test, qui existe
     * pour empêcher qu'on le « corrige ». Dédoublonner par client serait une régression :
     *
     *   · l'entité porte `referenceEcheanceOrigine` et `rejetOrigine` : un dossier unique par
     *     redevable ne saurait pas quoi y mettre au second rejet ;
     *   · la représentation se fait PAR MONTANT — deux échéances se représentent séparément à la
     *     banque, un dossier fusionné n'aurait plus qu'un montant ;
     *   · « ce qui a été rejeté, quand, pour quel motif » est une pièce justificative : elle sert à
     *     expliquer une porte fermée et à tenir un litige bancaire.
     *
     * ⚠ ET QUELQUE CHOSE DÉDOUBLONNE DÉJÀ, EN AMONT — ne l'ajoutez pas ici. `DeclarerRejetSepaProcessor`,
     * le chemin des VRAIS rejets bancaires, refuse un incident si un impayé non soldé existe pour le
     * couple (échéance, redevable) : réimporter un fichier de retour est un geste humain ordinaire, et
     * il ne doit pas produire deux dossiers pour un seul rejet. Cette garde-là porte sur L'ÉCHÉANCE.
     * Celle qu'on serait tenté d'ajouter ici porterait sur le REDEVABLE — et fusionnerait deux rejets
     * bien distincts. Les deux se ressemblent dans une revue ; une seule est juste.
     *
     * ⚠ CE QUE CE TEST NE PROUVE PAS. Il compte des incidents, pas des courriers. Le doublon qui
     * atteindrait un client vit dans `RecoveryEngine`, qui dédoublonne ses campagnes sur l'INCIDENT
     * et non sur le redevable — latent aujourd'hui, et hors de ce module.
     */
    public function testDeuxEcheancesRejeteesDuMemeClientFontDeuxIncidents(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->findOneBy([], ['dateSouscription' => 'ASC']);
        self::assertNotNull($abonnement);
        $echeances = $em->getRepository(EcheanceSepa::class)
            ->findBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC'], 2);
        self::assertCount(2, $echeances, 'témoin : il faut deux échéances du même contrat, sinon ce test ne mesure rien');

        $ids = [];
        foreach ($echeances as $echeance) {
            $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
                'json' => ['codeRetour' => 'AM04'],
            ]);
            self::assertResponseIsSuccessful();
            $ids[] = $client->getResponse()->toArray()['id'];
        }

        self::assertNotSame($ids[0], $ids[1], 'Le second rejet a été fusionné dans le premier : la trace du dossier d’avril est perdue.');

        $em->clear();
        $premier = $em->getRepository(IncidentImpaye::class)->find($ids[0]);
        $second = $em->getRepository(IncidentImpaye::class)->find($ids[1]);
        self::assertNotNull($premier);
        self::assertNotNull($second);

        // Le même redevable — sinon ce sont deux clients, et le test ne dit rien du doublon.
        self::assertSame($premier->getTypeRedevable(), $second->getTypeRedevable());
        self::assertSame($premier->getReferenceRedevable(), $second->getReferenceRedevable());

        // Et deux échéances distinctes : c'est CE champ que la fusion rendrait indéfinissable.
        self::assertNotSame(
            $premier->getReferenceEcheanceOrigine(),
            $second->getReferenceEcheanceOrigine(),
            'Les deux incidents désignent la même échéance : la trace ne distingue plus ce qui a été rejeté.',
        );
    }

    /**
     * ⚠ EXEMPTER UN REDEVABLE DEJA BLOQUE DOIT ROUVRIR SA PORTE IMMEDIATEMENT.
     *
     * C'est le cas qui a motive la fonctionnalite (D84) : la collectivite qui produit un impaye par
     * mois est DEJA bloquee au moment ou l'on decide de ne plus jamais la bloquer. Si l'exemption
     * n'etait consultee qu'a la fermeture, la poser ne rouvrirait rien — il faudrait encore forcer
     * chaque dossier a la main, c'est-a-dire exactement ce qu'elle remplace.
     *
     * ⚠ ON OBSERVE LA PORTE, PAS LA LIGNE EN BASE. Verifier que l'exemption est enregistree ne
     * prouverait rien : elle l'etait aussi dans la version qui ne rouvrait pas. Ce qui change est
     * `DroitAcces.statutProjection`.
     */
    public function testExempterUnRedevableDejaBloqueRouvreSaPorte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $incidentId = $this->creerIncidentBloquant($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $incident = $em->getRepository(IncidentImpaye::class)->find($incidentId);
        self::assertNotNull($incident);

        $registre = static::getContainer()->get(RedevableRegistry::class);
        $droit = $registre->droitAcces($incident->getTypeRedevable(), $incident->getReferenceRedevable());
        self::assertNotNull($droit, 'témoin : sans droit d’accès résolu, il n’y a pas de porte à observer');
        self::assertSame(
            StatutProjectionDroit::Devalide,
            $droit->getStatutProjection(),
            'témoin : le redevable doit être bloqué AVANT l’exemption, sinon ce test ne mesure rien',
        );

        $client->request('POST', '/api/recouvrement/exemptions/accorder', $entete + [
            'json' => [
                'typeRedevable' => $incident->getTypeRedevable(),
                'referenceRedevable' => $incident->getReferenceRedevable(),
                'motif' => 'Collectivité payant à 45 jours — jamais bloquée (convention 2026).',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $droit = $registre->droitAcces($incident->getTypeRedevable(), $incident->getReferenceRedevable());
        self::assertSame(
            StatutProjectionDroit::Valide,
            $droit->getStatutProjection(),
            'L’exemption a été enregistrée mais la porte est restée fermée : elle ne vaut que pour les impayés futurs, ce qui n’est pas ce qui a été demandé.',
        );
    }

    /**
     * ⚠ UN NOUVEL IMPAYE NE REFERME PAS LA PORTE D'UN EXEMPTE.
     *
     * C'est l'autre moitie, et c'est ce qui distingue une exemption d'un forcage : le forcage vaut
     * pour UN dossier, l'exemption vaut pour le redevable et donc pour les dossiers a venir.
     */
    public function testUnNouvelImpayeNeBloquePasUnRedevableExempte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $premier = $this->creerIncidentBloquant($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $incident = $em->getRepository(IncidentImpaye::class)->find($premier);
        self::assertNotNull($incident);
        $type = $incident->getTypeRedevable();
        $reference = $incident->getReferenceRedevable();

        $client->request('POST', '/api/recouvrement/exemptions/accorder', $entete + [
            'json' => ['typeRedevable' => $type, 'referenceRedevable' => $reference, 'motif' => 'Convention 2026.'],
        ]);
        self::assertResponseIsSuccessful();

        // Un SECOND impayé, postérieur à l'exemption, mené jusqu'au blocage.
        $this->creerIncidentBloquant($client, $entete);

        $em->clear();
        $registre = static::getContainer()->get(RedevableRegistry::class);
        $droit = $registre->droitAcces($type, $reference);
        self::assertSame(
            StatutProjectionDroit::Valide,
            $droit->getStatutProjection(),
            'Un nouvel impayé a bloqué un redevable exempté : l’exemption ne vaut que pour le passé, donc ce n’est qu’un forçage renommé.',
        );
    }

    /**
     * ⚠ RETIRER L'EXEMPTION REFERME LA PORTE SI DE L'ARGENT RESTE DU — et pas autrement.
     *
     * Retirer ne doit pas « fermer » : ca doit REEVALUER. Sans reevaluation au retrait, on retirerait
     * l'exemption d'un client qui doit de l'argent et sa porte resterait ouverte en silence, jusqu'au
     * prochain incident. C'est le defaut du 30/08, dans l'autre sens.
     */
    public function testRetirerLexemptionRefermeLaPorteSiUnImpayeResteDu(): void
    {
        [$client, $entete] = $this->adminSurA();

        $incidentId = $this->creerIncidentBloquant($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $incident = $em->getRepository(IncidentImpaye::class)->find($incidentId);
        self::assertNotNull($incident);
        $type = $incident->getTypeRedevable();
        $reference = $incident->getReferenceRedevable();

        $client->request('POST', '/api/recouvrement/exemptions/accorder', $entete + [
            'json' => ['typeRedevable' => $type, 'referenceRedevable' => $reference, 'motif' => 'Convention 2026.'],
        ]);
        self::assertResponseIsSuccessful();
        $exemptionId = $client->getResponse()->toArray()['id'];

        $em->clear();
        $registre = static::getContainer()->get(RedevableRegistry::class);
        self::assertSame(
            StatutProjectionDroit::Valide,
            $registre->droitAcces($type, $reference)->getStatutProjection(),
            'témoin : la porte doit être ouverte avant le retrait, sinon le test ne mesure pas le retrait',
        );

        $client->request('POST', '/api/recouvrement/exemptions/' . $exemptionId . '/retirer', $entete);
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertSame(
            StatutProjectionDroit::Devalide,
            $registre->droitAcces($type, $reference)->getStatutProjection(),
            'L’exemption a été retirée mais la porte est restée ouverte : un client qui doit de l’argent entre encore.',
        );
    }

    /** ⚠ Le motif est la SEULE garde de ce geste : sans lui, l'API doit refuser. */
    public function testUneExemptionSansMotifEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/recouvrement/exemptions/accorder', $entete + [
            'json' => ['typeRedevable' => 'client', 'referenceRedevable' => (string) \Symfony\Component\Uid\Uuid::v4(), 'motif' => '   '],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** Un incident mené jusqu'au blocage effectif : rejet, puis représentation échouée. */
    private function creerIncidentBloquant(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        $incidentId = $this->creerIncident($client, $entete);

        $representation = $this->representationDe($incidentId);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        return $incidentId;
    }

    private function creerIncident(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        $echeance = $this->premiereEcheanceContratDemo();
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    private function representationDe(string $incidentId): RepresentationSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $representation = $em->getRepository(RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        self::assertNotNull($representation);

        return $representation;
    }
}
