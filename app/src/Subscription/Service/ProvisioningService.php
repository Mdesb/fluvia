<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Crm\Entity\Client;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Dto\ProvisioningOutcome;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\ProvisioningStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée l'établissement d'un client qui vient de payer : son arbre, son administrateur, ses modules
 * (ED-3, CA-1, CA-2, RG-ED-05).
 *
 * **Ce service est le premier créateur d'établissement en production.** Jusqu'ici, un `Etablissement`
 * n'était créé que par des fixtures. Tout ce qu'une fixture pouvait se permettre — supposer un
 * groupe existant, ignorer les collisions, poser des droits à la main — devient ici une décision de
 * production, prise une fois, ci-dessous.
 *
 * **Un client provisionné reçoit son propre `Groupe`.** Le rattacher à une région de l'éditeur
 * placerait ses données sous l'arbre organisationnel de l'éditeur, où le cloisonnement (D3) les
 * rendrait atteignables depuis le tenant éditeur. RG-ED-01 dit que l'éditeur n'a besoin d'aucun
 * accès inter-établissement pour facturer : le corollaire est qu'il ne doit pas en hériter un par la
 * structure. D'où `Groupe → Region → Etablissement` créés ensemble, pour ce client seul.
 *
 * **L'idempotence n'est pas un « si ça existe déjà »** (RG-ED-05). Elle repose sur l'unicité de
 * `subscription_id` en base : la demande est insérée et validée **avant** que le moindre
 * établissement ne soit créé, et c'est le rejet de cet `INSERT` — pas une lecture — qui apprend au
 * second webhook qu'il est arrivé deuxième. Un webhook bancaire se répète, parfois en parallèle ;
 * un `SELECT` puis `INSERT` provisionnerait deux fois, et facturerait deux fois.
 *
 * **Un échec est final et lisible.** Adresse d'administrateur déjà prise, rôle modèle absent : ce
 * sont des causes qu'un rejeu à l'identique ne lève pas. On les inscrit en clair dans la demande
 * plutôt que de laisser un client payant sans plateforme et sans trace.
 */
final class ProvisioningService
{
    /**
     * Le rôle modèle dont hérite l'administrateur du client.
     *
     * Ce service **ne compose aucune politique de permissions**. Décider ici ce qu'un administrateur
     * de client a le droit de faire reviendrait à écrire une seconde politique d'habilitation, à
     * côté de celle de `Securite`, qui divergerait dès la première évolution. Il duplique un rôle
     * modèle existant, exactement comme le fait `DuplicationRoleProcessor`, et échoue bruyamment si
     * ce modèle n'a pas été posé.
     */
    public const ADMIN_ROLE_TEMPLATE = 'Administrateur d\'établissement';

    /** Longueur de `sec_role.nom`. Dépasser ne lève pas d'avertissement : la ligne est refusée. */
    private const ROLE_NAME_MAX_LENGTH = 120;

