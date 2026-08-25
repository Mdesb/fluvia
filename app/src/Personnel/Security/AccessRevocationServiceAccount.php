<?php

declare(strict_types=1);

namespace App\Personnel\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le compte qui trace les révocations de badge faites sans personne devant l'écran.
 *
 * **Pourquoi il fallait le créer.** `personnel:traiter-echeances-sortie` exige l'identité de l'agent
 * qui révoque — `DeclarationPerteVol.agent` est non-nullable côté `Acces`, et c'est ce qui rend la
 * révocation opposable. Faute de ce compte, la commande ne pouvait pas tourner sans surveillance : je
 * l'avais retirée du catalogue de l'ordonnanceur le 25/08 au matin pour cette raison. Conséquence
 * pendant ce temps : **un salarié dont le contrat est fini garde ses accès**, indéfiniment et sans que
 * rien ne le signale.
 *
 * Son en-tête l'avait prévu — *« compte technique dédié pour l'exécution planifiée »* — il n'avait
 * simplement jamais été créé. C'est le motif de la semaine : le mécanisme existe, il n'est pas branché.
 *
 * **Il reprend le patron posé par `claude-D` pour la facturation** (`BillingServiceAccount`), et pour
 * les mêmes raisons :
 *
 * 1. **Un nom qui se lit** — « Révocation automatique des accès » — et non « système ». Quelqu'un qui
 *    relit un journal d'audit doit comprendre du premier coup qu'un traitement a révoqué ce badge, et
 *    non un collègue qui aurait outrepassé ses droits.
 * 2. **Personne ne peut s'y connecter, et c'est structurel.** Le compte est créé `Suspendu` :
 *    `VerificateurJwtActifListener` refuse tout jeton d'un compte non actif. Ce n'est pas une
 *    convention, c'est un contrôle qui existait déjà — on le branche, on ne le réécrit pas.
 * 3. **Son mot de passe est un aléa que personne ne conserve**, moi compris.
 *
 * **Un compte distinct de celui de la facturation, à dessein.** Deux traitements automatiques, deux
 * identités : le jour où l'on veut suspendre l'un sans l'autre, ou savoir lequel a agi, la question ne
 * se pose pas.
 */
final readonly class AccessRevocationServiceAccount
{
    public const NOM = 'Révocation automatique des accès';
    public const EMAIL = 'revocation-automatique@personnel.invalid';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function compte(): Utilisateur
    {
        $existant = $this->entityManager->getRepository(Utilisateur::class)
            ->findOneBy(['email' => self::EMAIL]);

        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $compte = new Utilisateur();
        $compte->setEmail(self::EMAIL)
            ->setNom(self::NOM)
            ->setStatut(StatutUtilisateur::Suspendu)
            ->setMotDePasse($this->hasher->hashPassword($compte, bin2hex(random_bytes(32))));

        $this->entityManager->persist($compte);
        $this->entityManager->flush();

        return $compte;
    }
}
