<?php

declare(strict_types=1);

namespace App\Ocr\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\DataFixtures\SocleFixtures;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Entity\OcrProviderConfig;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Enum\OcrProvider;
use App\Ocr\Service\ChiffreurApiKeyOcr;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du service transverse `App\Ocr` (plan-ocr.md T4) : permissions `ocr.configure`/
 * `ocr.read_extraction` accordées à l'administrateur socle, une configuration `manual` (établissement
 * A, défaut RG-OCR-06) et une configuration `anthropic` avec clé API de démonstration chiffrée
 * (établissement B, CA-6 : preuve qu'elle n'est jamais exposée en clair), et 2 `ExtractionAttempt` de
 * démonstration (succès + faible confiance) pour couvrir les filtres API.
 */
final class OcrFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const DEMO_API_KEY = 'sk-ant-demo-0000000000000000000000';

    public function __construct(
        private readonly ChiffreurApiKeyOcr $chiffreur,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions ocr.* + octroi à l'administrateur socle (RG-SOCLE-02/03) ---
        $permConfigurer = $this->permissionNommee($manager, 'ocr', 'configure');
        $permLireExtraction = $this->permissionNommee($manager, 'ocr', 'read_extraction');
        $manager->persist($permConfigurer);
        $manager->persist($permLireExtraction);

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permConfigurer)->addPermission($permLireExtraction);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement) {
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
        if ($manager->getRepository(OcrProviderConfig::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }

        // --- Configuration MANUAL de démonstration (établissement A, défaut RG-OCR-06) ---
        $configA = new OcrProviderConfig();
        $configA->setEstablishment($etabA)
            ->setProvider(OcrProvider::Manual)
            ->setConfidenceThreshold('0.70');
        $manager->persist($configA);

        // --- Configuration ANTHROPIC de démonstration (établissement B, clé API chiffrée — CA-6) ---
        $configB = new OcrProviderConfig();
        $configB->setEstablishment($etabB)
            ->setProvider(OcrProvider::Anthropic)
            ->setApiKeyEncrypted($this->chiffreur->chiffrer(self::DEMO_API_KEY))
            ->setConfidenceThreshold('0.80');
        $manager->persist($configB);

        $manager->flush();

        // --- Tentatives d'extraction de démonstration (RG-OCR-04, filtres documentKind/status) ---
        $tentativeSucces = new ExtractionAttempt();
        $tentativeSucces->setEstablishment($etabA)
            ->setDocumentKind(DocumentKind::SupplierInvoice)
            ->setProvider(OcrProvider::Manual->value)
            ->setStatus(ExtractionStatus::Failed)
            ->setExtractedFields(['note' => 'demo-fixture'])
            ->setRequestedAt(new \DateTimeImmutable('-2 days'))
            ->setRequestedBy($admin instanceof Utilisateur ? $admin : null);
        $manager->persist($tentativeSucces);

        $tentativeFaibleConfiance = new ExtractionAttempt();
        $tentativeFaibleConfiance->setEstablishment($etabB)
            ->setDocumentKind(DocumentKind::ExpenseReceipt)
            ->setProvider(OcrProvider::Anthropic->value)
            ->setStatus(ExtractionStatus::LowConfidence)
            ->setConfidenceScore(0.42)
            ->setExtractedFields(['note' => 'demo-fixture', 'confidenceScore' => 0.42])
            ->setRequestedAt(new \DateTimeImmutable('-1 day'))
            ->setRequestedBy($admin instanceof Utilisateur ? $admin : null);
        $manager->persist($tentativeFaibleConfiance);

        $manager->flush();
    }
}
