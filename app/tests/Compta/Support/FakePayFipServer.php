<?php

declare(strict_types=1);

namespace App\Tests\Compta\Support;

use App\Compta\PayFip\PayFipSoapClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * THE TEST DOUBLE OF THE PAYFIP SERVICE (#339) — a server, not a stub of our adapter.
 *
 * It receives the SOAP envelopes our `PayFipSoapClient` really sends (through a `MockHttpClient`), keeps
 * the operations it was asked to open, and answers `recupererDetailPaiementSecurise` the way the DGFiP
 * guide describes (Régie Web service V18.0, §3.5.2.6): `P1` for an unknown operation, `P5` while the
 * buyer has not finished, the detail with RESULTRANS once the test has decided the outcome. Our code
 * therefore goes through its real XML building, real parsing and real checks.
 *
 * State is static: API tests reboot the kernel, the double must survive it. `reset()` in `setUp()`.
 */
final class FakePayFipServer
{
    /** @var array<string, array<string, string>> idOp => fields sent at creation */
    private static array $operations = [];
    /** @var array<string, array<string, string>> idOp => fields to answer (result + overrides) */
    private static array $answers = [];
    /** @var list<string> */
    private static array $calls = [];
    private static ?string $nextCreationFault = null;

    public static function reset(): void
    {
        self::$operations = [];
        self::$answers = [];
        self::$calls = [];
        self::$nextCreationFault = null;
    }

    /** The buyer finished on PayFiP: `P`/`V` paid, `R`/`Z` refused, `A` abandoned. */
    public static function decide(string $operationId, string $result, array $overrides = []): void
    {
        self::$answers[$operationId] = ['resultrans' => $result] + $overrides;
    }

    public static function failNextCreation(string $code): void
    {
        self::$nextCreationFault = $code;
    }

    /** @return array<string, array<string, string>> */
    public static function operations(): array
    {
        return self::$operations;
    }

    /** @return list<string> the operations called, in order */
    public static function calls(): array
    {
        return self::$calls;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $document = new \DOMDocument();
        $document->loadXML((string) ($options['body'] ?? ''), \LIBXML_NONET);
        $call = $document->getElementsByTagNameNS(PayFipSoapClient::SERVICE_NAMESPACE, '*')->item(0);
        $operation = $call?->localName ?? '';
        self::$calls[] = $operation;

        $arguments = [];
        foreach ($document->getElementsByTagName('arg0')->item(0)?->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement) {
                $arguments[$node->localName] = $node->textContent;
            }
        }

        if ($operation === 'creerPaiementSecurise') {
            if (self::$nextCreationFault !== null) {
                $code = self::$nextCreationFault;
                self::$nextCreationFault = null;

                return self::fault($code, 'Erreur simulée');
            }
            $operationId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
            self::$operations[$operationId] = $arguments;

            return self::answer('creerPaiementSecuriseResponse', ['idOp' => $operationId]);
        }

        if ($operation === 'recupererDetailPaiementSecurise') {
            $operationId = $arguments['idOp'] ?? '';
            if (!isset(self::$operations[$operationId])) {
                return self::fault('P1', 'IdOp incorrect');
            }
            if (!isset(self::$answers[$operationId])) {
                return self::fault('P5', 'Résultat de la transaction non connu');
            }
            $sent = self::$operations[$operationId];
            $answer = self::$answers[$operationId];
            $paid = \in_array($answer['resultrans'], ['P', 'V'], true);
            $test = ($sent['saisie'] ?? '') !== 'W';
            unset($answer['resultrans']);

            return self::answer('recupererDetailPaiementSecuriseResponse', $answer + [
                'dattrans' => '05112026',
                'exer' => $sent['exer'] ?? '',
                'heurtrans' => '1432',
                'idOp' => $operationId,
                'mel' => $sent['mel'] ?? '',
                'montant' => $sent['montant'] ?? '',
                'numauto' => $paid ? ($test ? 'XXXXXX' : '123456') : '',
                'numcli' => $sent['numcli'] ?? '',
                'objet' => $sent['objet'] ?? '',
                'refdet' => $sent['refdet'] ?? '',
                'resultrans' => self::$answers[$operationId]['resultrans'],
                'saisie' => $sent['saisie'] ?? '',
            ]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    /** @param array<string, string> $fields */
    private static function answer(string $element, array $fields): MockResponse
    {
        $children = '';
        foreach ($fields as $name => $value) {
            $children .= sprintf('<%1$s>%2$s</%1$s>', $name, htmlspecialchars($value, \ENT_XML1));
        }

        return new MockResponse(sprintf(
            '<?xml version="1.0" ?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><ns2:%1$s xmlns:ns2="%2$s"><return>%3$s</return></ns2:%1$s></S:Body></S:Envelope>',
            $element,
            PayFipSoapClient::SERVICE_NAMESPACE,
            $children,
        ), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/xml; charset=utf-8']]);
    }

    private static function fault(string $code, string $label): MockResponse
    {
        return new MockResponse(sprintf(
            '<?xml version="1.0" ?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><S:Fault><faultcode>S:Server</faultcode><faultstring>fr.gouv.finances.cp.tpa.webservice.exceptions.FonctionnelleErreur</faultstring><detail><ns2:FonctionnelleErreur xmlns:ns2="%s"><code>%s</code><descriptif>%s</descriptif><libelle>%s</libelle><severite>2</severite></ns2:FonctionnelleErreur></detail></S:Fault></S:Body></S:Envelope>',
            PayFipSoapClient::SERVICE_NAMESPACE,
            $code,
            htmlspecialchars($label, \ENT_XML1),
            htmlspecialchars($label, \ENT_XML1),
        ), ['http_code' => 500, 'response_headers' => ['content-type' => 'text/xml; charset=utf-8']]);
    }
}
