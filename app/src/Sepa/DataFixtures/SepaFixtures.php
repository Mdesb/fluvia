<?php

declare(strict_types=1);

namespace App\Sepa\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Sepa\Service\GenerationRemiseHandler;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du module SEPA partagé (plan-sepa.md) : permissions `sepa.*` accordées à
 * l'administrateur, une configuration créancier **régie** (établissement A, `ProfilExploitant` régie
 * directe existant — `ComptaFixtures`) et une configuration créancier **privée** (établissement B,
 * variante surchargée explicitement — pas de `ProfilExploitant` sur B), un mandat SEPA de démonstration
 * par variante, et une **remise régie de démonstration** générée via `GenerationRemiseHandler` (preuve
 * de réutilisation bi-régime du module, plan-sepa.md §5 « Piscine »).
 */
final class SepaFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const REGIE_IBAN_DEMO = 'FR7630006000011234567890189';
    public const PRIVE_IBAN_DEMO = 'FR7630004000031234567890143';

    public function __construct(
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
        private readonly GenerationRemiseHandler $generationRemiseHandler,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class, CrmFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions sepa.* + octroi à l'administrateur (RG-SOCLE-02/03) ---
        $perms = [];
        foreach (['lire', 'gerer', 'generer_remise', 'declarer_rejet'] as $action) {
            $perm = $this->permissionNommee($manager, 'sepa', $action);
            $manager->persist($perm);
            $perms[$action] = $perm;
        }
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdmin->addPermission($perm);
            }
        }
        // Octroi également au rôle du second groupe (CrmFixtures, RG-SOCLE-05) : permet de tester le
        // cloisonnement établissement avec un utilisateur qui a les permissions mais pas l'affectation
        // (même pattern que SportFixtures).
        $roleAdminB = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe B']);
        if ($roleAdminB instanceof Role) {
            foreach ($perms as $perm) {
                $roleAdminB->addPermission($perm);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement || !$payeur instanceof Client) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DÉMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de données cohérent, pas un référentiel : le reposer sur une
        // base qui l'a déjà écraserait ce qui a été corrigé à la main depuis, ou le dupliquerait
        // pour les entités sans contrainte d'unicité — silencieusement.
        //
        // Les permissions et les rôles restent AU-DESSUS de cette garde : ils doivent être rejoués à
        // chaque chargement, sans quoi un droit ajouté au code n'atteindrait jamais une base
        // existante.
        if ($manager->getRepository(ConfigCreancierSepa::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }

        // --- Configuration créancier RÉGIE (établissement A, ProfilExploitant régie directe) ---
        $ibanConfigA = 'FR7600000000000000000000097';
        $tokenIbanA = $this->tokenisation->tokeniser($ibanConfigA);
        $configRegie = new ConfigCreancierSepa();
        $configRegie->setEtablissement($etabA)
            ->setVariante(VarianteCreancierSepa::Regie)
            ->setIcs('FR00ZZZ000000')
            ->setCreancierNom('REGIE PISCINE A')
            ->setCreancierIbanToken($tokenIbanA->token)
            ->setCreancierIban4Derniers($tokenIbanA->quatreDerniers)
            ->setCreancierIbanChiffre($this->chiffreur->chiffrer($ibanConfigA))
            ->setCreancierBic('BDFEFRPPCCT')
            ->setCollectiviteNom('COLLECTIVITE DEMO / VILLE-MODELE')
            ->setUltimateCreancierNom('REGIE PISCINE A')
            ->setUltimateCreancierOrgId('00000000000000');
        $manager->persist($configRegie);

        // --- Configuration créancier PRIVÉ (établissement B — variante surchargée, pas de ProfilExploitant) ---
        $ibanConfigB = 'FR7600000000000000000000399';
        $tokenIbanB = $this->tokenisation->tokeniser($ibanConfigB);
        $configPrive = new ConfigCreancierSepa();
        $configPrive->setEtablissement($etabB)
            ->setVariante(VarianteCreancierSepa::Prive)
            ->setIcs('FR00ZZZ111111')
            ->setCreancierNom('PATINOIRE B PRIVEE')
            ->setCreancierIbanToken($tokenIbanB->token)
            ->setCreancierIban4Derniers($tokenIbanB->quatreDerniers)
            ->setCreancierIbanChiffre($this->chiffreur->chiffrer($ibanConfigB))
            ->setCreancierBic('CMCIFRPPXXX');
        $manager->persist($configPrive);

        // --- Mandats SEPA de démonstration (IBAN tokenisé + chiffré, données fictives) — 1 par variante ---
        $tokenMandatRegie = $this->tokenisation->tokeniser(self::REGIE_IBAN_DEMO);
        $mandatRegie = new MandatSepa();
        $mandatRegie->setRum('RUM-DEMO-REGIE-0001')
            ->setIbanToken($tokenMandatRegie->token)
            ->setIban4Derniers($tokenMandatRegie->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer(self::REGIE_IBAN_DEMO))
            ->setBicDebiteur('AGRIFRPPXXX')
            ->setDebiteurNom('Usager Démo Régie')
            ->setDateSignature(new \DateTimeImmutable('-1 month'))
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($payeur)
            ->setEtablissement($etabA);
        $manager->persist($mandatRegie);

        $tokenMandatPrive = $this->tokenisation->tokeniser(self::PRIVE_IBAN_DEMO);
        $mandatPrive = new MandatSepa();
        $mandatPrive->setRum('RUM-DEMO-PRIVE-0001')
            ->setIbanToken($tokenMandatPrive->token)
            ->setIban4Derniers($tokenMandatPrive->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer(self::PRIVE_IBAN_DEMO))
            ->setBicDebiteur('CCBPFRPPXXX')
            ->setDebiteurNom('Usager Démo Privé')
            ->setDateSignature(new \DateTimeImmutable('-1 month'))
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($payeur)
            ->setEtablissement($etabB);
        $manager->persist($mandatPrive);

        $manager->flush();

        // --- Remise RÉGIE de démonstration (preuve de réutilisation bi-régime, plan-sepa.md §5) ---
        // 2 échéances synthétiques sur le mandat régie (jamais collecté : FRST), même profil que
        // l'échantillon de référence `pain008-regie-FRST.xml`.
        $source = new class($mandatRegie) implements EcheanceSepaSource {
            public function __construct(private readonly MandatSepa $mandat)
            {
            }

            public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
            {
                return [
                    new EcheanceSepaDue(
                        referenceOrigine: 'demo-regie-1',
                        mandatId: $this->mandat->getId(),
                        montantCentimes: 6300,
                        libelle: 'Abonnement piscine démo 1',
                        dateEcheance: $dateExecution,
                        derniereEcheanceEngagement: false,
                        paiementUnique: false,
                    ),
                ];
            }

            public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
            {
                // Démonstration fixtures : aucun échéancier réel à mettre à jour côté verticale.
            }
        };

        // PAY-2 — le préavis, sans lequel la remise ne pourrait plus être générée.
        //
        // **La fixture énonce un fait passé, elle ne contourne pas le contrôle.** Elle affirme que ce
        // client a été prévenu vingt jours plus tôt, exactement comme elle affirme qu'il a signé un
        // mandat. `GenerationRemiseHandler` relit ce préavis et vérifie le montant et le délai comme
        // pour n'importe quelle échéance : ce qui est fabriqué, c'est l'envoi, pas la vérification.
        //
        // On l'écrit directement plutôt que de passer par `DebitPreNotifier::announce()` parce
        // qu'aucun prestataire d'envoi n'existe (D19) : `announce()` rendrait `Journalisee`, le jeu de
        // démonstration n'aurait plus aucune remise, et on aurait perdu l'écran au lieu de montrer le
        // blocage. Le blocage, lui, reste entier en production — c'est le chemin réel qui est barré.
        $this->preavisDemo($manager, $mandatRegie, 'demo-regie-1', 6300);

        $this->generationRemiseHandler->generer($etabA, new \DateTimeImmutable('today'), $source);
    }

    /**
     * Le préavis de démonstration, cherché avant d'être créé (D49).
     *
     * `uniq_prenotification_mandate_origin` refuserait un second chargement, et les fixtures se
     * rechargent : quatorze d'entre elles créaient aveuglément des objets à contrainte d'unicité, ce
     * qui a coûté une préproduction le 24/08.
     */
    private function preavisDemo(ObjectManager $manager, MandatSepa $mandat, string $reference, int $montantCentimes): void
    {
        $existant = $manager->getRepository(DebitPreNotification::class)->findOneBy([
            'mandate' => $mandat,
            'originReference' => $reference,
        ]);

        $preavis = $existant instanceof DebitPreNotification ? $existant : new DebitPreNotification();
        $preavis->setMandate($mandat)
            ->setOriginReference($reference)
            ->setAmountCents($montantCentimes)
            ->setAnnouncedDueDate(new \DateTimeImmutable('today'))
            ->setSentAt(new \DateTimeImmutable('today -20 days'))
            ->setReason(PreNotificationReason::Schedule)
            ->setOutcome(NotificationOutcome::Envoyee);

        $manager->persist($preavis);
        $manager->flush();
    }
}
