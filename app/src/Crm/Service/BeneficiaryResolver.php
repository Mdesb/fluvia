<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\RoleBeneficiaire;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QUI EST L'ADHÉRENT, QUAND SEUL L'ACHETEUR EST CONNU.
 *
 * ── LES DEUX RÔLES, ET POURQUOI ILS NE SE CONFONDENT PAS ───────────────────────────────────────
 *
 * Un abonnement porte un ADHÉRENT (`Beneficiaire` — celui qui vient, dont le badge porte le nom) et
 * un PAYEUR (`Client` — celui qui est prélevé). Le cas courant les sépare : un parent règle pour son
 * enfant. Le cas le plus fréquent les confond : l'adhérent se paie lui-même.
 *
 * Arbitrage de Maxime le 06/09 : « ça peut être la même personne donc il faut un mécanisme de
 * suggestion ». Au guichet, l'opérateur choisit et l'écran suggère. En ligne, personne ne choisit :
 * l'acheteur est le payeur, et l'adhérent est lui-même — sauf si la ligne de commande en désigne un
 * autre.
 *
 * ── POURQUOI CE SERVICE EXISTE PLUTÔT QU'UNE MÉTHODE PRIVÉE ────────────────────────────────────
 *
 * Cette résolution vivait en privé dans `ConfirmerCommandeHandler`, et la souscription
 * d'abonnement en ligne en avait besoin à l'identique. La recopier aurait donné deux réponses à
 * « qui est l'adhérent de cet achat » — deux réponses qui se seraient répondu la même chose le
 * premier jour, et plus le jour où l'une des deux apprend un cas de plus.
 *
 * ⚠ ET LA CRÉATION D'UNE FAMILLE EST UN EFFET DE BORD ASSUMÉ. Un `Beneficiaire` sans famille n'est
 * rattachable à rien ; en créer une d'un membre est le plus petit objet qui rende le rattachement
 * possible. Elle porte le payeur comme payeur principal, ce qui reste vrai s'il en ajoute d'autres.
 */
final class BeneficiaryResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * L'adhérent d'un achat, résolu puis créé si besoin.
     *
     * @param Client      $payer      celui qui règle — l'acheteur en ligne
     * @param Client|null $designated l'adhérent désigné par la ligne de commande, quand il diffère
     */
    public function forPurchase(Client $payer, ?Client $designated = null): Beneficiaire
    {
        if ($designated instanceof Client) {
            $existant = $this->em->getRepository(Beneficiaire::class)->findOneBy(['client' => $designated]);
            if ($existant instanceof Beneficiaire) {
                return $existant;
            }
        }

        $beneficiaire = $this->em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payer]);
        if ($beneficiaire instanceof Beneficiaire) {
            return $beneficiaire;
        }

        $famille = new Famille();
        $famille->setPayeurPrincipal($payer)
            ->setGroupe($payer->getGroupe())
            ->setLibelle('Famille ' . ($payer->getNom() ?? 'boutique'));
        $this->em->persist($famille);

        $beneficiaire = new Beneficiaire();
        $beneficiaire->setFamille($famille)
            ->setClient($payer)
            ->setRole(RoleBeneficiaire::PayeurEtBeneficiaire);
        $this->em->persist($beneficiaire);
        $this->em->flush();

        return $beneficiaire;
    }
}
