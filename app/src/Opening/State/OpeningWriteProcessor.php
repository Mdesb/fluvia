<?php

declare(strict_types=1);

namespace App\Opening\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Opening\Entity\OpeningException;
use App\Opening\Entity\OpeningSlot;
use App\Opening\Enum\OpeningExceptionType;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * L'ÉCRITURE D'UN PLANNING D'OUVERTURE : l'établissement vient du contexte, jamais du corps.
 *
 * ── POURQUOI CE PROCESSOR EXISTE, ET CE QU'IL EMPÊCHE ───────────────────────────────────────────
 *
 * `etablissement` n'est pas dans le groupe d'écriture, donc le client ne peut pas le poser — mais
 * ça ne suffit pas : sans processor, la propriété resterait `null` et l'écriture échouerait sur une
 * contrainte de base, avec un message que personne ne peut interpréter. C'est ici qu'on la pose,
 * depuis l'en-tête `X-Etablissement` résolu par le socle.
 *
 * ⚠ **L'ESPACE EST RECONFRONTÉ AU PÉRIMÈTRE, ET C'EST LE POINT DE SÉCURITÉ.** L'espace, lui,
 * ARRIVE du corps de requête. Les extensions Doctrine `Perimetre*` ne filtrent que les LECTURES
 * d'API Platform : un identifiant d'espace fourni à l'écriture sort du filet sans que rien ne le
 * signale (D8, garde-fou n°1). Sans la vérification ci-dessous, on pourrait accrocher les horaires
 * de son propre site à l'espace du voisin — et, le réglage activé, décider quand SA porte s'ouvre.
 *
 * @implements ProcessorInterface<OpeningSlot|OpeningException, OpeningSlot|OpeningException>
 */
final readonly class OpeningWriteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<OpeningSlot|OpeningException, OpeningSlot|OpeningException> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof OpeningSlot || $data instanceof OpeningException);

        $etablissement = $data->getEstablishment() ?? $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException(
                'Aucun établissement actif : le planning d’ouverture appartient à un site, pas à un compte.',
            );
        }
        $data->setEstablishment($etablissement);

        $espace = $data->getSpace();
        if ($espace !== null && $espace->getEtablissement()?->getId()?->equals($etablissement->getId()) !== true) {
            // 404 aurait été le choix d'un GET ; ici l'espace existe et l'auteur a le droit d'écrire
            // sur SON site — ce qu'on refuse est le rattachement, et le dire évite un « réessayez ».
            throw new UnprocessableEntityHttpException(
                'Cet espace d’accès n’appartient pas à l’établissement actif.',
            );
        }

        if ($data instanceof OpeningException) {
            $this->verifierException($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Une ouverture exceptionnelle sans heures n'a pas de sens — « ouvert, mais quand ? » — et
     * produirait une journée réputée ouverte vingt-quatre heures, donc une porte qui laisse passer
     * la nuit. Une fermeture sans heures, elle, est le cas le plus courant : la journée entière.
     */
    private function verifierException(OpeningException $exception): void
    {
        if ($exception->getType() === OpeningExceptionType::SpecialOpening && $exception->isAllDay()) {
            throw new UnprocessableEntityHttpException(
                'Une ouverture exceptionnelle demande une heure de début et une heure de fin : '
                . 'sans elles, la journée serait réputée ouverte vingt-quatre heures.',
            );
        }

        $debut = $exception->getStartTime();
        $fin = $exception->getEndTime();
        if ($debut !== null && $fin !== null && $fin <= $debut) {
            throw new UnprocessableEntityHttpException(
                'L’heure de fin doit suivre l’heure de début. Une exception ne traverse pas minuit : '
                . 'saisissez-en une par journée concernée.',
            );
        }
    }
}
