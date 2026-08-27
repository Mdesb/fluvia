<?php

declare(strict_types=1);

namespace App\Crm\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\ParametrePmvEtablissement;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\CanalMouvementPmv;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TypeClient;
use App\Crm\Enum\TypeMouvementPmv;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données M4 (L5) : permissions `crm.*` accordées à l'administrateur, un rôle « Agent CRM »
 * restreint (séparation des devoirs, §3 spec-crm.md), une famille (payeur + 2 bénéficiaires dont un
 * enfant mineur) avec un PMV alimenté sur l'établissement A (socle), et un second **Groupe** avec son
 * propre établissement/utilisateur pour les tests de cloisonnement (RG-SOCLE-05, spécificité Groupe
 * de M4, §6 plan-crm.md).
 */
final class CrmFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const PAYEUR_EMAIL = 'jean.dupont@example.test';
    public const ENFANT_PRENOM = 'Léo';
    public const CONJOINT_PRENOM = 'Marie';
    public const FAMILLE_LIBELLE = 'Famille Dupont';
    public const AGENT_EMAIL = 'agent.crm@itcotation.com';
    public const AGENT_MDP = 'aaa';
    public const GROUPE_B_NOM = 'Groupe Second Loisirs';
    public const ETAB_C_NOM = 'Musée C';
    public const AGENT_B_EMAIL = 'agent.groupeb@itcotation.com';
    public const AGENT_B_MDP = 'aaa';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $groupeA = $etabA instanceof Etablissement ? $etabA->getRegion()?->getGroupe() : null;

        // --- Permissions crm.* + octroi complet à l'administrateur (RG-SOCLE-02/03) ---
        $permCrmTout = (new Permission())->setModule('crm')->setAction('*');
        $manager->persist($permCrmTout);
        $actions = [
            'lire', 'lire_soi', 'creer', 'modifier', 'modifier_soi',
            'pmv_lire', 'pmv_lire_soi', 'pmv_recharger', 'pmv_recharger_soi',
            'famille_gerer', 'consentement_gerer_soi', 'rgpd_demander', 'rgpd_gerer',
            'fusionner', 'parametrer', 'exporter', 'segment_gerer',
        ];
        $permissions = [];
        foreach ($actions as $action) {
            $permissions[$action] = $this->permissionNommee($manager, 'crm', $action);
            $manager->persist($permissions[$action]);
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permCrmTout);
        }

        // --- Rôle « Agent CRM » restreint (séparation des devoirs, §3 spec-crm.md) : ni fusion, ni
        // RGPD, ni paramétrage — seulement lecture/création/modification/PMV recharge/famille.
        $roleAgent = (new Role())->setNom('Agent CRM');
        foreach (['lire', 'creer', 'modifier', 'pmv_lire', 'pmv_recharger', 'famille_gerer'] as $action) {
            $roleAgent->addPermission($permissions[$action]);
        }
        $manager->persist($roleAgent);

        $agent = (new Utilisateur())->setEmail(self::AGENT_EMAIL)->setNom('Agent Accueil CRM')->setActif(true);
        $agent->setMotDePasse($this->hasher->hashPassword($agent, self::AGENT_MDP));
        $manager->persist($agent);
        if ($etabA instanceof Etablissement) {
            $manager->persist((new Affectation())->setUtilisateur($agent)->setRole($roleAgent)->setEtablissement($etabA));
        }

        // --- Second Groupe + Établissement + utilisateur (cloisonnement Groupe, §6 plan-crm.md) ---
        $groupeB = (new Groupe())->setNom(self::GROUPE_B_NOM);
        $manager->persist($groupeB);
        $regionB = (new Region())->setNom('Région B')->setGroupe($groupeB);
        $manager->persist($regionB);
        $etabC = (new Etablissement())->setNom(self::ETAB_C_NOM)->setRegion($regionB)->setActif(true);
        $manager->persist($etabC);

        $roleAdminB = (new Role())->setNom('Administrateur groupe B');
        $roleAdminB->addPermission($permCrmTout);
        $manager->persist($roleAdminB);
        $agentB = (new Utilisateur())->setEmail(self::AGENT_B_EMAIL)->setNom('Agent Groupe B')->setActif(true);
        $agentB->setMotDePasse($this->hasher->hashPassword($agentB, self::AGENT_B_MDP));
        $manager->persist($agentB);
        $manager->persist((new Affectation())->setUtilisateur($agentB)->setRole($roleAdminB)->setEtablissement($etabC));

        // --- Paramètre PMV établissement A (US-L5-06/07, valeurs de repli explicites) ---
        if ($etabA instanceof Etablissement) {
            $parametre = (new ParametrePmvEtablissement())
                ->setEtablissement($etabA)
                ->setRechargeExpireeAutorisee(false)
                ->setRegleEcheance(['mode' => 'duree_jours', 'valeur' => 365])
                ->setTraitementSoldeResiduel(\App\Crm\Enum\TraitementSoldeResiduel::Conserve);
            $manager->persist($parametre);
        }

        if (!$etabA instanceof Etablissement || !$groupeA instanceof Groupe) {
            $manager->flush();

            return;
        }

        // --- Famille Dupont : payeur + 2 bénéficiaires dont un enfant mineur (US-L5-03) ---
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);

        $payeur = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupeA)
            ->setEtablissementCreation($etabA)
            ->setNom('Dupont')
            ->setPrenom('Jean')
            ->setEmail(self::PAYEUR_EMAIL)
            ->setTelephone('0601020304')
            ->setDateNaissance(new \DateTimeImmutable('-42 years'));
        $manager->persist($payeur);

        $enfant = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupeA)
            ->setEtablissementCreation($etabA)
            ->setNom('Dupont')
            ->setPrenom(self::ENFANT_PRENOM)
            ->setDateNaissance(new \DateTimeImmutable('-10 years'));
        $manager->persist($enfant);

        $conjoint = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupeA)
            ->setEtablissementCreation($etabA)
            ->setNom('Dupont')
            ->setPrenom(self::CONJOINT_PRENOM)
            ->setEmail('marie.dupont@example.test')
            ->setDateNaissance(new \DateTimeImmutable('-40 years'));
        $manager->persist($conjoint);

        $famille = (new Famille())->setGroupe($groupeA)->setLibelle(self::FAMILLE_LIBELLE)->setPayeurPrincipal($payeur);
        $manager->persist($famille);

        $bPayeur = (new Beneficiaire())->setFamille($famille)->setClient($payeur)->setRole(RoleBeneficiaire::Payeur)
            ->setAutorisations(['recharger_pmv', 'acheter_pour_famille']);
        $bEnfant = (new Beneficiaire())->setFamille($famille)->setClient($enfant)->setRole(RoleBeneficiaire::Beneficiaire)
            ->setAutorisations(['entree_seule']);
        $bConjoint = (new Beneficiaire())->setFamille($famille)->setClient($conjoint)->setRole(RoleBeneficiaire::Beneficiaire)
            ->setAutorisations(['recuperer_mineur']);
        foreach ([$bPayeur, $bEnfant, $bConjoint] as $b) {
            $famille->addBeneficiaire($b);
            $manager->persist($b);
        }

        // --- PMV alimenté pour le payeur (US-L5-04) ---
        $pmv = (new PorteMonnaieVirtuel())
            ->setClient($payeur)
            ->setSolde('50.00')
            ->setStatut(StatutPmv::Actif)
            ->setDateEcheance(new \DateTimeImmutable('+1 year'));
        $manager->persist($pmv);

        $mouvement = new MouvementPmv(TypeMouvementPmv::Recharge);
        $mouvement->setPmv($pmv);
        $mouvement->setMontant('50.00');
        $mouvement->setSoldeApres('50.00');
        $mouvement->setCanal(CanalMouvementPmv::Caisse);
        $mouvement->setEtablissement($etabA);
        if ($admin instanceof Utilisateur) {
            $mouvement->setUtilisateur($admin);
        }
        $manager->persist($mouvement);

        $manager->flush();
    }
}
