<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création/mise à jour de la configuration créancier SEPA d'un établissement (plan §2/§6). Si
 * `variante` n'est pas fournie explicitement, elle est dérivée du `ProfilExploitant.type` couvrant
 * l'établissement (régie directe ⇒ `REGIE`, DSP/groupe privé ou aucun profil ⇒ `PRIVE`) —
 * surchargeable en la fournissant explicitement dans le corps de la requête.
 *
 * @implements ProcessorInterface<ConfigCreancierSepa, ConfigCreancierSepa>
 */
final class ConfigCreancierSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConfigCreancierSepa
    {
        \assert($data instanceof ConfigCreancierSepa);

        if ($data->getVariante() === null) {
            $data->setVariante($this->resoudreVarianteParDefaut($data->getEtablissement()));
        }

        $ibanClair = $data->getCreancierIbanClair();
        if (trim($ibanClair) !== '') {
            $token = $this->tokenisation->tokeniser($ibanClair);
            $data->setCreancierIbanToken($token->token)
                ->setCreancierIban4Derniers($token->quatreDerniers)
                ->setCreancierIbanChiffre($this->chiffreur->chiffrer($ibanClair));
        }
        $data->setCreancierIbanClair('');

        $data->toucherModifieLe();
        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    private function resoudreVarianteParDefaut(?Etablissement $etablissement): VarianteCreancierSepa
    {
        if ($etablissement === null) {
            return VarianteCreancierSepa::Prive;
        }

        /** @var list<ProfilExploitant> $profils */
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();
        foreach ($profils as $profil) {
            if ($profil->couvre($etablissement)) {
                return $profil->getType() === TypeExploitant::RegieDirecte
                    ? VarianteCreancierSepa::Regie
                    : VarianteCreancierSepa::Prive;
            }
        }

        return VarianteCreancierSepa::Prive;
    }
}
