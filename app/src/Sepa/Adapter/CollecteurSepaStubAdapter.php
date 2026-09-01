<?php

declare(strict_types=1);

namespace App\Sepa\Adapter;

use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Port\CollecteurSepaInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Adaptateur SEPA par defaut — AUCUN RACCORDEMENT BANCAIRE REEL.
 *
 * ── CE QU'IL FAISAIT, ET CE QUE LE SYSTEME EN DEDUISAIT ─────────────────────────────────────────
 *
 * Il rendait `'TRANSMISSION-' . hash(...)` sans rien transmettre, et l'appelant lisait ce retour
 * comme un accuse de reception : la remise passait en `Transmise`, les echeances etaient marquees
 * collectees, et le compteur `nbCollectesReussies` de chaque mandat etait incremente.
 *
 * ⚠ CE COMPTEUR DECIDE DU TYPE DE SEQUENCE SEPA. `SeqTpResolver` en deduit `RCUR` des qu'il depasse
 * zero, `FRST` sinon. Un mandat compte a tort part donc en RCUR a son PREMIER prelevement reel —
 * motif de rejet bancaire classique, sur de l'argent, mandat par mandat, et qui ne se manifeste
 * qu'a la mise en service chez un client.
 *
 * ── ⚠ POURQUOI UN DRAPEAU EXPLICITE ET NON L'ENVIRONNEMENT SYMFONY ─────────────────────────────
 *
 * L'arbitrage etait « refuse en production, fonctionne en preprod ». Mesure faite avant d'ecrire :
 * **la preprod tourne sous `APP_ENV=prod`** (`infra/compose.preprod.yaml`). Une porte sur
 * l'environnement ferait donc refuser le bouchon precisement la ou on veut derouler le parcours.
 *
 * Le drapeau est donc explicite, et son DEFAUT EST LE REFUS. Une installation qui l'oublie ne
 * transmet pas — echec ferme. La forme inverse (« refuser si prod ») echouerait OUVERTE dans tout
 * environnement autrement nomme, ce qui est exactement le sens dangereux de l'erreur.
 *
 * ── CE QUE LE REFUS NE CASSE PAS ────────────────────────────────────────────────────────────────
 *
 * `GenerationRemiseHandler` flushe la remise en statut `Generee` AVANT d'appeler ce port. Le XML
 * pain.008 est donc ecrit et conserve ; c'est la transmission seule qui echoue. La remise reste
 * transmissible le jour du raccordement, sans regenerer le fichier ni renumeroter quoi que ce soit.
 *
 * ── LA REFERENCE DIT DESORMAIS CE QU'ELLE EST ───────────────────────────────────────────────────
 *
 * `TRANSMISSION-…` se lit, en base et dans un ecran, comme une reference bancaire. `SIMULATION-…`
 * ne se confond avec rien. Une trace qui ment sur sa nature est une trace qu'on croira le jour ou
 * il faudra prouver qu'un prelevement est parti.
 *
 * @see \App\Compta\Adapter\ChorusProStubAdapter le meme refus, pour la meme raison, sur le depot B2G
 * @see \App\Vente\Port\PorteMonnaieVirtuelStub « aucun solde connu, tout debit est refuse »
 */
final class CollecteurSepaStubAdapter implements CollecteurSepaInterface
{
    public function __construct(
        /**
         * ⚠ Injecte plutot que lu au fond du code : le refus devient testable sans manipuler
         * l'environnement, et les deux branches se prouvent chacune par un test.
         */
        #[Autowire('%env(bool:SEPA_TRANSMISSION_SIMULEE)%')]
        private readonly bool $simulationAutorisee = false,
    ) {
    }

    public function transmettre(RemiseSepa $remise): string
    {
        if (!$this->simulationAutorisee) {
            throw new ServiceUnavailableHttpException(null, sprintf(
                'Aucun collecteur bancaire n\'est raccorde : la remise « %s » N\'A PAS ete transmise '
                . 'et aucun prelevement n\'a ete presente. Le fichier pain.008 est genere et '
                . 'conserve ; la remise reste transmissible telle quelle le jour du raccordement. '
                . 'Pour derouler le parcours sans banque, poser SEPA_TRANSMISSION_SIMULEE=1.',
                (string) $remise->getId()
            ));
        }

        return 'SIMULATION-' . substr(hash('sha256', (string) $remise->getId() . $remise->getNbTxs()), 0, 16);
    }
}
