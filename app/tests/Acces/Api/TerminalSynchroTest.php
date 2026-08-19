<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Espace;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Remontée hors-ligne au niveau `Terminal` (POST /terminal/passages/lot, US-TERM-06/07/08, CA-7/9/10,
 * §2.4/§4.3 du plan).
 */
final class TerminalSynchroTest extends AccesApiTestCase
{
    public function testCa7LotDecrementeCreditPuisRejeuMemeLotIdempotent(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $cle = (string) Uuid::v4();
        $lot = [
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                'cleIdempotence' => $cle,
            ]],
        ];

        $premier = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame(1, $premier['recus']);
        self::assertCount(1, $premier['resultats']);
        self::assertSame('accepte', $premier['resultats'][0]['statut']);
        self::assertFalse($premier['resultats'][0]['enConflit']);

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant());

        // Rejeu du même lot (retransmission réseau) : idempotent, aucun second décompte.
        $second = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('doublon', $second['resultats'][0]['statut']);

        $droitApres = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droitApres->getCreditRestant(), 'Aucun second décompte au rejeu (idempotence).');
    }

    public function testEquipementHorsPorteeRejeteSansBloquerLeReteDuLot(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        [$equipementAutreItbox] = $this->creerEquipementAutreItboxRef();

        $cleValide = (string) Uuid::v4();
        $cleHorsPortee = (string) Uuid::v4();
        $lot = [
            'lot' => [
                [
                    'equipementId' => (string) $equipementAutreItbox->getId(),
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                    'cleIdempotence' => $cleHorsPortee,
                ],
                [
                    'equipementId' => $this->idEquipement(),
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                    'cleIdempotence' => $cleValide,
                ],
            ],
        ];

        $reponse = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame(2, $reponse['recus']);

        $parCle = [];
        foreach ($reponse['resultats'] as $resultat) {
            $parCle[$resultat['cleIdempotence']] = $resultat;
        }
        self::assertSame('rejete', $parCle[$cleHorsPortee]['statut']);
        self::assertSame('hors_portee', $parCle[$cleHorsPortee]['codeMotif']);
        self::assertSame('accepte', $parCle[$cleValide]['statut'], 'Les autres entrées du lot restent traitées normalement.');
    }

    /**
     * Durcissement revue sécurité : `equipementId` transmis en IRI (`/api/equipements/{uuid}`) doit
     * être normalisé vers l'UUID avant comparaison à la portée du terminal — sans normalisation,
     * l'entrée était rejetée à tort `hors_portee` (faux négatif).
     */
    public function testEquipementIdEnIriEstNormaliseEtTraiteSansFauxHorsPortee(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $lot = [
            'lot' => [[
                'equipementId' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ]],
        ];

        $reponse = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('accepte', $reponse['resultats'][0]['statut'], 'equipementId en IRI doit être normalisé et traité, pas rejeté hors_portee.');
        self::assertNotSame('hors_portee', $reponse['resultats'][0]['codeMotif']);
    }

    public function testCa10EcartHorlogeSuspectSignaleSansBloquerLaJournalisation(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $lot = [
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'horodatageBorne' => (new \DateTimeImmutable())->modify('-1 hour')->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ]],
        ];

        $reponse = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('accepte', $reponse['resultats'][0]['statut'], 'Un écart horloge ne doit jamais bloquer la journalisation.');
        self::assertTrue($reponse['resultats'][0]['ecartHorlogeSuspect']);
    }

    public function testCa9RecalageFmiAppliqueAuxDeuxEspacesToucherParLeLot(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        [$espace2, $equipement2, $support2] = $this->creerSecondEspaceMemeItboxRef();

        $lot = [
            'lot' => [
                [
                    'equipementId' => $this->idEquipement(),
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'sens' => 'entree',
                    'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                    'cleIdempotence' => (string) Uuid::v4(),
                ],
                [
                    'equipementId' => (string) $equipement2->getId(),
                    'identifiantSupport' => $support2->getIdentifiant(),
                    'sens' => 'entree',
                    'horodatageBorne' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                    'cleIdempotence' => (string) Uuid::v4(),
                ],
            ],
        ];

        $reponse = $client->request('POST', '/api/terminal/passages/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('accepte', $reponse['resultats'][0]['statut']);
        self::assertSame('accepte', $reponse['resultats'][1]['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $jauge1 = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])]);
        $jauge2 = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace2]);
        self::assertNotNull($jauge1, 'La jauge FMI du 1er espace touché par le lot doit être recalée/présente.');
        self::assertNotNull($jauge2, 'La jauge FMI du 2e espace touché par le lot doit aussi être recalée/présente (US-TERM-07).');
    }

    /** @return array{0: Equipement} */
    private function creerEquipementAutreItboxRef(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);

        $controleur = new Controleur();
        $controleur->setLibelle('Contrôleur hors portée (synchro)')->setEspace($espace)->setItboxRef('ITBOX-SYNCHRO-AUTRE');
        $em->persist($controleur);

        $equipement = new Equipement();
        $equipement->setLibelle('Tourniquet hors portée (synchro)')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $em->flush();

        return [$equipement];
    }

    /** @return array{0: EspaceAcces, 1: Equipement, 2: Support} un second espace/contrôleur/équipement/support du MÊME itboxRef que le Terminal démo */
    private function creerSecondEspaceMemeItboxRef(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Bassin secondaire')->setEtablissement($etab)->setType('bassin');
        $em->persist($espaceSocle);

        $espace = (new EspaceAcces())->setLibelle('Zone tourniquets Piscine A2')->setEspaceSocle($espaceSocle)->setSeuilFmi(50)->setModeSeuil(ModeSeuil::Blocage);
        $em->persist($espace);

        // Même itboxRef que AccesFixtures::ITBOX_REF : un même concentrateur pilote plusieurs contrôleurs
        // de sens/espaces différents (§4.1 spec, décision granularité = l'ITBOX).
        $controleur = (new Controleur())->setLibelle('Contrôleur Entrée A2')->setEspace($espace)->setItboxRef(AccesFixtures::ITBOX_REF);
        $em->persist($controleur);

        $equipement = (new Equipement())->setLibelle('Tourniquet Entrée A2')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::CarteQuota)->setCreditRestant(5)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($etab);
        $em->persist($droit);

        $support = new Support();
        $support->setIdentifiant('SYNC-A2-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $appairage = new Appairage();
        $appairage->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
        $em->persist($appairage);

        $em->flush();

        return [$espace, $equipement, $support];
    }
}
