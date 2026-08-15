<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Audit\Entity\EntreeAudit;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\EtatConsentement;
use App\Crm\Event\PassageMajoriteEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Passage à la majorité d'un bénéficiaire mineur (RG-M4-10, décision actée, CA-19/CA-20, §3.2
 * plan-crm.md) : les consentements portés par le représentant légal passent en `a_renouveler`, une
 * relance est déclenchée (événement, envoi hors périmètre), la transition est journalisée
 * (`EntreeAudit`, réutilisé — pas de nouvelle entité), et l'autorisation `entree_seule` du client
 * devenu majeur (dérogatoire, n'a plus lieu d'être) est retirée de son propre enregistrement.
 */
final class RenouvellementMajoriteHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function traiter(Client $client): void
    {
        $consentements = $this->em->getRepository(Consentement::class)->findBy(['client' => $client]);

        // État courant par canal (dernier recueil) — seuls les canaux dont l'état courant est
        // `accorde` ET porté par le représentant légal basculent en `a_renouveler` (§3.2).
        $etatsCourants = [];
        foreach ($consentements as $c) {
            $canal = $c->getCanal()->value;
            if (!isset($etatsCourants[$canal]) || $c->getDateRecueil() > $etatsCourants[$canal]->getDateRecueil()) {
                $etatsCourants[$canal] = $c;
            }
        }

        $canauxARenouveler = [];
        foreach ($etatsCourants as $etat) {
            if ($etat->getEtat() === EtatConsentement::Accorde && $etat->isRecueilliParRepresentant()) {
                $nouveau = new Consentement($etat->getCanal(), EtatConsentement::ARenouveler);
                $nouveau->setClient($client);
                $nouveau->setSource('passage_majorite');
                $nouveau->setRecueilliParRepresentant(false);
                $this->em->persist($nouveau);
                $canauxARenouveler[] = $etat->getCanal()->value;
            }
        }

        // Journalisation (CA-20) : réutilise EntreeAudit du socle, pas de nouvelle entité.
        $entree = new EntreeAudit();
        $entree->setAction('passage_majorite');
        $entree->setCibleType(Client::class);
        $entree->setCibleId((string) $client->getId());
        $entree->setAuteur('systeme:crm-majorite');
        $this->em->persist($entree);

        // Autorisations dérogatoires devenues caduques pour le client lui-même (§3.2 point 5) ;
        // les autorisations détenues par des tiers ne sont pas automatisées (modèle trop grossier,
        // signalées via l'événement pour revue manuelle, ⚠ HYPOTHÈSE documentée).
        $beneficiaires = $this->em->getRepository(Beneficiaire::class)->findBy(['client' => $client]);
        foreach ($beneficiaires as $beneficiaire) {
            if ($beneficiaire->estActif()) {
                $beneficiaire->retirerAutorisation('entree_seule');
            }
        }

        $this->dispatcher->dispatch(new PassageMajoriteEvent($client, $canauxARenouveler));
    }
}
