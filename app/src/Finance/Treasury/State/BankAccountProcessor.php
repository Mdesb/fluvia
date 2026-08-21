<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\BankAccount;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/api/bank_accounts` (§0.2 point 1, §0.3 du plan, D8 explicite) : `establishment` du corps
 * revérifié via `PerimetreEtablissementVerificateur` (Stock, réutilisé une 4ᵉ fois hors de son module
 * d'origine, §7 point 2 du plan) — 403 sinon. `ledgerAccount` (si fourni) doit être couvert par le même
 * établissement via `CompteComptable::getProfilExploitant()->couvre()` — 422 sinon (IDOR inter-profils).
 *
 * Chiffrement IBAN (§0.3) : si `ibanClear` est fourni (création ou ré-saisie), il est chiffré via le
 * coffre SEPA réutilisé tel quel (`ChiffreurIbanInterface`) et les 4 derniers caractères extraits pour
 * `ibanLast4` — jamais persisté en clair, jamais journalisé, jamais renvoyé (CA-1). Une modification qui
 * ne fournit pas `ibanClear` conserve l'IBAN déjà chiffré (PATCH partiel).
 *
 * @implements ProcessorInterface<BankAccount, BankAccount>
 */
final class BankAccountProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly ChiffreurIbanInterface $chiffreurIban,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BankAccount
    {
        \assert($data instanceof BankAccount);
        $creation = !isset($uriVariables['id']);

        // D8 — échec fermé (403) : réutilise tel quel le service Stock (§0.2 point 1 du plan).
        $etablissement = $this->perimetre->verifier($data->getEstablishment());

        $compteComptable = $data->getLedgerAccount();
        if ($compteComptable !== null && !($compteComptable->getProfilExploitant()?->couvre($etablissement) ?? false)) {
            throw new UnprocessableEntityHttpException('Compte comptable hors du périmètre de l\'établissement (IDOR inter-profils).');
        }

        if ($data->getIbanClear() !== '') {
            $ibanNormalise = strtoupper(str_replace(' ', '', $data->getIbanClear()));
            $data->setIbanCipher($this->chiffreurIban->chiffrer($ibanNormalise));
            $data->setIbanLast4(substr($ibanNormalise, -4));
        }
        // Ne jamais laisser transiter la valeur transitoire au-delà du processor.
        $data->setIbanClear('');

        if ($creation) {
            $acteur = $this->security->getUser();
            if ($acteur instanceof Utilisateur) {
                $data->setCreatedBy($acteur);
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
