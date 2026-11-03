<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Support;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutJetonTerminal;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Un droit n'ouvre que les portes de son établissement, sauf zones explicites.
 *
 * Le défaut : un droit de réservation ou de personnel sans zone ouvrait toute porte (`ouvre()`), et
 * ni le contrôle en ligne ni le contrôle à la main ne comparaient l'établissement du droit à celui de
 * la porte. Le badge d'un client A ouvrait la porte d'un client B. Même famille : `/acces/passages`
 * prenait l'équipement du corps sans regarder le site de l'appelant.
 */
final class CrossEstablishmentAccessTest extends AccesApiTestCase
{
    private const SECRET_TIERS = 'secret-terminal-site-tiers-essai';
    private const SECRET_A = 'secret-terminal-site-a-essai';
    private const SECRET_B = 'secret-terminal-site-b-essai';

    public function testABookingRightOfClientADoesNotOpenClientBsDoor(): void
    {
        $badge = $this->badge(TypeDroitAcces::Booking, SocleFixtures::ETAB_A_NOM);
        $porte = $this->door($this->foreignSite(), 'TIERS', self::SECRET_TIERS);

        self::assertSame(['refuse', 'droit_invalide'], $this->scan($porte, self::SECRET_TIERS, $badge));
    }

    public function testAStaffRightOfClientADoesNotOpenClientBsDoor(): void
    {
        $badge = $this->badge(TypeDroitAcces::Personnel, SocleFixtures::ETAB_A_NOM);
        $porte = $this->door($this->foreignSite(), 'TIERS', self::SECRET_TIERS);

        self::assertSame(['refuse', 'droit_invalide'], $this->scan($porte, self::SECRET_TIERS, $badge));
    }

