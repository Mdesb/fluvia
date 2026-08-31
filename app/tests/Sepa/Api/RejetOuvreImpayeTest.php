<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Recouvrement\Entity\IncidentImpaye;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\State\DeclarerRejetSepaProcessor;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN REJET BANCAIRE OUVRE UN IMPAYÉ.
 *
 * Il n'en ouvrait aucun. `DeclarerRejetSepaProcessor` écrivait une ligne au journal des rejets et
 * s'arrêtait ; le seul appelant du moteur de recouvrement était une *simulation* du module Sport.
 *
 * ⚠ ET DEUX ÉCRANS AFFIRMAIENT LE CONTRAIRE — « Rejet enregistré. Un impayé est ouvert : il est
 * traité dans l'écran Recouvrement. » On déclarait un rejet, on lisait que c'était pris en charge, on
 * fermait l'écran, et personne ne relançait jamais. Jusqu'ici nos écrans taisaient ce qu'ils ne
 * savaient pas ; celui-là affirmait un traitement qui n'avait pas lieu. Un silence se remarque un
 * jour ; une confirmation, jamais.
 *
 * ⚠ CE QUE CES TESTS NE PROUVENT PAS. Ils prouvent que l'incident s'ouvre et qu'il désigne la bonne
 * échéance. Ils ne prouvent RIEN sur la fermeture de l'accès : `PropagationAccesHandler` résout le
 * droit à couper via un port par type de redevable, et il n'en existe qu'un
 * (`sport.abonnement_fitness`). Un rejet sur un mandat de piscine ouvre donc un incident qui ne
 * ferme aucune porte — c'est mesuré, pas supposé, et c'est écrit dans le processeur.
 */
final class RejetOuvreImpayeTest extends SepaApiTestCase
{
    /**
     * L'INCIDENT S'OUVRE, ET IL DÉSIGNE LA BONNE ÉCHÉANCE.
     *
     * On vérifie le rattachement, pas seulement l'existence : un incident ouvert sur le mauvais
     * redevable ou pour le mauvais montant satisferait un simple `assertCount(1)`, et enverrait une
     * relance à quelqu'un qui a payé.
     */
    public function testUnRejetOuvreUnImpayeSurLeClientDuMandat(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ligne = $this->ligneDeDemo();

        $this->declarerRejet($client, $entete, $ligne);
        self::assertResponseIsSuccessful();

        $incidents = $this->incidents();
        self::assertCount(1, $incidents, 'le rejet doit ouvrir un impayé, pas seulement une ligne de journal');

        $incident = $incidents[0];
        self::assertSame(
            (string) $ligne->getMandat()?->getClient()?->getId(),
            $incident->getReferenceRedevable(),
            'le redevable est le client porteur du mandat',
        );
        self::assertSame(DeclarerRejetSepaProcessor::TYPE_REDEVABLE_CLIENT, $incident->getTypeRedevable());
        self::assertSame($ligne->getMontantCentimes(), $incident->getMontantCentimes());
        self::assertSame('AM04', $incident->getMotifBancaire());
        self::assertNotNull($incident->getRejetOrigine(), 'l’incident garde le rejet qui l’a causé, sinon on ne peut pas l’expliquer');
    }

    /**
     * DÉCLARER DEUX FOIS LE MÊME REJET N'OUVRE QU'UN IMPAYÉ.
     *
     * Rafraîchir un écran et recommencer est un geste d'exploitant courant. Sans garde, le tableau
     * de bord compterait deux impayés là où il y en a un — et deux relances partiraient.
     *
     * ⚠ Le témoin est la première assertion : sans elle, un chaînage qui n'ouvrirait jamais rien
     * passerait ce test avec les félicitations.
     */
    public function testDeclarerDeuxFoisNOuvreQuUnSeulImpaye(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ligne = $this->ligneDeDemo();

        $this->declarerRejet($client, $entete, $ligne);
        self::assertCount(1, $this->incidents(), 'témoin : le premier rejet a bien ouvert un impayé');

        $this->declarerRejet($client, $entete, $ligne);
        self::assertResponseIsSuccessful('le second rejet reste enregistré : c’est un fait bancaire, pas une erreur de saisie');

        self::assertCount(1, $this->incidents(), 'mais il ne rouvre pas un second impayé pour la même échéance');
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $entete */
    private function declarerRejet(object $client, array $entete, LigneRemiseSepa $ligne): void
    {
        $client->request('POST', '/api/rejet_sepas', $entete + ['json' => [
            'ligne' => '/api/ligne_remise_sepas/' . $ligne->getId(),
            'codeMotif' => 'AM04',
            'libelleMotif' => 'Provision insuffisante',
            'dateRejet' => '2026-01-15',
        ]]);
    }

    private function ligneDeDemo(): LigneRemiseSepa
    {
        $em = $this->em();

        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['rum' => 'RUM-DEMO-REGIE-0001']);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        self::assertNotNull($mandat->getClient(), 'le mandat de démonstration doit porter un client, sinon ce test ne mesure rien');

        $ligne = $em->getRepository(LigneRemiseSepa::class)->findOneBy(['mandat' => $mandat]);
        self::assertInstanceOf(LigneRemiseSepa::class, $ligne);

        return $ligne;
    }

    /** @return list<IncidentImpaye> */
    private function incidents(): array
    {
        $em = $this->em();
        $em->clear();

        return array_values($em->getRepository(IncidentImpaye::class)->findAll());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
