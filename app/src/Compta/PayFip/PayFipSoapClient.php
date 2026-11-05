<?php

declare(strict_types=1);

namespace App\Compta\PayFip;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * THE PAYFIP "RÉGIE" WEB SERVICE, SPOKEN BY HAND.
 *
 * Protocol: DGFiP, « Guide de mise en œuvre PayFiP — Régies de recettes du secteur local — Web
 * service », V18.0 (août 2019), §3.5.1 to §3.5.2.6. SOAP 1.1, document/literal, `soapAction=""`,
 * JAX-WS service `PaiementSecuriseService`; operation parameters are wrapped in an unqualified `arg0`
 * and answers in an unqualified `return`. The element names are those of the service's WSDL as
 * distributed to adherents (cross-checked with the open-source client eopayment, Entr'ouvert, which
 * talks to the production service).
 *
 * WHY NOT ext-soap: the PHP image does not ship it, and two operations with flat string fields do not
 * justify a new system dependency. The XML is built with DOM (escaping is DOM's job, not ours) and read
 * with DOM, without network access and without entity expansion.
 *
 * WHAT THIS CLASS DOES NOT DO: decide. It returns what PayFiP says; the adapter compares it with what we
 * initiated (client number, operation, amount, entry mode) before anything is confirmed.
 */
final class PayFipSoapClient
{
    public const SERVICE_NAMESPACE = 'http://securite.service.tpa.cp.finances.gouv.fr/services/mas_securite/contrat_paiement_securise/PaiementSecuriseService';
    private const SOAP_ENVELOPE_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PayFipConfiguration $configuration,
    ) {
    }

    /** `creerPaiementSecurise`: returns the operation id (IdOp) to send the buyer to PayFiP with. */
    public function createSecurePayment(PayFipPaymentRequest $request): string
    {
        $fields = $this->call('creerPaiementSecurise', $request->toFields());
        $operationId = trim($fields['idOp'] ?? '');
        if ($operationId === '') {
            throw new PayFipException('PayFiP n\'a pas rendu d\'identifiant d\'opération.');
        }

        return $operationId;
    }

    /**
     * `recupererDetailPaiementSecurise`: what PayFiP knows about an operation. The guide asks for unit
     * calls, spaced by 30 minutes after a technical error (§3.5.2.6); the caller throttles.
     */
    public function fetchPaymentDetail(string $operationId): PayFipPaymentDetail
    {
        return PayFipPaymentDetail::fromFields($this->call('recupererDetailPaiementSecurise', ['idOp' => $operationId]));
    }

    public function paymentPageUrl(string $operationId): string
    {
        return $this->configuration->paymentPageUrl($operationId);
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array<string, string> the children of `return`
     */
    private function call(string $operation, array $arguments): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->configuration->serviceUrl(), [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '""'],
                'body' => self::envelope($operation, $arguments),
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpClientException $e) {
            throw new PayFipException('Service PayFiP injoignable : ' . $e->getMessage(), null, $e);
        }

        $document = self::parse($body);
        $fault = self::fault($document);
        if ($fault !== null) {
            throw $fault;
        }
        if ($status !== 200) {
            throw new PayFipException(sprintf('Service PayFiP : réponse HTTP %d inattendue.', $status));
        }

        $responses = $document->getElementsByTagNameNS(self::SERVICE_NAMESPACE, $operation . 'Response');
        $return = $responses->length > 0 ? self::child($responses->item(0), 'return') : null;
        if ($return === null) {
            throw new PayFipException(sprintf('Service PayFiP : réponse « %s » illisible.', $operation));
        }

        $fields = [];
        foreach ($return->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $fields[$node->localName] = $node->textContent;
            }
        }

        return $fields;
    }

    /** @param array<string, string> $arguments */
    public static function envelope(string $operation, array $arguments): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $envelope = $document->createElementNS(self::SOAP_ENVELOPE_NAMESPACE, 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pai', self::SERVICE_NAMESPACE);
        $document->appendChild($envelope);
        $envelope->appendChild($document->createElementNS(self::SOAP_ENVELOPE_NAMESPACE, 'soapenv:Header'));
        $body = $envelope->appendChild($document->createElementNS(self::SOAP_ENVELOPE_NAMESPACE, 'soapenv:Body'));
        $call = $body->appendChild($document->createElementNS(self::SERVICE_NAMESPACE, 'pai:' . $operation));
        $arg0 = $call->appendChild($document->createElement('arg0'));
        foreach ($arguments as $name => $value) {
            $element = $document->createElement($name);
            $element->appendChild($document->createTextNode($value));
            $arg0->appendChild($element);
        }

        return (string) $document->saveXML();
    }

    private static function parse(string $xml): \DOMDocument
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new PayFipException('Service PayFiP : réponse vide ou non conforme.');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new PayFipException('Service PayFiP : réponse XML illisible.');
        }

        return $document;
    }

    private static function fault(\DOMDocument $document): ?PayFipException
    {
        $faults = $document->getElementsByTagNameNS(self::SOAP_ENVELOPE_NAMESPACE, 'Fault');
        if ($faults->length === 0) {
            return null;
        }
        $fault = $faults->item(0);
        \assert($fault instanceof \DOMElement);

        $code = null;
        $label = '';
        $functional = $fault->getElementsByTagNameNS(self::SERVICE_NAMESPACE, 'FonctionnelleErreur');
        if ($functional->length > 0) {
            $error = $functional->item(0);
            \assert($error instanceof \DOMElement);
            $code = trim(self::child($error, 'code')?->textContent ?? '') ?: null;
            $label = trim(self::child($error, 'descriptif')?->textContent ?? '') ?: trim(self::child($error, 'libelle')?->textContent ?? '');
        }
        if ($label === '') {
            $label = trim(self::child($fault, 'faultstring')?->textContent ?? 'erreur sans libellé');
        }

        return new PayFipException(sprintf('PayFiP a refusé l\'appel (%s) : %s', $code ?? 'sans code', $label), $code);
    }

    private static function child(?\DOMNode $parent, string $localName): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }
}
