<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Jauge FMI — présence simultanée ≠ cumul (US-L3-05, RG-ACC-04, CA-6) : deux compteurs distincts,
 * blocage au seuil (mode `blocage`), alerte sans blocage (mode `alerte`), décrément à la sortie.
 */
final class FmiTest extends AccesApiTestCase
{
    public function testCa6SeuilBlocageRefuseAuDelaEtDecoupleFmiDuCumul(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipementEntree, $supportA, $supportB] = $this->creerEspaceASeuilUn(ModeSeuil::Blocage);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Seuil atteint (1) : le second passage (support distinct) est refusé.
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportB],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('seuil_fmi', $reponse['codeMotif']);
    }

    public function testCa6ModeAlerteNeBloquePas(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipementEntree, $supportA, $supportB] = $this->creerEspaceASeuilUn(ModeSeuil::Alerte);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Seuil atteint mais mode alerte : le passage suivant reste validé (pas de blocage).
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportB],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);
    }

    public function testCa6DecrementeALaSortieEtJaugeDistincteDuCumul(): void
    {
        [$client, $entete] = $this->adminSurA();

        $equipementEntree = $this->idEquipement();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);

        // Équipement de sortie (même contrôleur) pour décrémenter la jauge.
        $controleur = $em->getRepository(Controleur::class)->findOneBy(['libelle' => AccesFixtures::CONTROLEUR_LIBELLE]);
        $sortie = (new Equipement())->setLibelle('Tourniquet Sortie A1')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Sortie);
        $em->persist($sortie);
        $em->flush();

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $sortie->getId(), 'sens' => 'sortie', 'motif' => 'sortie test'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/jauge_fmis', $entete);
        $liste = $client->getResponse()->toArray();
        $jauge = ($liste['member'] ?? $liste['hydra:member'])[0];

        // 1 entrée validée + 1 sortie non nominative : présents = 0, cumul (entrées) = 1 (RG-ACC-04).
        self::assertSame(0, $jauge['valeurCourante']);
        self::assertSame(1, $jauge['cumulJour']);
    }

    public function testCa6RecalageRemiseAZeroALOuverture(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var \App\Acces\Service\RecalageFmiHandler $recalage */
        $recalage = static::getContainer()->get(\App\Acces\Service\RecalageFmiHandler::class);
        $espace = $em->getRepository(EspaceAcces::class)->findOneBy(['libelle' => AccesFixtures::ESPACE_LIBELLE]);

        $jaugeAvant = $em->getRepository(\App\Acces\Entity\JaugeFmi::class)->findOneBy(['espace' => $espace]);
        self::assertSame(1, $jaugeAvant->getValeurCourante());

        $recalage->ouvrir($espace);

        $em->clear();
        $jaugeApres = $em->getRepository(\App\Acces\Entity\JaugeFmi::class)->findOneBy(['espace' => $espace]);
        self::assertSame(0, $jaugeApres->getValeurCourante(), 'Remise à zéro à l\'ouverture (recalageOuverture = remise_a_zero, §4.5).');
        self::assertSame(0, $jaugeApres->getCumulJour());
    }

    /**
     * Crée un espace d'accès de test au seuil FMI = 1 avec un équipement d'entrée, et deux supports
     * (billets simples, sans crédit) prêts à franchir.
     *
     * @return array{0: string, 1: string, 2: string} idEquipement, identifiantSupportA, identifiantSupportB
     */
    private function creerEspaceASeuilUn(ModeSeuil $mode): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Espace FMI test')->setEtablissement($etab)->setType('salle');
        $em->persist($espaceSocle);

        $espace = (new EspaceAcces())->setLibelle('Espace FMI ' . $mode->value)->setEspaceSocle($espaceSocle)->setSeuilFmi(1)->setModeSeuil($mode);
        $em->persist($espace);

        $controleur = (new Controleur())->setLibelle('Contrôleur FMI ' . $mode->value)->setEspace($espace)->setItboxRef('ITBOX-FMI-' . $mode->value);
        $em->persist($controleur);

        $equipement = (new Equipement())->setLibelle('Entrée FMI ' . $mode->value)->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $identifiantA = 'FMI-A-' . substr((string) Uuid::v4(), 0, 8);
        $identifiantB = 'FMI-B-' . substr((string) Uuid::v4(), 0, 8);
        foreach ([$identifiantA, $identifiantB] as $identifiant) {
            $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Billet)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($etab);
            $em->persist($droit);
            $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
            $em->persist($support);
            $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        }

        $em->flush();

        return [(string) $equipement->getId(), $identifiantA, $identifiantB];
    }
}
