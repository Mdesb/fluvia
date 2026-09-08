<?php

declare(strict_types=1);

namespace App\Group\DataFixtures;

use App\Compta\Entity\TauxTva;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupParticipant;
use App\Group\Entity\GroupProduct;
use App\Group\Entity\GroupProductLine;
use App\Group\Enum\GroupType;
use App\Group\Enum\ParticipantCategory;
use App\Group\Entity\ParticipantGroup;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données du module transverse `App\Group` : permissions `group.read` / `group.manage`, un rôle
 * « Gestionnaire de groupes » et son utilisateur (sur l'établissement A), un groupe de démonstration
 * (avec sa liste nominative) et une réservation de groupe en option — plus un groupe sur
 * l'établissement B, **témoin de cloisonnement** qu'un gestionnaire de A ne doit jamais voir.
 */
final class GroupFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const GESTIONNAIRE_EMAIL = 'gestionnaire.groupes@itcotation.com';
    public const GESTIONNAIRE_MDP = 'aaa';

    public const GROUPE_A_LABEL = 'École Jean Moulin CM2';
    public const GROUPE_B_LABEL = 'Comité d\'entreprise Nord';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /** @return array<int, class-string> */
    public function getDependencies(): array
    {
        // ReservationFixtures fournit Activités/Créneaux, CrmFixtures les clients, OffreFixtures les
        // produits du catalogue et (transitivement) ComptaFixtures les taux de TVA du forfait démo.
        return [SocleFixtures::class, CrmFixtures::class, OffreFixtures::class, ReservationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etabA instanceof Etablissement) {
            $manager->flush();

            return;
        }
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);

        // --- Permissions group.* (RG-SOCLE-02) ---
        $permRead = $this->permissionNommee($manager, 'group', 'read');
        $permManage = $this->permissionNommee($manager, 'group', 'manage');

        // L'administrateur les reçoit explicitement : son joker `*.lire` ne couvre pas des actions
        // anglaises (D5) — « un droit accordé par coïncidence de vocabulaire est un droit que personne
        // n'a décidé » (SocleFixtures).
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permRead)->addPermission($permManage);
        }

        // --- Rôle « Gestionnaire de groupes » + utilisateur sur l'établissement A ---
        $roleGestionnaire = $this->roleNomme($manager, 'Gestionnaire de groupes');
        $roleGestionnaire->addPermission($permRead)->addPermission($permManage);
        $gestionnaire = $this->utilisateurParEmail(
            $manager,
            self::GESTIONNAIRE_EMAIL,
            'Gestionnaire Groupes',
            fn (Utilisateur $u): string => $this->hasher->hashPassword($u, self::GESTIONNAIRE_MDP),
        );
        $this->affectationUnique($manager, $gestionnaire, $roleGestionnaire, $etabA);

        // --- Données de démonstration : posées une seule fois (aucune unicité sur ces tables) ---
        if ($manager->getRepository(ParticipantGroup::class)->findOneBy(['label' => self::GROUPE_A_LABEL]) !== null) {
            $manager->flush();

            return;
        }

        // Payeur de démonstration : le client CRM « Jean Dupont ». Permet de facturer (générer un
        // devis) une réservation de ce groupe sans configuration préalable.
        $payeur = $manager->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);

        $groupeA = (new ParticipantGroup())
            ->setEtablissement($etabA)
            ->setLabel(self::GROUPE_A_LABEL)
            ->setType(GroupType::School)
            ->setOrganizerName('Mme Martin')
            ->setOrganizerEmail('martin@ecole-jean-moulin.fr')
            ->setOrganizerPhone('0102030405')
            ->setHeadcount(28)
            ->setClient($payeur instanceof Client ? $payeur : null);
        $manager->persist($groupeA);

        $manager->persist((new GroupParticipant())->setGroup($groupeA)
            ->setFirstName('Léa')->setLastName('Bernard')->setCategory(ParticipantCategory::Child));
        $manager->persist((new GroupParticipant())->setGroup($groupeA)
            ->setFirstName('Paul')->setLastName('Durand')->setCategory(ParticipantCategory::Accompanist));

        $manager->persist((new GroupBooking())
            ->setGroup($groupeA)
            ->setEtablissement($etabA)
            ->setEffectif(28)
            ->setAccompagnateurs(2));

        // Forfait groupe de démonstration : un « produit groupe » composite (ici une ligne, faute
        // d'un second produit en fixtures). On l'applique à une réservation pour la facturer en
        // lignes de devis.
        $produit = $manager->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        $taux20 = $manager->getRepository(TauxTva::class)->findOneBy(['taux' => '20.00', 'actif' => true]);
        if ($produit instanceof Produit && $taux20 instanceof TauxTva) {
            $forfait = (new GroupProduct())->setEtablissement($etabA)->setLabel('Forfait scolaire');
            $forfait->addLine((new GroupProductLine())
                ->setProduit($produit)->setQuantite(3)->setPrixUnitaireHT('10.00')->setTauxTva($taux20));
            $manager->persist($forfait);
        }

        if ($etabB instanceof Etablissement) {
            $manager->persist((new ParticipantGroup())
                ->setEtablissement($etabB)
                ->setLabel(self::GROUPE_B_LABEL)
                ->setType(GroupType::WorksCouncil)
                ->setOrganizerName('M. Lefèvre')
                ->setHeadcount(40));
        }

        $manager->flush();
    }
}
