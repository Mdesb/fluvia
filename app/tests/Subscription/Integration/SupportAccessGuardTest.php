<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Audit\Entity\EntreeAudit;
use App\Audit\Service\JournalAudit;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Exception\SupportAccessDeniedException;
use App\Subscription\Service\SupportAccessGuard;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-4, RG-ED-07, CA-6 — l'accès d'assistance, et surtout tout ce qu'il refuse.
 *
 * La règle tient en une phrase : **il n'existe aucun rôle qui voit tous les établissements**. Ces
 * tests sont là pour qu'elle reste vraie. Chacun décrit une manière plausible de la contourner par
 * distraction — un accès sans fin, un accès pour un autre établissement, un accès révoqué qu'on
 * oublie de vérifier — et chacun tombera le jour où quelqu'un l'introduira.
 */
final class SupportAccessGuardTest extends SocleApiTestCase
{
    private const AGENT_EMAIL = 'assistance@editeur.test';

    /**
     * ⚠ UN ACCÈS OUVERT CONFÈRE LES DROITS D'UN ADMINISTRATEUR — décision de Maxime du 31/08.
     *
     * Ce test manquait, et son absence coûtait cher : le port `SupportAccessRightsInterface` était
     * câblé sur l'implémentation qui n'accorde JAMAIS rien. Toute la chaîne existait — ouvrir,
     * tracer, expirer, révoquer, refuser — sauf le maillon qui sert. Ouvrir un accès d'assistance
     * ne changeait strictement rien, et aucune erreur ne le signalait.
     *
     * Sept tests verts autour d'un mécanisme sans effet : chaque pièce éprouvée, l'assemblage
     * jamais.
     *
     * ⚠ ON OBSERVE `CalculateurDroits`, PAS LE GARDE. Le garde répondait déjà « cet agent peut
     * lire » — c'est ce que vérifie le test voisin. Ce qui manquait est que cette réponse se
     * TRANSFORME en permissions effectives, et ce chemin passe par le port et son câblage.
     *
     * ⚠ LE TÉMOIN NÉGATIF EST LA MOITIÉ QUI COMPTE : sans accès, aucun droit. Sans lui, un
     * calculateur qui accorderait tout à tout le monde rendrait ce test vert.
     */
    public function testUnAccesOuvertConfereLesDroitsDAdministrateur(): void
    {
        $agent = $this->agent();
        $client = $this->etablissementClient('Camping des Écluses');

        /** @var \App\Securite\Service\CalculateurDroits $calculateur */
        $calculateur = static::getContainer()->get(\App\Securite\Service\CalculateurDroits::class);

        // ── TÉMOIN : sans accès, l'agent n'a rien chez ce client ──────────────────────────────
        $avant = $calculateur->codesEffectifs($agent, $client->getId());
        self::assertSame(
            [],
            $avant,
            'témoin : sans accès d’assistance, un agent de l’éditeur ne doit rien avoir chez un client. '
            .'Si ce tableau n’est pas vide, la suite ne prouve rien sur l’accès.',
        );

        // ⚠ LA FENÊTRE ENTOURE L'HEURE RÉELLE, ET NON LE `maintenant()` FIXE DU FICHIER.
        //
        // Les sept tests voisins passent leur instant explicitement au garde, qui le reçoit en
        // argument — ils peuvent donc vivre au 15/09/2026. Celui-ci observe la chaîne par
        // `CalculateurDroits::codesEffectifs()`, qui ne prend pas d'instant : il forge son propre
        // `new \DateTimeImmutable()`. Une fenêtre posée autour d'une date fixe serait donc dans le
        // futur au moment où le calculateur interroge, et l'accès ne vaudrait rien.
        //
        // ⚠ CE DÉTAIL EXPLIQUE L'ANGLE MORT QU'ON COMBLE ICI : le garde reçoit son instant, donc il
        // se teste à l'instant qu'on veut ; le calculateur le fabrique, donc il ne se teste qu'à
        // l'instant qu'il est. Les tests se sont massés du côté commode, et le maillon manquant
        // était de l'autre.
        $reel = new \DateTimeImmutable();
        $this->guard()->grant(
            $agent,
            $client,
            'Ticket 5102 — la caisse ne s’ouvre pas',
            $reel->modify('-1 hour'),
            $reel->modify('+1 hour'),
            'chef@editeur.test',
        );

        $apres = $calculateur->codesEffectifs($agent, $client->getId());
        self::assertNotSame(
            [],
            $apres,
            'Un accès d’assistance ouvert ne confère aucun droit : le port est câblé sur une '
            .'implémentation qui n’accorde rien, et ouvrir un accès ne change strictement rien.',
        );
        self::assertContains(
            '*.*',
            $apres,
            'Maxime a tranché « tout, comme un administrateur » : le joker doit être accordé.',
        );
    }

