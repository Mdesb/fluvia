<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

        // ── ⚠ UN ETABLISSEMENT N'A QU'UNE CONFIGURATION, ET LE DIRE EST LE TRAVAIL DU PROCESSEUR ──
        //
        // La table porte `uniq_config_creancier_etablissement`. Sans ce controle, un second POST
        // remonte jusqu'au flush et la contrainte le refuse en `UniqueConstraintViolationException`,
        // qu'API Platform rend en **500 « Internal Server Error »** — un corps sans un mot sur la
        // cause. Mesure du 02/09 : j'ai lu ce 500 comme une panne du serveur et je suis alle
        // chercher dans les journaux du conteneur, alors que la reponse aurait pu me le dire.
        //
        // Un 500 dit « le logiciel est casse ». Ici rien n'est casse : la demande est refusee, et
        // pour une raison que l'appelant peut corriger seul — il voulait un PATCH.
        $etablissement = $data->getEtablissement();

        if ($operation instanceof Post && $etablissement !== null) {
            $existante = $this->em->getRepository(ConfigCreancierSepa::class)
                ->findOneBy(['etablissement' => $etablissement]);

            if ($existante instanceof ConfigCreancierSepa) {
                throw new ConflictHttpException(sprintf(
                    'Cet etablissement a deja une configuration creancier SEPA (« %s », ICS %s). '
                    . 'Un etablissement n en porte qu une : modifiez celle-ci par un PATCH sur %s.',
                    $existante->getCreancierNom(),
                    $existante->getIcs(),
                    '/api/config_creancier_sepas/' . $existante->getId(),
                ));
            }
        }

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
