<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Unit;

use App\Tests\SchemaDuHarnais;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\Service\ResolveurLimiteAutorisation;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `ResolveurLimiteAutorisation::resoudre()` (RG-AUTZ-03/12) : priorité utilisateur > rôle, la plus
 * restrictive entre plusieurs limites de rôle (plafond puis périmètre), héritage via délégation
 * temporaire active.
 */
final class ResolveurLimiteAutorisationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ResolveurLimiteAutorisation $resolveur;
    private Etablissement $etablissement;
    private OperationSensible $operation;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        $container->get(SocleFixtures::class)->load($em);

        /** @var ResolveurLimiteAutorisation $resolveur */
        $resolveur = $container->get(ResolveurLimiteAutorisation::class);
        $this->resolveur = $resolveur;

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $this->etablissement = $etab;

        $operation = (new OperationSensible())->setCode('vente.annuler')->setLibelle('Annulation')->setModuleAction('vente.annuler')->setActive(true);
        $em->persist($operation);
        $em->flush();
        $this->operation = $operation;
    }

    public function testAucuneLimiteRetourneNull(): void
    {
        $utilisateur = $this->creerUtilisateur('sans-limite@test.itcotation.com');
        $decision = $this->resolveur->resoudre($this->operation, $utilisateur, $this->etablissement->getId());
        self::assertNull($decision);
    }

    public function testLimiteUtilisateurPrevautSurLimiteDeRole(): void
    {
        $role = $this->creerRole('Role Priorite Test');
        $utilisateur = $this->creerUtilisateur('priorite@test.itcotation.com', $role);
        $admin = $this->admin();

        $this->creerLimite($role, null, '50.00', PerimetreAutorisation::Global);
        $limiteUtilisateur = $this->creerLimite(null, $utilisateur, '999.00', PerimetreAutorisation::Global);

        $resultat = $this->resolveur->resoudre($this->operation, $utilisateur, $this->etablissement->getId());
        self::assertSame($limiteUtilisateur->getId()->toRfc4122(), $resultat?->getId()->toRfc4122());
        self::assertSame('999.00', $resultat?->getPlafondMontant());
        unset($admin);
    }

    public function testEntreDeuxLimitesDeRoleLaPlusRestrictiveLemporte(): void
    {
        $roleA = $this->creerRole('Role Restrictif A');
        $roleB = $this->creerRole('Role Restrictif B');
        $utilisateur = $this->creerUtilisateur('deux-roles@test.itcotation.com');
        $this->affecter($utilisateur, $roleA);
        $this->affecter($utilisateur, $roleB);

        $this->creerLimite($roleA, null, '300.00', PerimetreAutorisation::Global);
        $limiteRestrictive = $this->creerLimite($roleB, null, '80.00', PerimetreAutorisation::PropreSession);

        $resultat = $this->resolveur->resoudre($this->operation, $utilisateur, $this->etablissement->getId());
        self::assertSame($limiteRestrictive->getId()->toRfc4122(), $resultat?->getId()->toRfc4122());
        self::assertSame('80.00', $resultat?->getPlafondMontant());
    }

    public function testDelegationActiveHeriteDesLimitesDuRoleDelegue(): void
    {
        $roleDelegue = $this->creerRole('Role Delegue Test');
        $beneficiaire = $this->creerUtilisateur('beneficiaire-delegation@test.itcotation.com');
        $delegant = $this->creerUtilisateur('delegant@test.itcotation.com', $roleDelegue);

        $limite = $this->creerLimite($roleDelegue, null, '150.00', PerimetreAutorisation::Global);

        $delegation = (new DelegationDroit())
            ->setDelegant($delegant)
            ->setBeneficiaire($beneficiaire)
            ->setRole($roleDelegue)
            ->setEtablissement($this->etablissement)
            ->setDateDebut(new \DateTimeImmutable('-1 hour'))
            ->setDateFin(new \DateTimeImmutable('+1 hour'))
            ->setStatut(StatutDelegation::Active);
        $this->em->persist($delegation);
        $this->em->flush();

        $resultat = $this->resolveur->resoudre($this->operation, $beneficiaire, $this->etablissement->getId());
        self::assertSame($limite->getId()->toRfc4122(), $resultat?->getId()->toRfc4122());
    }

    private function creerLimite(?Role $role, ?Utilisateur $utilisateur, string $plafond, PerimetreAutorisation $perimetre): LimiteAutorisation
    {
        $limite = (new LimiteAutorisation())
            ->setOperation($this->operation)
            ->setRole($role)
            ->setUtilisateur($utilisateur)
            ->setEtablissement($this->etablissement)
            ->setPlafondMontant($plafond)
            ->setPerimetre($perimetre)
            ->setEscaladeAuDela(false)
            ->setAuteur($this->admin());
        $this->em->persist($limite);
        $this->em->flush();

        return $limite;
    }

    private function creerRole(string $nom): Role
    {
        $role = (new Role())->setNom($nom);
        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }

    private function creerUtilisateur(string $email, ?Role $role = null): Utilisateur
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($email)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, 'aaa'));
        $this->em->persist($utilisateur);
        $this->em->flush();

        if ($role !== null) {
            $this->affecter($utilisateur, $role);
        }

        return $utilisateur;
    }

    private function affecter(Utilisateur $utilisateur, Role $role): void
    {
        $this->em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($this->etablissement));
        $this->em->flush();
    }

    private function admin(): Utilisateur
    {
        $admin = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);

        return $admin;
    }
}
