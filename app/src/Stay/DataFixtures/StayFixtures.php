<?php

declare(strict_types=1);

namespace App\Stay\DataFixtures;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du module `App\Stay` (ACT-3) : les permissions `stay.*` accordées à l'administrateur
 * socle, et **un séjour ouvert dans CHACUN des deux établissements**.
 *
 * Le séjour de l'établissement B n'est pas décoratif : c'est le témoin du test de cloisonnement. Sans
 * une donnée réelle hors périmètre, un test de non-régression d'IDOR passe au vert pour la mauvaise
 * raison — il ne prouve alors que l'absence de donnée, pas la présence d'un filtre.
 */
final class StayFixtures extends Fixture implements DependentFixtureInterface
{
    public const REFERENCE_A = 'SEJ-TEST-A';
    public const REFERENCE_B = 'SEJ-TEST-B';

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions stay.* + octroi à l'administrateur socle (RG-SOCLE-02/03) ---
        //
        // **On réutilise la permission si elle existe déjà.** Depuis `Version20260825235100`, les
        // mêmes lignes sont posées par migration — c'est elles qui comptent chez un client, les
        // fixtures ne tournant que pour la démonstration et les tests. Recréer aveuglément violerait
        // `uniq_permission_module_action` sur toute base ayant reçu les deux.
        $permissions = [];
        foreach (['read', 'write', 'charge', 'settle'] as $action) {
            $permission = $manager->getRepository(Permission::class)->findOneBy(['module' => 'stay', 'action' => $action]);
            if (!$permission instanceof Permission) {
                $permission = (new Permission())->setModule('stay')->setAction($action);
                $manager->persist($permission);
            }
            $permissions[] = $permission;
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            foreach ($permissions as $permission) {
                $roleAdmin->addPermission($permission);
            }
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement) {
            $manager->flush();

            return;
        }

        $sejourA = $this->sejour($manager, $etabA, self::REFERENCE_A, 'Martin', 'Claire');
        $this->sejour($manager, $etabB, self::REFERENCE_B, 'Nowak', 'Piotr');

        // Une ligne sur le séjour de A, pour que la note ne soit pas vide : un solde de zéro ne
        // distingue pas « rien consommé » de « lignes invisibles ».
        if ($sejourA instanceof Stay) {
            $manager->persist(new StayCharge(
                $sejourA,
                'Bar - 2 demis',
                '9.00',
                new \DateTimeImmutable('2026-08-25 19:00:00'),
                'manual',
                'manual.entry',
                'fixture-charge-a',
            ));
        }

        $manager->flush();
    }

    private function sejour(
        ObjectManager $manager,
        Etablissement $etablissement,
        string $reference,
        string $nom,
        string $prenom,
    ): ?Stay {
        $groupe = $etablissement->getRegion()?->getGroupe();
        if (null === $groupe) {
            return null;
        }

        $client = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupe)
            ->setEtablissementCreation($etablissement)
            ->setNom($nom)
            ->setPrenom($prenom);
        $manager->persist($client);

        $sejour = new Stay(
            $etablissement,
            $client,
            $reference,
            new \DateTimeImmutable('2026-08-24'),
            new \DateTimeImmutable('2026-08-24 15:00:00'),
        );
        $manager->persist($sejour);

        return $sejour;
    }
}
