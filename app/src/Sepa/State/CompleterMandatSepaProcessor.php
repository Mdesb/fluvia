<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sepa/mandats/{id}/completer — capture l'IBAN d'un mandat « en attente » et l'active. Le mode
 * « pending » de la vente d'un abonnement au comptoir (arbitrage de Maxime « ça peut être les trois »)
 * crée un mandat SANS IBAN : le client règle le 1er mois, mais donnera son IBAN plus tard. Corps :
 *   { "iban": string, "titulaire": string, "bic"?: string }
 *
 * ⚠ TANT QUE LE MANDAT EST « EN ATTENTE », IL N'EST JAMAIS PRÉLEVÉ. `GenerationRemiseHandler` exclut
 *   toute échéance dont le mandat n'est pas `Actif` (même filtre que pour un mandat révoqué) : aucune
 *   remise pain.008 ne part sur un IBAN absent. Cette route est le seul chemin qui l'active.
 *
 * ⚠ L'IBAN EN CLAIR NE TRANSITE QU'ICI : tokenisé (non réversible, `ibanToken`) ET chiffré
 *   (réversible, `ibanChiffre`) avant persistance, jamais mappé Doctrine en clair (§4 spec), comme
 *   `CreerMandatSepaProcessor`.
 *
 * `read: true` sans provider : le cloisonnement par établissement vient de `PerimetreSepaExtension`
 * (comme la révocation) — le mandat d'un autre établissement rend 404, jamais complétable.
 *
 * @implements ProcessorInterface<MandatSepa, MandatSepa>
 */
final class CompleterMandatSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MandatSepa
    {
        \assert($data instanceof MandatSepa);

        // On refuse plutôt que de rendre 200 en silence : compléter un mandat déjà actif écraserait
        // un IBAN valide (et une remise a pu partir dessus) ; compléter un mandat révoqué le
        // ressusciterait. Seul « en attente » est complétable.
        if (StatutMandatSepa::EnAttente !== $data->getStatut()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Seul un mandat « en attente » peut être complété ; celui-ci est « %s ». Rien n\'a été modifié.',
                $data->getStatut()->value,
            ));
        }

        $corps = $this->lecteur->corps();
        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $titulaire = \is_string($corps['titulaire'] ?? null) ? $corps['titulaire'] : '';
        $bic = \is_string($corps['bic'] ?? null) ? $corps['bic'] : '';
        if (trim($iban) === '' || trim($titulaire) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « titulaire » sont requis pour signer le mandat SEPA.');
        }

        $token = $this->tokenisation->tokeniser($iban);
        $data->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer($iban))
            ->setDebiteurNom($titulaire)
            ->setBicDebiteur($bic)
            ->setDateSignature(new \DateTimeImmutable('today'))
            ->setStatut(StatutMandatSepa::Actif);
        $this->em->flush();

        return $data;
    }
}