    /**
     * ⚠ L'ACCÈS EST CIBLÉ : il ne déborde pas sur un autre établissement du même client.
     *
     * Sans cette borne, ouvrir un accès pour dépanner une piscine donnerait aussi la patinoire d'à
     * côté — et le motif écrit à l'ouverture ne couvrirait plus ce qui a été vu.
     */
    public function testLAccesNeDebordePasSurUnAutreEtablissement(): void
    {
        $agent = $this->agent();
        $depanne = $this->etablissementClient('Camping des Écluses');
        $voisin = $this->etablissementClient('Camping des Peupliers');

        // Fenêtre à l'heure réelle : voir le test précédent — le calculateur forge son propre instant.
        $reel = new \DateTimeImmutable();
        $this->guard()->grant($agent, $depanne, 'Ticket 5102', $reel->modify('-1 hour'), $reel->modify('+1 hour'), 'chef@editeur.test');

        /** @var \App\Securite\Service\CalculateurDroits $calculateur */
        $calculateur = static::getContainer()->get(\App\Securite\Service\CalculateurDroits::class);

        self::assertNotSame([], $calculateur->codesEffectifs($agent, $depanne->getId()), 'témoin : l’accès doit valoir sur l’établissement dépanné');
        self::assertSame([], $calculateur->codesEffectifs($agent, $voisin->getId()), 'L’accès déborde sur un établissement voisin : le motif écrit ne couvre plus ce qui a été vu.');
    }

    /** L'accès ouvert autorise la lecture, et le passage est tracé. */
    public function testUnAccesOuvertAutoriseEtLaisseUneTrace(): void
    {
        $agent = $this->agent();
        $client = $this->etablissementClient('Camping du Lac');

        $this->guard()->grant($agent, $client, 'Ticket 4218 — facture introuvable', $this->hier(), $this->demain(), 'chef@editeur.test');
        $this->guard()->assertCanRead($agent, $client, $this->maintenant());

        self::assertSame(1, $this->countAudit('support_access.granted'));
        self::assertSame(1, $this->countAudit('support_access.used'));
        self::assertSame(0, $this->countAudit('support_access.denied'));
    }

    /**
     * **CA-6** — l'accès expiré est refusé en échec fermé, et la tentative est tracée.
     *
     * Le second point compte autant que le premier : une tentative refusée sans trace est exactement
     * celle qu'on voudrait retrouver le jour où l'on se demande qui a essayé de regarder quoi.
     */
    public function testCa6UnAccesExpireEstRefuseEtLaTentativeEstTracee(): void
    {
        $agent = $this->agent();
        $client = $this->etablissementClient('Piscine expirée');

        $this->guard()->grant($agent, $client, 'Ticket clos', $this->hier(), $this->maintenant(), 'chef@editeur.test');

        try {
            $this->guard()->assertCanRead($agent, $client, $this->demain());
            self::fail('la lecture aurait dû être refusée');
        } catch (SupportAccessDeniedException $refus) {
            self::assertStringContainsString('tracée', $refus->getMessage());
        }

        self::assertSame(1, $this->countAudit('support_access.denied'), 'la tentative refusée doit être tracée');
        self::assertSame(0, $this->countAudit('support_access.used'));
    }

    /** Un accès révoqué ne sert plus, même avant son échéance. */
    public function testUnAccesRevoqueNeSertPlus(): void
    {
        $agent = $this->agent();
        $client = $this->etablissementClient('Hôtel révoqué');

        $acces = $this->guard()->grant($agent, $client, 'Ticket 12', $this->hier(), $this->demain(), null);
        $this->guard()->revoke($acces, $this->maintenant(), 'chef@editeur.test');

        $this->expectException(SupportAccessDeniedException::class);

        $this->guard()->assertCanRead($agent, $client, $this->maintenant()->modify('+1 minute'));
    }

