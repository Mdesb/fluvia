<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Securite\Enum\AccountKind;
use App\Securite\Service\PasswordPolicy;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\Vitrine;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création de compte client final (US-L8-04, RG-M3-10). Crée `Utilisateur` (socle) + `Client` (M4,
 * rattachement `clientLie`) + `CompteClient` (satellite) + `Affectation(RoleClientFinal)` (§0
 * décision n°3 du plan — sans ce rattachement, aucune permission `_soi` ne serait accordée).
 * **Aucune fusion automatique** (RG-M3-10, CA-5) : un compte est toujours une fiche `Client`
 * nouvelle ; le contrôle anti-homonyme via `dateNaissance` se traduit par l'absence de recherche de
 * fiche existante, jamais par une fusion.
 */
final class CreationCompteHandler
{
    public const ROLE_CLIENT_FINAL_NOM = 'Client final';

    /** @var list<string> Bundle de permissions `_soi` du rôle système RoleClientFinal (§1.4 du plan). */
    private const PERMISSIONS_CLIENT_FINAL = [
        ['crm', 'lire_soi'],
        ['crm', 'modifier_soi'],
        ['crm', 'pmv_lire_soi'],
        ['crm', 'pmv_recharger_soi'],
        ['crm', 'consentement_gerer_soi'],
        ['reservation', 'reserver_soi'],
        ['reservation', 'lire_soi'],
        ['reservation', 'annuler_soi'],
        ['boutique', 'acheter_soi'],
        ['boutique', 'lire_soi'],
        ['boutique', 'gerer_famille_soi'],
        ['boutique', 'demander_remboursement_soi'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $passwords,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees {email, motDePasse, civilite?, nom, prenom, dateNaissance,
     *                                        societeOuAssociation?}
     */
    public function creer(array $donnees, Vitrine $vitrine): CompteClient
    {
        $email = \is_string($donnees['email'] ?? null) ? trim($donnees['email']) : '';
        $motDePasse = \is_string($donnees['motDePasse'] ?? null) ? $donnees['motDePasse'] : '';
        $nom = \is_string($donnees['nom'] ?? null) ? trim($donnees['nom']) : '';
        $prenom = \is_string($donnees['prenom'] ?? null) ? trim($donnees['prenom']) : null;
        $dateNaissanceStr = \is_string($donnees['dateNaissance'] ?? null) ? $donnees['dateNaissance'] : null;

        if ($email === '' || $motDePasse === '' || $nom === '' || $dateNaissanceStr === null) {
            throw new UnprocessableEntityHttpException('« email », « motDePasse », « nom » et « dateNaissance » sont requis (RG-M3-10).');
        }
        // Audit du 06/09, constat 7 : cette porte acceptait « aaa » (vérifié en préproduction, 201).
        $this->passwords->assertAcceptable($motDePasse, $email);

        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if ($existant instanceof Utilisateur) {
            throw new ConflictHttpException('Un compte existe déjà avec cette adresse e-mail.');
        }

        $etablissement = $vitrine->getEtablissement();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Vitrine sans établissement rattaché.');
        }

        // RG-M3-10 (CA-5) : aucune recherche/fusion automatique par nom+prénom — une nouvelle fiche
        // Client est systématiquement créée, même en cas d'homonymie (seule la date de naissance
        // distingue deux comptes candidats, jamais fusionnés).
        $client = new Client();
        $client->setType(TypeClient::Physique)
            ->setCivilite(\is_string($donnees['civilite'] ?? null) ? $donnees['civilite'] : null)
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail($email)
            ->setDateNaissance(new \DateTimeImmutable($dateNaissanceStr))
            ->setStatut(StatutClient::Actif)
            ->setEtablissementCreation($etablissement)
            ->setGroupe($etablissement->getRegion()?->getGroupe());
        $this->em->persist($client);

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail($email)
            ->setNom(trim(($prenom ?? '') . ' ' . $nom))
            ->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse))
            ->setStatut(StatutUtilisateur::Actif)
            ->setRolesSecurite(['ROLE_USER'])
            // Audit du 06/09, constat 4 : un compte né ici est un client final, et le reste.
            ->setKind(AccountKind::Customer)
            ->setClientLie($client->getId());
        $this->em->persist($utilisateur);

        $compte = new CompteClient();
        $compte->setUtilisateur($utilisateur)
            ->setClient($client)
            ->setVitrineCreation($vitrine)
            ->setEtablissement($etablissement);
        $this->em->persist($compte);

        $role = $this->roleClientFinal();
        $affectation = new Affectation();
        $affectation->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement);
        $this->em->persist($affectation);

        $this->em->flush();

        return $compte;
    }

    /** Trouve ou crée le rôle système RoleClientFinal (idempotent, §1.4/T2 du plan). */
    private function roleClientFinal(): Role
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['nom' => self::ROLE_CLIENT_FINAL_NOM]);
        if ($role instanceof Role) {
            return $role;
        }

        $role = new Role();
        $role->setNom(self::ROLE_CLIENT_FINAL_NOM)->setEstModele(false);
        foreach (self::PERMISSIONS_CLIENT_FINAL as [$module, $action]) {
            $permission = $this->em->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action]);
            if (!$permission instanceof Permission) {
                $permission = new Permission();
                $permission->setModule($module)->setAction($action);
                $this->em->persist($permission);
            }
            $role->addPermission($permission);
        }
        $this->em->persist($role);

        return $role;
    }
}