    private const INVITATION_HOURS = 72;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Fonctionnalites $features,
        private readonly DemoConfiguration $demoConfiguration,
    ) {
    }

    /**
     * Provisionne l'abonnement, ou retrouve le provisioning déjà fait.
     *
     * @param \DateTimeImmutable $at instant de référence pour les capacités actives (prorata, ED-2)
     */
    public function provision(Subscription $subscription, \DateTimeImmutable $at): ProvisioningOutcome
    {
        $request = $this->claim($subscription);

        // Rejeu : la demande existe déjà et a été tranchée. On compte le passage et on rend la
        // décision précédente telle quelle — sans nouveau jeton (voir ProvisioningOutcome).
        if (ProvisioningStatus::Pending !== $request->getStatus()) {
            $request->recordAttempt();
            $this->em->flush();

            return new ProvisioningOutcome($request);
        }

        $client = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());
        if (!$client instanceof Client) {
            return $this->abort($request, sprintf(
                'Aucune fiche client « %s » : impossible de provisionner un établissement sans savoir '
                .'à qui en confier l\'administration (RG-ED-02).',
                $subscription->getCustomerReference(),
            ));
        }

        $email = trim((string) $client->getEmail());
        if ('' === $email) {
            return $this->abort($request, 'La fiche client ne porte pas d\'adresse e-mail : l\'administrateur ne pourrait pas être invité.');
        }

        // Deux abonnements pour la même adresse (spec §7) : on tranche sur une règle stable plutôt
        // que sur l'ordre d'arrivée des webhooks. Rattacher ce second établissement au compte
        // existant donnerait à une personne un pied dans deux tenants — précisément ce que D3
        // interdit. On refuse, et l'exploitant arbitre.
        if (null !== $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
            return $this->abort($request, sprintf(
                'L\'adresse « %s » sert déjà à un compte : rattacher ce nouvel établissement à ce '
                .'compte lui donnerait accès à deux périmètres (D3). Arbitrage manuel requis.',
                $email,
            ));
        }

        $template = $this->em->getRepository(Role::class)->findOneBy([
            'nom' => self::ADMIN_ROLE_TEMPLATE,
            'estModele' => true,
        ]);
        if (!$template instanceof Role) {
            return $this->abort($request, sprintf(
                'Rôle modèle « %s » absent : aucun administrateur ne peut être habilité. Le '
                .'provisioning ne compose pas de permissions lui-même.',
                self::ADMIN_ROLE_TEMPLATE,
            ));
        }

        $establishment = $this->createEstablishmentTree($this->establishmentName($client));

        // Le jeton naît ici et non dans la méthode qui crée le compte : la base n'en garde que le
        // sha256, et l'appelant a besoin de la valeur en clair pour l'envoyer une fois, tout de suite.
        $token = bin2hex(random_bytes(32));
        $administrator = $this->createAdministrator($client, $email, $establishment, $template, $token);

        // Les modules souscrits, et eux seuls (CA-1). `activeCapabilities` fait déjà la somme de la
        // formule et des options en cours à cet instant : la reproduire ici la ferait diverger.
        $capabilities = $subscription->activeCapabilities($at);
        foreach ($capabilities as $capability) {
            $this->features->definir($establishment, $capability, true, null);
        }

        // Le paramétrage de démo, rejoué **après** l'activation des modules et borné à eux (CA-7,
        // RG-ED-08). Après, parce qu'un module éteint refuserait sa propre configuration ; borné,
        // parce qu'un instantané ne doit jamais allumer ce qui n'a pas été acheté.
        $this->demoConfiguration->replay($establishment, $subscription->getDemoConfiguration(), $capabilities);

        $request->recordAttempt()->complete($establishment);
        $this->em->flush();

        return new ProvisioningOutcome($request, $token, $administrator->getId());
    }

    /**
     * Réserve le provisioning de cet abonnement, ou rend celui qui l'a précédé.
     *
     * L'insertion est validée immédiatement : c'est elle, et le rejet éventuel de la contrainte
     * d'unicité, qui départagent deux réceptions concurrentes du même événement.
     */
    private function claim(Subscription $subscription): ProvisioningRequest
    {
        $existing = $this->em->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $subscription]);
        if ($existing instanceof ProvisioningRequest) {
            return $existing;
        }

        $request = (new ProvisioningRequest())->setSubscription($subscription);

        try {
            $this->em->persist($request);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Arrivé deuxième. La connexion est refermée pour repartir d'un état propre, puis on
            // relit la demande gagnante : c'est elle qui fait foi, pas celle qu'on vient de perdre.
            $this->em->getConnection()->close();

            $winner = $this->em->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $subscription]);
            \assert($winner instanceof ProvisioningRequest);

            return $winner;
        }

        return $request;
    }

    /** Le client est seul dans son arbre : un groupe, une région, un établissement. */
    private function createEstablishmentTree(string $name): Etablissement
    {
        $groupe = (new Groupe())->setNom($name);
        $this->em->persist($groupe);

        $region = (new Region())->setNom($name)->setGroupe($groupe);
        $this->em->persist($region);

        $establishment = (new Etablissement())->setNom($name)->setRegion($region)->setActif(true);
        $this->em->persist($establishment);

        return $establishment;
    }

    /**
     * Crée l'administrateur du client, invité et non actif.
     *
     * **Aucun mot de passe n'est choisi pour le client** : le compte naît en `Invite` avec un jeton
     * d'activation, comme tout compte créé par un tiers dans `Securite`. Générer un mot de passe et
     * l'envoyer serait un secret transmis par courriel, et un compte qui reste ouvert si le courriel
     * fuite.
     *
     * @param string $clearToken le jeton d'activation ; seul son sha256 est écrit en base
     */
    private function createAdministrator(
        Client $client,
        string $email,
        Etablissement $establishment,
        Role $template,
        string $clearToken,
    ): Utilisateur {
        $role = (new Role())
            ->setNom($this->administratorRoleName($establishment))
            ->setEstModele(false)
            ->setRoleModeleOrigine($template);
        foreach ($template->getPermissions() as $permission) {
            $role->addPermission($permission);
        }
        $this->em->persist($role);

        $administrator = new Utilisateur();
        $administrator->setEmail($email)
            ->setNom($this->administratorName($client, $email))
            ->setStatut(StatutUtilisateur::Invite)
            ->setJetonInvitation(hash('sha256', $clearToken))
            ->setJetonInvitationExpire(new \DateTimeImmutable('+'.self::INVITATION_HOURS.' hours'))
            ->setRolesSecurite(['ROLE_USER'])
            // Mot de passe technique inconnu tant que le compte n'est pas activé.
            ->setMotDePasse($this->hasher->hashPassword($administrator, bin2hex(random_bytes(32))));
        $this->em->persist($administrator);

        $affectation = (new Affectation())
            ->setUtilisateur($administrator)
            ->setRole($role)
            ->setEtablissement($establishment);
        $this->em->persist($affectation);

        return $administrator;
    }

    /**
     * Le nom du rôle livré au client — unique, borné à la colonne, et lisible par l'exploitant.
     *
     * Trois contraintes se croisent ici, et chacune a déjà cassé quelque chose :
     *
     * 1. **`Role.nom` est unique globalement** (`uniq_role_nom`). Reprendre le nom du modèle faisait
     *    échouer le *deuxième* client provisionné — le premier ne révélait rien.
     * 2. **La colonne fait 120 caractères.** Une raison sociale longue déborde, et le débordement
     *    n'arrive qu'en production, chez le client qui a le nom le plus long.
     * 3. **Tronquer par la fin annulerait le point 1** : deux raisons sociales qui ne diffèrent
     *    qu'au-delà de la coupe produiraient le même nom. On tronque donc **le milieu**, ce qui
     *    préserve à la fois le début lisible et le suffixe qui porte l'unicité.
     *
     * Le suffixe est l'identifiant **complet** de l'établissement, pas un préfixe de huit caractères :
     * huit caractères hexadécimaux, c'est trente-deux bits, et une collision d'anniversaire y devient
     * plausible bien avant qu'on ait épuisé les clients. Un nom de rôle en doublon ferait échouer le
     * provisionnement d'un client qui a déjà payé — le prix de la garantie est ici quelques caractères
     * de lisibilité.
     */
    private function administratorRoleName(Etablissement $establishment): string
    {
        $prefix = 'Administrateur — ';
        $suffix = ' — '.$establishment->getId()->toRfc4122();

        $available = self::ROLE_NAME_MAX_LENGTH - mb_strlen($prefix) - mb_strlen($suffix);

        return $prefix.$this->truncateMiddle($establishment->getNom(), $available).$suffix;
    }

    /**
     * Raccourcit en retirant le milieu : « Camping municipal … des Pins » plutôt qu'une coupe nette.
     *
     * Garder les deux extrémités n'est pas cosmétique — c'est ce qui laisse deux noms voisins
     * distinguables à l'œil, là où une coupe par la fin les rendrait identiques à l'écran.
     */
    private function truncateMiddle(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        if ($max <= 1) {
            return mb_substr($value, 0, $max);
        }

        $head = (int) ceil(($max - 1) / 2);
        $tail = $max - 1 - $head;

        return mb_substr($value, 0, $head).'…'.($tail > 0 ? mb_substr($value, -$tail) : '');
    }

    private function establishmentName(Client $client): string
    {
        $raisonSociale = trim((string) $client->getRaisonSociale());
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $nom = trim(($client->getPrenom() ?? '').' '.($client->getNom() ?? ''));

        return '' !== $nom ? $nom : 'Etablissement '.$client->getId()->toRfc4122();
    }

    private function administratorName(Client $client, string $email): string
    {
        $nom = trim(($client->getPrenom() ?? '').' '.($client->getNom() ?? ''));

        return '' !== $nom ? $nom : $email;
    }

    /** Clôt la demande sur un échec final, sans rien avoir créé. */
    private function abort(ProvisioningRequest $request, string $reason): ProvisioningOutcome
    {
        $request->recordAttempt()->fail($reason);
        $this->em->flush();

        return new ProvisioningOutcome($request);
    }
}
