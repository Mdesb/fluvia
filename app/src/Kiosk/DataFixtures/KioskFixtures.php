<?php

declare(strict_types=1);

namespace App\Kiosk\DataFixtures;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Service\KioskSecrets;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Securite\Entity\Role;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données des bornes (#341) : les permissions `kiosk.read` / `kiosk.manage` données à
 * l'administrateur, une borne « Borne du hall » sur la piscine A avec son PROPRE point de vente (terminal
 * simulé, carte bancaire seule), et une borne sur la patinoire B — TÉMOIN de cloisonnement, qu'aucun
 * appareil ni aucun agent de A ne doit atteindre.
 *
 * L'entrée et la carte multi-entrées reçoivent le canal « borne » À LA CRÉATION de la borne seulement :
 * un rechargement ne remet pas un canal qu'on aurait retiré à la main en préproduction.
 */
final class KioskFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const KIOSK_A_LABEL = 'Borne du hall';
    public const KIOSK_B_LABEL = 'Borne patinoire';
    public const POS_A_LABEL = 'Borne du hall — Piscine A';
    public const POS_B_LABEL = 'Borne — Patinoire B';
    /** Code de sortie du personnel des bornes de démonstration (valeur de test, comme les mots de passe des fixtures). */
    public const EXIT_CODE = '2468';

    public function __construct(
        private readonly KioskSecrets $secrets,
    ) {
    }

    /** @return array<int, class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class, VenteFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Les droits sont rejoués à chaque chargement : un droit ajouté au code doit atteindre une base existante.
        $read = $this->permissionNommee($manager, 'kiosk', 'read');
        $manage = $this->permissionNommee($manager, 'kiosk', 'manage');
        $admin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($admin instanceof Role) {
            $admin->addPermission($read)->addPermission($manage);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement
            || $manager->getRepository(Kiosk::class)->findOneBy(['label' => self::KIOSK_A_LABEL]) !== null) {
            $manager->flush();

            return;
        }

        $this->kiosk($manager, $etabA, self::POS_A_LABEL, self::KIOSK_A_LABEL, 'TPE-BORNE-A1');
        if ($etabB instanceof Etablissement) {
            $this->kiosk($manager, $etabB, self::POS_B_LABEL, self::KIOSK_B_LABEL, 'TPE-BORNE-B1');
        }

        foreach ([OffreFixtures::PRODUIT_ENTREE, OffreFixtures::PRODUIT_CARTE] as $label) {
            $product = $manager->getRepository(Produit::class)->findOneBy(['libelleRecherche' => $label]);
            if ($product instanceof Produit && !\in_array('borne', $product->getCanaux(), true)) {
                $product->setCanaux([...$product->getCanaux(), 'borne']);
            }
        }

        $manager->flush();
    }

    private function kiosk(ObjectManager $manager, Etablissement $site, string $posLabel, string $label, string $terminalRef): void
    {
        $pos = $manager->getRepository(PointDeVente::class)->findOneBy(['libelle' => $posLabel, 'etablissement' => $site]);
        if (!$pos instanceof PointDeVente) {
            $pos = (new PointDeVente())
                ->setLibelle($posLabel)
                ->setEtablissement($site)
                ->setMoyensAutorises(['cb'])
                // Le terminal simulé : refusé hors test et démonstration (`TPE_SIMULE_AUTORISE`).
                ->setTpe(['connecteur' => 'simule', 'marque' => 'ingenico', 'ref' => $terminalRef]);
            $manager->persist($pos);
        }

        $manager->persist((new Kiosk())
            ->setEtablissement($site)
            ->setPointOfSale($pos)
            ->setLabel($label)
            ->setExitCodeHash($this->secrets->hashExitCode(self::EXIT_CODE)));
    }
}