    /**
     * Un accès sur un établissement n'en ouvre aucun autre.
     *
     * C'est la forme la plus probable de la fuite : l'agent a une raison légitime de regarder un
     * client, et le contrôle porterait sur « a-t-il un accès » au lieu de « a-t-il un accès *sur
     * celui-ci* ».
     */
    public function testUnAccesNouvrePasLesAutresEtablissements(): void
    {
        $agent = $this->agent();
        $autorise = $this->etablissementClient('Client autorisé');
        $voisin = $this->etablissementClient('Client voisin');

        $this->guard()->grant($agent, $autorise, 'Ticket 7', $this->hier(), $this->demain(), null);

        self::assertTrue($this->guard()->canRead($agent, $autorise, $this->maintenant()));
        self::assertFalse($this->guard()->canRead($agent, $voisin, $this->maintenant()));

        $this->expectException(SupportAccessDeniedException::class);
        $this->guard()->assertCanRead($agent, $voisin, $this->maintenant());
    }

    /** Sans le moindre accès, la lecture est refusée — et tracée elle aussi. */
    public function testSansAccesLaLectureEstRefuseeEtTracee(): void
    {
        $agent = $this->agent();
        $client = $this->etablissementClient('Jamais ouvert');

        try {
            $this->guard()->assertCanRead($agent, $client, $this->maintenant());
            self::fail('la lecture aurait dû être refusée');
        } catch (SupportAccessDeniedException) {
            // attendu
        }

        self::assertSame(1, $this->countAudit('support_access.denied'));
    }

    /**
     * Une fenêtre vide est refusée à l'ouverture.
     *
     * Un accès qui expire avant de commencer se lit comme un accès accordé dans une liste, et n'en
     * est pas un. Le refuser à l'ouverture évite un accès fantôme que personne ne comprend.
     */
    public function testUneFenetreVideEstRefuseeALouverture(): void
    {
        $this->expectException(SupportAccessDeniedException::class);

        $this->guard()->grant(
            $this->agent(),
            $this->etablissementClient('Fenêtre vide'),
            'Ticket 9',
            $this->demain(),
            $this->hier(),
            null,
        );
    }

    /** L'éditeur ne s'ouvre pas d'accès d'assistance sur lui-même. */
    public function testPasDaccesDassistanceSurLetablissementDeLediteur(): void
    {
        $this->expectException(SupportAccessDeniedException::class);

        $this->guard()->grant($this->agent(), $this->editeur(), 'Ticket interne', $this->hier(), $this->demain(), null);
    }

    /** Un motif vide est refusé : sans lui, la trace ne se justifie pas devant le client. */
    public function testUnMotifVideEstRefuse(): void
    {
        $this->expectException(SupportAccessDeniedException::class);

        $this->guard()->grant($this->agent(), $this->etablissementClient('Sans motif'), '   ', $this->hier(), $this->demain(), null);
    }

    // ---------------------------------------------------------------- montage

    private function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 12:00:00');
    }

    private function hier(): \DateTimeImmutable
    {
        return $this->maintenant()->modify('-1 day');
    }

    private function demain(): \DateTimeImmutable
    {
        return $this->maintenant()->modify('+1 day');
    }

    private function guard(): SupportAccessGuard
    {
        /** @var JournalAudit $journal */
        $journal = static::getContainer()->get(JournalAudit::class);

        return new SupportAccessGuard($this->em(), $journal, $this->editorTenant());
    }

    private function editorTenant(): EditorTenantResolver
    {
        return new EditorTenantResolver($this->em(), $this->editeur()->getId()->toRfc4122());
    }

    private function editeur(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        return $etablissement;
    }

    /** Un établissement client, dans son propre arbre — comme le provisionnement le crée. */
    private function etablissementClient(string $nom): Etablissement
    {
        $groupe = (new Groupe())->setNom($nom);
        $this->em()->persist($groupe);

        $region = (new Region())->setNom($nom)->setGroupe($groupe);
        $this->em()->persist($region);

        $etablissement = (new Etablissement())->setNom($nom)->setRegion($region)->setActif(true);
        $this->em()->persist($etablissement);
        $this->em()->flush();

        return $etablissement;
    }

    private function agent(): Utilisateur
    {
        $existant = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::AGENT_EMAIL]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $agent = new Utilisateur();
        $agent->setEmail(self::AGENT_EMAIL)
            ->setNom('Agent d\'assistance')
            ->setMotDePasse('peu importe')
            ->setStatut(StatutUtilisateur::Actif);
        $this->em()->persist($agent);
        $this->em()->flush();

        return $agent;
    }

    private function countAudit(string $action): int
    {
        return \count($this->em()->getRepository(EntreeAudit::class)->findBy(['action' => $action]));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
