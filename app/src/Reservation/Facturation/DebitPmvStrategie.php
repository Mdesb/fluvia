<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Platform\Event\EventBus;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Service\NoShowBillingLock;
use App\Reservation\Service\SessionSystemeResolver;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Service\PaiementHandler;
use App\Vente\Service\SettlementEvents;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Stratégie réellement branchée (décision structurante n°4 du plan) : débit automatique du
 * porte-monnaie virtuel du bénéficiaire (`App\Vente\Port\PorteMonnaieVirtuelInterface`, via
 * `PaiementHandler` — même mécanisme atomique que le paiement guichet), sans agent présent. Utilise
 * une `SessionCaisse` technique permanente par établissement (`SessionSystemeResolver`, Risque n°1 du
 * plan). Si le débit réussit, la vente est validée/scellée (NF525) immédiatement.
 *
 * ── UNE SEULE UNITÉ (G-1 et G-5 du ticket opposable, lot 4) ────────────────────────────────────
 *
 * La vente, le débit, le règlement, la validation et le statut de la facturation partent dans UNE
 * transaction, sous le verrou de la facturation (`NoShowBillingLock`), avec une clé tirée de la
 * facturation. Avant : le débit partait hors transaction et la validation hors du `try` ; une
 * validation en échec laissait le client débité sans règlement et la facturation « à facturer », et
 * chaque relance ouvrait une vente neuve et débitait de nouveau. Les événements partent après le commit.
 */
final class DebitPmvStrategie implements StrategieFacturationNoShow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteReservationHandler $venteHandler,
        private readonly SessionSystemeResolver $sessionSysteme,
        private readonly PaiementHandler $paiementHandler,
        private readonly ValiderVenteService $validation,
        private readonly NoShowBillingLock $verrou,
        private readonly EventBus $bus,
        #[Autowire(env: 'APP_SECRET')] private readonly string $secret,
    ) {
    }

    public function code(): string
    {
        return ModeFacturationNoShow::DebitPmv->value;
    }

    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow
    {
        $reservation = $facturation->getReservation();
        $etablissement = $reservation?->getEtablissement();
        $organisateur = $reservation?->getOrganisateur();
        $clientRef = $organisateur?->getClient()?->getId();

        if ($etablissement === null || $clientRef === null) {
            return new ResultatFacturationNoShow(false, 'Bénéficiaire sans fiche client CRM rattachée : débit PMV impossible.');
        }

        // ⚠ SANS PRODUIT, ON NE FACTURE PAS. La chaîne de `?->` rendait `null` dès qu'une activité
        // n'a pas de produit tarifaire — les 3 de la préproduction, mesuré le 04/09 — et le handler
        // inventait alors un produit inexistant. Un no-show débité sur un produit fantôme est une
        // recette que la comptabilité ne verra jamais.
        $produitRef = $reservation->getCreneau()?->getActivite()?->getProduitTarifReference()?->getId();
        if ($produitRef === null) {
            return new ResultatFacturationNoShow(
                false,
                'L\'activité de ce créneau n\'a pas de produit tarifaire : le débit ne serait rattaché à '
                . 'aucune catégorie comptable. Renseignez-le avant de facturer ce no-show.',
            );
        }

        $session = $this->sessionSysteme->sessionSysteme($etablissement);
        $avant = [$facturation->getStatut(), $facturation->getVenteRattachee()];
        $evenements = new SettlementEvents();
        $vente = null;
        try {
            $resultat = $this->em->getConnection()->transactional(function () use ($facturation, $reservation, $session, $clientRef, $produitRef, $evenements, &$vente): ResultatFacturationNoShow {
                $statut = $this->verrou->lock($facturation);
                if ($statut !== StatutFacturationNoShow::AFacturer) {
                    return ResultatFacturationNoShow::alreadySettled($statut);
                }

                $vente = $this->venteHandler->creerVente(
                    $session,
                    $facturation->getMontant(),
                    $clientRef,
                    'No-show (débit PMV automatique) — réservation ' . (string) $reservation->getId(),
                    $produitRef,
                    $reservation->getCreneau()?->getActivite()?->getId(),
                    $reservation->getCreneau()?->getRessource()?->getId(),
                );
                $encaissement = $this->paiementHandler->encaisser($vente, ['moyen' => 'pmv', 'cleIdempotence' => $this->debitKey($facturation)], $evenements);
                if ($encaissement['paiement'] === null) {
                    throw new UnprocessableEntityHttpException('Débit PMV refusé (solde insuffisant ou PMV inexistant/expiré).');
                }
                // Le règlement et le mouvement du porte-monnaie sont écrits ICI, dans la transaction : un
                // échec ensuite les annule, et rien ne reste en attente d'écriture pour le `flush()` de
                // l'appelant (l'annulation d'une réservation, la bascule).
                $this->em->flush();
                $this->validation->valider($vente, [], $evenements);
                $facturation->setVenteRattachee($vente);
                $facturation->setStatut(StatutFacturationNoShow::Facturee);
                $this->em->flush();

                return new ResultatFacturationNoShow(true);
            });
        } catch (\Throwable $e) {
            $this->forget($facturation, $avant, $vente);

            return new ResultatFacturationNoShow(false, $e instanceof HttpExceptionInterface
                ? $e->getMessage()
                : 'Débit PMV impossible : l\'opération a été annulée en entier, rien n\'a été débité.');
        }
        $evenements->publishTo($this->bus);

        return $resultat;
    }

    /**
     * La clé du débit (G-1) : tirée de la facturation, donc la même à chaque relance, et jamais celle
     * de la vente qu'on ouvre. Un HMAC et non l'identifiant : l'API montre celui-ci, et une clé connue
     * pourrait être prise d'avance sur une autre vente (l'index est unique sur toute la table) — le
     * débit serait alors refusé pour toujours. UUID v8 (RFC 9562), donc jamais nul ni max.
     */
    private function debitKey(FacturationNoShow $facturation): string
    {
        $octets = substr(hash_hmac('sha256', 'no-show-debit|' . $facturation->getId()->toRfc4122(), $this->secret, true), 0, 16);
        $octets[6] = \chr((\ord($octets[6]) & 0x0F) | 0x80);
        $octets[8] = \chr((\ord($octets[8]) & 0x3F) | 0x80);

        return Uuid::fromBinary($octets)->toRfc4122();
    }

    /**
     * La transaction est annulée, mais l'`EntityManager` garde ce qu'elle avait écrit : la vente, ses
     * lignes et son règlement, la facturation « facturée ». L'appelant qui `flush()` ensuite y
     * écrirait une facturation sans vente ni débit. On remet la facturation comme avant, et on oublie
     * la vente.
     *
     * @param array{0: StatutFacturationNoShow, 1: Vente|null} $avant
     */
    private function forget(FacturationNoShow $facturation, array $avant, ?Vente $vente): void
    {
        $facturation->setStatut($avant[0]);
        $facturation->setVenteRattachee($avant[1]);
        if ($vente === null) {
            return;
        }
        foreach ([...$vente->getLignes(), ...$vente->getPaiements(), ...$vente->getSupports(), $vente] as $entite) {
            if ($this->em->contains($entite)) {
                $this->em->detach($entite);
            }
        }
    }
}