    /** Le contrôle à la main, sans porte : l'agent d'un autre groupe ne valide ni ne consomme le billet de A. */
    public function testAnAgentOfAnotherGroupCannotValidateClientAsTicketByHand(): void
    {
        $agent = $this->agent($this->foreignSite());

        $vue = static::createClient()->request('POST', '/api/acces/controle-billet', $agent + [
            'json' => ['identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ])->toArray();

        self::assertSame('refuse', $vue['resultat']);
        self::assertNull($vue['billet'] ?? null, 'Rien du billet de A ne fuit vers B.');
        self::assertSame(12, $this->credit(), 'Le crédit de A est intact.');
    }

    public function testAPassageIngestedWithAnotherSitesEquipmentIsNotFound(): void
    {
        $agent = $this->agent($this->foreignSite());

        $reponse = static::createClient()->request('POST', '/api/acces/passages', $agent + ['json' => [
            'equipement' => '/api/equipements/' . $this->idEquipement(),
            'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            'sens' => 'entree',
        ]]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(12, $this->credit());
    }

    public function testAnOfflineBatchWithAnotherSitesEquipmentDoesNotConsumeClientAsCredit(): void
    {
        $porte = $this->door($this->foreignSite(), 'TIERS', self::SECRET_TIERS);
        $controleur = (string) $porte->getControleur()?->getId();
        $agent = $this->agent($this->foreignSite());

        $reponse = static::createClient()->request('POST', '/api/acces/synchro', $agent + ['json' => [
            'controleur' => '/api/controleurs/' . $controleur,
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatage' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ]],
        ]]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(12, $this->credit(), 'Un lot du site tiers ne décompte pas la carte de A.');
    }

    /** Même groupe, autre site : la carte de ville est un autre chantier (E7) ; pour l'instant, refusé. */
    public function testABookingRightOfSiteADoesNotOpenSiteBOfTheSameGroup(): void
    {
        $badge = $this->badge(TypeDroitAcces::Booking, SocleFixtures::ETAB_A_NOM);
        $porte = $this->door(SocleFixtures::ETAB_B_NOM, 'B', self::SECRET_B);

        self::assertSame(['refuse', 'droit_invalide'], $this->scan($porte, self::SECRET_B, $badge));
    }

    /** Témoin : chez lui, le droit sans zone ouvre toujours (réservation et personnel). */
    public function testRightsWithoutZoneStillOpenTheirOwnSite(): void
    {
        $porte = $this->door(SocleFixtures::ETAB_A_NOM, 'A2', self::SECRET_A);

        self::assertSame(['valide', null], $this->scan($porte, self::SECRET_A, $this->badge(TypeDroitAcces::Booking, SocleFixtures::ETAB_A_NOM)));
        self::assertSame(['valide', null], $this->scan($porte, self::SECRET_A, $this->badge(TypeDroitAcces::Personnel, SocleFixtures::ETAB_A_NOM)));
    }

    /** Hors ligne : le snapshot du site tiers n'embarque aucun support de A ; celui de A, si. */
    public function testTheOfflineSnapshotOfASiteCarriesNoRightOfAnotherSite(): void
    {
        $badge = $this->badge(TypeDroitAcces::Booking, SocleFixtures::ETAB_A_NOM);
        $this->door($this->foreignSite(), 'TIERS', self::SECRET_TIERS);
        $this->door(SocleFixtures::ETAB_A_NOM, 'A2', self::SECRET_A);

        $tiers = static::createClient()->request('GET', '/api/terminal/snapshot', $this->terminalEntete(self::SECRET_TIERS))->toArray();
        $chezA = static::createClient()->request('GET', '/api/terminal/snapshot', $this->terminalEntete(self::SECRET_A))->toArray();

        self::assertSame([], array_column($tiers['entrees'], 'identifiant'));
        self::assertContains($badge, array_column($chezA['entrees'], 'identifiant'));
    }

    /** @return array{0: string, 1: ?string} résultat et motif : un refus pour une autre raison ne prouverait rien */
    private function scan(Equipement $porte, string $secret, string $identifiant): array
    {
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete($secret) + ['json' => [
            'equipementId' => (string) $porte->getId(),
            'identifiantSupport' => $identifiant,
            'sens' => 'entree',
            'cleIdempotence' => (string) Uuid::v4(),
        ]]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $verdict = $reponse->toArray();

        return [$verdict['resultat'], $verdict['codeMotif']];
    }

    /** Un établissement d'un autre groupe, c'est-à-dire d'un autre client de la plateforme. */
    private function foreignSite(): string
    {
        if ($this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => 'Site tiers']) instanceof Etablissement) {
            return 'Site tiers';
        }
        $groupe = (new Groupe())->setNom('Groupe tiers');
        $region = (new Region())->setNom('Région tiers')->setGroupe($groupe);
        $site = (new Etablissement())->setNom('Site tiers')->setRegion($region)->setActif(true);
        foreach ([$groupe, $region, $site] as $entite) {
            $this->em()->persist($entite);
        }
        $this->em()->flush();

        return 'Site tiers';
    }

    /** Une porte (espace, contrôleur, tourniquet) et la borne qui la sert. */
    private function door(string $nomSite, string $suffixe, string $secret): Equipement
    {
        $em = $this->em();
        $site = $this->entite(Etablissement::class, ['nom' => $nomSite]);
        $socle = (new Espace())->setNom('Hall ' . $suffixe)->setEtablissement($site)->setType('hall');
        $espace = (new EspaceAcces())->setLibelle('Zone ' . $suffixe)->setEspaceSocle($socle)->setSeuilFmi(100);
        $controleur = (new Controleur())->setLibelle('Contrôleur ' . $suffixe)->setEspace($espace)->setItboxRef('ITBOX-' . $suffixe);
        $porte = (new Equipement())->setLibelle('Tourniquet ' . $suffixe)->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $terminal = (new Terminal())->setNom('Borne ' . $suffixe)->setItboxRef('ITBOX-' . $suffixe)->setEtablissement($site)->setStatut(StatutTerminal::Actif);
        $jeton = (new JetonTerminal())->setTerminal($terminal)->setSecretHash(hash('sha256', $secret))->setStatut(StatutJetonTerminal::Actif)->setEtablissement($site);
        foreach ([$socle, $espace, $controleur, $porte, $terminal, $jeton] as $entite) {
            $em->persist($entite);
        }
        $em->flush();

        return $porte;
    }

    /** Un droit sans zone (comme la projection d'une réservation ou un badge du personnel) et son support. */
    private function badge(TypeDroitAcces $type, string $nomSite): string
    {
        $em = $this->em();
        $site = $this->entite(Etablissement::class, ['nom' => $nomSite]);
        $droit = (new DroitAcces())->setSourceType($type)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($site);
        if ($type === TypeDroitAcces::Booking) {
            $droit->setFenetreDebut(new \DateTimeImmutable('-1 hour'))->setFenetreFin(new \DateTimeImmutable('+1 hour'));
        }
        $identifiant = 'BADGE-' . substr((string) Uuid::v4(), 0, 8);
        $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Rfid)->setEtablissement($site);
        $appairage = (new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($site);
        foreach ([$droit, $support, $appairage] as $entite) {
            $em->persist($entite);
        }
        $em->flush();

        return $identifiant;
    }

    /** @return array<string, mixed> en-têtes d'un agent d'accès affecté au seul site donné */
    private function agent(string $nomSite): array
    {
        $em = $this->em();
        $site = $this->entite(Etablissement::class, ['nom' => $nomSite]);
        $role = (new Role())->setNom('Agent accès ' . $site->getNom())->setEstModele(false);
        foreach (['ingestion', 'controler', 'lire'] as $action) {
            $role->addPermission($this->entite(Permission::class, ['module' => 'acces', 'action' => $action]));
        }
        $email = 'agent.' . substr((string) Uuid::v4(), 0, 8) . '@essai.test';
        $agent = (new Utilisateur())->setEmail($email)->setNom('Agent tiers')->setActif(true);
        $agent->setMotDePasse(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($agent, 'aaa'));
        foreach ([$role, $agent, (new Affectation())->setUtilisateur($agent)->setRole($role)->setEtablissement($site)] as $entite) {
            $em->persist($entite);
        }
        $em->flush();

        return ['auth_bearer' => $this->jeton(static::createClient(), $email, 'aaa'), 'headers' => [ContexteEtablissement::HEADER => (string) $site->getId()]];
    }

    private function credit(): ?int
    {
        $this->em()->clear();

        $support = $this->entite(Support::class, ['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT]);

        return $this->entite(Appairage::class, ['support' => $support])->getDroit()?->getCreditRestant();
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
