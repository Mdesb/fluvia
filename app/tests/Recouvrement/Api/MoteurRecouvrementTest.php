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

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('app_1_clic', $incident['canalResolution']);
        self::assertFalse($incident['accesBloque']);
    }

    public function testResolutionRefuseeSiIncidentDejaResolu(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
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
        $client->request('POST', '/api/recouvrement/incidents/' . $ids[0] . '/resoudre', $entete);
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
