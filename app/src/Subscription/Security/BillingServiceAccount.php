<?php

declare(strict_types=1);

namespace App\Subscription\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le compte au nom duquel les factures d'abonnement sont émises automatiquement (ED-7).
 *
 * **Arbitré par Maxime, avec trois conditions.** Une facture NF525 porte le nom de qui l'a émise, et
 * une tâche périodique n'a personne derrière elle. Plutôt que d'attribuer des documents opposables à
 * un compte humain qui n'a rien fait, on nomme la machine — et on la nomme pour être lue par un
 * client, pas par un développeur.
 *
 * **1. Une identité réelle et nommée.** « Facturation automatique », jamais « système » ni « admin ».
 * Quelqu'un qui lit sa facture doit comprendre du premier coup qu'elle vient d'un traitement, et non
 * d'un humain qui aurait mal fait son travail.
 *
 * **2. Personne ne peut s'y connecter, et c'est structurel.** Le compte est créé `Suspendu` : le
 * contrôle qui refuse un jeton d'un compte non actif existe déjà ({@see \App\Securite\Security\VerificateurJwtActifListener}),
 * et il ne dépend d'aucune vigilance de notre part. Son mot de passe est un aléa qu'on ne conserve
 * nulle part, donc inconnu de tous, y compris de nous. Un compte de service dont on peut emprunter
 * l'identité vaut pire que rien : il devient l'endroit où l'on masque ce qu'on ne veut pas signer.
 *
 * **3. Le contrôle se déplace, il ne disparaît pas.** L'écran des règlements devient l'endroit où
 * l'on vérifie ce que la machine a fait. Le vrai risque n'a jamais été le nom sur la facture — c'est
 * qu'une facture parte de travers et que personne ne la regarde.
 *
 * **Aucune affectation.** Le compte n'est rattaché à aucun établissement : il n'a donc aucun droit,
 * et n'apparaît dans aucune liste d'affectation. Il ne sert qu'à porter un nom sur un document.
 */
final class BillingServiceAccount
{
    /** Lu par un client sur sa facture. C'est le critère qui a présidé au choix. */
    public const NOM = 'Facturation automatique';

    /**
     * Adresse en `.invalid`, réservée par la RFC 2606 et garantie non routable.
     *
     * Un domaine interne plausible finirait par recevoir une réponse de client — et personne ne la
     * lirait.
     */
    public const EMAIL = 'facturation-automatique@abonnements.invalid';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Le compte de service, créé au premier usage.
     *
     * Créé à la demande plutôt que par migration : il n'existe que là où la facturation automatique
     * tourne, et une installation qui ne s'en sert pas ne porte pas un compte inutile dans sa base.
     */
    public function compte(): Utilisateur
    {
        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $compte = new Utilisateur();
        $compte->setEmail(self::EMAIL)
            ->setNom(self::NOM)
            // Suspendu : aucun jeton émis pour ce compte ne passe le contrôle d'activité.
            ->setStatut(StatutUtilisateur::Suspendu)
            ->setRolesSecurite(['ROLE_USER'])
            // Mot de passe aléatoire, jamais conservé : inconnu de tous, y compris de nous.
            ->setMotDePasse($this->hasher->hashPassword($compte, bin2hex(random_bytes(32))));

        $this->em->persist($compte);
        $this->em->flush();

        return $compte;
    }
}
