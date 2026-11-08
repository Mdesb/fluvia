<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Enum\StatutPayFiP;
use App\Compta\Enum\TypeExploitant;
use App\Compta\PayFip\PayFipConfiguration;
use App\Compta\PayFip\PayFipException;
use App\Compta\PayFip\PayFipPaymentDetail;
use App\Compta\PayFip\PayFipPaymentRequest;
use App\Compta\PayFip\PayFipSoapClient;
use App\Compta\Service\TraiterRetourPayFipHandler;
use App\Vente\Enum\StatutTPE;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * PAYFIP, POUR DE VRAI — régime « régie directe / public » (RG-M3-11, #339).
 *
 * Cinématique du guide DGFiP « PayFiP — Régies de recettes du secteur local — Web service » V18.0 :
 *  1. `creerPaiementSecurise` (serveur à serveur) → IdOp, valable 15 minutes (§3.5.2.1, §3.5.2.3) ;
 *  2. l'acheteur part sur `paiementws.web?idop=…`, paie chez le prestataire CB de la DGFiP ;
 *  3. PayFiP le renvoie sur URLREDIRECT ET appelle URLNOTIF, chacune « complétée du paramètre IdOp »
 *     (§3.5.2.4, §3.5.2.5) — l'un ou l'autre nous réveille ;
 *  4. on demande l'issue à PayFiP : `recupererDetailPaiementSecurise(IdOp)` (§3.5.2.6). C'est la SEULE
 *     source de l'issue. Ni le navigateur, ni la notification ne la portent.
 *
 * Vérification (`verifierRetour`) : la réponse de PayFiP doit concerner NOTRE client (NUMCLI), NOTRE
 * opération (IdOp), la référence et le montant initiés, dans le mode de saisie de l'initiation — un
 * paiement de test (T) ne vaut jamais pour une commande initiée en réel (W). Un écart, et rien n'est
 * attesté (`null` → 422). Seuls P et V (payé) confirment ; R, Z (refus) et A (abandon) laissent la
 * commande impayée ; P5 (« résultat pas encore connu ») la laisse en attente.
 *
 * Rapprochement : chaque opération laisse un `BordereauPayFiP` portant REFDET (= notre numéro de
 * vente, lisible dans la remise quotidienne du régisseur, §4.3), le montant, le mode, puis RESULTRANS,
 * NUMAUTO et la date de transaction.
 */
#[AutoconfigureTag('boutique.paiement_en_ligne')]
final class PayFipOnlinePaymentAdapter implements PaiementEnLigneInterface, ConfigurableOnlinePaymentInterface, NotifiedOnlinePaymentInterface
{
    public function __construct(
        private readonly PayFipConfiguration $configuration,
        private readonly PayFipSoapClient $client,
        private readonly TraiterRetourPayFipHandler $bordereaux,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::RegieDirecte;
    }

    public function isConfigured(): bool
    {
        return $this->configuration->isConfigured();
    }

    public function provider(): string
    {
        return 'payfip';
    }

    /** PayFiP appelle URLNOTIF « complétée du paramètre IdOp » — en GET ou en POST selon les versions : on lit les deux. */
    public function referenceFromNotification(Request $request): ?string
    {
        foreach (['idop', 'idOp', 'IdOp', 'IDOP'] as $name) {
            $value = $request->query->get($name) ?? $request->request->get($name);
            if (\is_string($value) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour, ?OnlinePaymentContext $context = null): InitiationPaiementEnLigne
    {
        if ($context === null) {
            throw new UnprocessableEntityHttpException('PayFiP : numéro de commande et adresse de l\'acheteur requis.');
        }

        try {
            $demande = PayFipPaymentRequest::forOrder(
                $this->configuration,
                $context->orderNumber,
                $venteId->toBase58(),
                $montantCentimes,
                $context->payerEmail,
                $urlRetour,
                new \DateTimeImmutable(),
            );
        } catch (PayFipException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        try {
            $idOp = $this->client->createSecurePayment($demande);
        } catch (PayFipException $e) {
            $this->logger->error('PayFiP : initiation refusée', ['vente' => (string) $venteId, 'code' => $e->faultCode(), 'erreur' => $e->getMessage()]);

            throw new HttpException(502, 'Le service de paiement PayFiP n\'a pas pu ouvrir la transaction. Réessayez dans quelques minutes.', $e);
        }

        $bordereau = $this->bordereaux->initier($venteId, $idOp);
        $bordereau->recordInitiation($demande->debtReference, $montantCentimes, $demande->entryMode);
        $this->em->flush();

        return new InitiationPaiementEnLigne($idOp, $this->client->paymentPageUrl($idOp));
    }

    public function verifierRetour(string $referenceTransaction, int $montantAttenduCentimes, array $donneesRetour): ?ResultatRetourPaiementEnLigne
    {
        $bordereau = $this->em->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => $referenceTransaction]);
        if (!$bordereau instanceof BordereauPayFiP) {
            return null;
        }

        try {
            $detail = $this->client->fetchPaymentDetail($referenceTransaction);
        } catch (PayFipException $e) {
            if ($e->faultCode() === PayFipException::UNKNOWN_OPERATION) {
                $this->logger->warning('PayFiP : opération inconnue de PayFiP', ['idOp' => $referenceTransaction]);

                return null;
            }
            // P5 « résultat pas encore connu », ou PayFiP injoignable : l'issue est inconnue, pas négative.
            $this->logger->notice('PayFiP : issue pas encore connue', ['idOp' => $referenceTransaction, 'code' => $e->faultCode(), 'erreur' => $e->getMessage()]);

            return new ResultatRetourPaiementEnLigne($referenceTransaction, StatutTPE::Timeout, $montantAttenduCentimes);
        }

        $ecart = $this->ecart($detail, $bordereau, $referenceTransaction, $montantAttenduCentimes);
        if ($ecart !== null) {
            $this->logger->warning('PayFiP : réponse qui ne correspond pas à l\'opération initiée', ['idOp' => $referenceTransaction, 'ecart' => $ecart]);

            return null;
        }

        $statut = match (true) {
            $detail->isPaid() => StatutTPE::Accepte,
            $detail->isRefused() => StatutTPE::Refuse,
            $detail->isAbandoned() => StatutTPE::Annule,
            default => null,
        };
        if ($statut === null) {
            $this->logger->warning('PayFiP : RESULTRANS inconnu', ['idOp' => $referenceTransaction, 'resultrans' => $detail->result]);

            return null;
        }

        $bordereau->recordResult($detail->result, $detail->authorizationNumber, $detail->transactedAt());
        $this->bordereaux->traiterRetour($bordereau, match ($statut) {
            StatutTPE::Accepte => StatutPayFiP::Ok,
            StatutTPE::Annule => StatutPayFiP::Annule,
            default => StatutPayFiP::Echec,
        });

        return new ResultatRetourPaiementEnLigne($referenceTransaction, $statut, (int) $detail->amountCents());
    }

    /** La raison pour laquelle cette réponse n'atteste pas NOTRE opération — `null` si elle l'atteste. */
    private function ecart(PayFipPaymentDetail $detail, BordereauPayFiP $bordereau, string $reference, int $montantAttendu): ?string
    {
        return match (true) {
            $detail->operationId !== '' && $detail->operationId !== $reference => 'IdOp',
            $detail->clientNumber !== $this->configuration->clientNumber() => 'NUMCLI',
            $detail->amountCents() === null || $detail->amountCents() !== $montantAttendu => 'MONTANT',
            $bordereau->getAmountCents() !== null && $bordereau->getAmountCents() !== $montantAttendu => 'MONTANT initié',
            $bordereau->getDebtReference() !== null && $detail->debtReference !== $bordereau->getDebtReference() => 'REFDET',
            $bordereau->getEntryMode() !== null && $detail->entryMode !== $bordereau->getEntryMode() => 'SAISIE',
            default => null,
        };
    }
}
