<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Unit;

use App\Tests\SchemaDuHarnais;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\ResultatDecision;
use App\Autorisation\Enum\StatutEscalade;
use App\Autorisation\Service\RequeteAutorisation;
use App\Autorisation\Service\ServiceAutorisation;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `ServiceAutorisation::evaluerRejeu()` (§2.4 plan, RG-AUTZ-06) : jeton inconnu, demande non
 * approuvée, jeton déjà utilisé (409, §0 n°6), divergence cible/montant/auteur entre la demande
 * approuvée et la requête rejouée.
 */
final class ServiceAutorisationRejeuTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ServiceAutorisation $service;
    private Etablissement $etablissement;
    private Utilisateur $caissier;
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

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $this->etablissement = $etab;

        $permVenteAnnuler = (new Permission())->setModule('vente')->setAction('annuler');
        $em->persist($permVenteAnnuler);
        $role = (new Role())->setNom('Caissier Rejeu Test');
        $role->addPermission($permVenteAnnuler);
        $em->persist($role);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $caissier = (new Utilisateur())->setEmail('rejeu@test.itcotation.com')->setNom('Rejeu')->setActif(true);
        $caissier->setMotDePasse($hasher->hashPassword($caissier, 'aaa'));
        $em->persist($caissier);
        $em->persist((new Affectation())->setUtilisateur($caissier)->setRole($role)->setEtablissement($this->etablissement));

        $operation = (new OperationSensible())->setCode('vente.annuler')->setLibelle('Annulation')->setModuleAction('vente.annuler')->setActive(true);
        $em->persist($operation);
        $em->flush();
        $this->operation = $operation;
        $this->caissier = $caissier;

        // NB : le chemin `evaluerRejeu()` testé ici ne passe jamais par le contrôle binaire
        // `is_granted()` (bypass explicite dès que `jetonRejeu !== null`, §2.2 plan) — aucune
        // authentification de token nécessaire pour ces scénarios.
        /** @var ServiceAutorisation $service */
        $service = $container->get(ServiceAutorisation::class);
        $this->service = $service;
    }

    public function testJetonInconnuRefuse(): void
    {
        $decision = $this->service->evaluer($this->requete(jetonRejeu: Uuid::v4()));
        self::assertSame(ResultatDecision::Refuse, $decision->resultat);
    }

    public function testJetonNonApprouveRefuse(): void
    {
        $demande = $this->creerDemande(StatutEscalade::EnAttente);

        $decision = $this->service->evaluer($this->requete(jetonRejeu: $demande->getJeton(), montant: $demande->getMontant()));
        self::assertSame(ResultatDecision::Refuse, $decision->resultat);
    }

    public function testJetonDejaUtiliseRefuse409(): void
    {
        $demande = $this->creerDemande(StatutEscalade::Approuvee);
        $demande->setDateRejeu(new \DateTimeImmutable());
        $this->em->flush();

        $this->expectException(ConflictHttpException::class);
        $this->service->evaluer($this->requete(jetonRejeu: $demande->getJeton(), montant: $demande->getMontant()));
    }

    public function testDivergenceMontantRefuse(): void
    {
        $demande = $this->creerDemande(StatutEscalade::Approuvee, montant: '250.00');

        $decision = $this->service->evaluer($this->requete(jetonRejeu: $demande->getJeton(), montant: '50.00'));
        self::assertSame(ResultatDecision::Refuse, $decision->resultat);
    }

    public function testRejeuValideAutoriseSansRecomparaisonAuPlafond(): void
    {
        $demande = $this->creerDemande(StatutEscalade::Approuvee, montant: '999999.99');

        $decision = $this->service->evaluer($this->requete(jetonRejeu: $demande->getJeton(), montant: '999999.99'));
        self::assertSame(ResultatDecision::Autorise, $decision->resultat);
        self::assertNotNull($demande->getDateRejeu());
    }

    private function creerDemande(StatutEscalade $statut, string $montant = '250.00'): DemandeEscalade
    {
        $demande = new DemandeEscalade();
        $demande->setOperation($this->operation)
            ->setCibleType('Vente')
            ->setCibleId('11111111-1111-1111-1111-111111111111')
            ->setMontant($montant)
            ->setAuteur($this->caissier)
            ->setEtablissement($this->etablissement)
            ->setStatut($statut)
            ->setDateExpiration(new \DateTimeImmutable('+15 minutes'));
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    private function requete(Uuid $jetonRejeu, string $montant = '250.00'): RequeteAutorisation
    {
        return new RequeteAutorisation(
            operationCode: 'vente.annuler',
            utilisateur: $this->caissier,
            montant: $montant,
            cibleType: 'Vente',
            cibleId: Uuid::fromString('11111111-1111-1111-1111-111111111111'),
            cibleEtablissementId: $this->etablissement->getId(),
            jetonRejeu: $jetonRejeu,
        );
    }
}
