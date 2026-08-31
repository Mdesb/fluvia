<?php

declare(strict_types=1);

namespace App\Calendar\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Calendar\Entity\CalendarEvent;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * QUI ÉCRIT QUOI DANS L'AGENDA — et pourquoi la règle n'est pas dans l'attribut `security`.
 *
 * L'attribut `security` d'API Platform décide d'après QUI APPELLE. Ici la règle dépend aussi de CE
 * QUE LA CHARGE UTILE CONTIENT : poser un événement du site (`duSite: true`) est un geste
 * d'exploitant ; poser le sien est un geste ordinaire. Une seule opération, deux exigences — la
 * décision revient donc au processor.
 *
 * ⚠ **`proprietaire` n'est jamais lu du corps de requête.** Il est posé ici, à partir du compte
 * authentifié. L'accepter du client laisserait écrire dans l'agenda personnel de quelqu'un d'autre
 * — et un agenda personnel est précisément ce que personne d'autre ne doit toucher.
 *
 * @implements ProcessorInterface<CalendarEvent, CalendarEvent>
 */
final readonly class CalendarEventProcessor implements ProcessorInterface
{
    /** Ce qu'il faut porter pour écrire dans l'agenda DU SITE, et non dans le sien. */
    private const DROITS_DU_SITE = [
        ['organisation', 'gerer'],
        ['personnel', 'gerer'],
        ['reservation', 'gerer_creneau'],
    ];

    /**
     * @param ProcessorInterface<CalendarEvent, CalendarEvent> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private ContexteEtablissement $contexte,
        private Security $security,
        private CalculateurDroits $calculateur,
        private RequestStack $requetes,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof CalendarEvent);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Aucun utilisateur authentifié.');
        }

        $etablissement = $data->getEstablishment() ?? $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException(
                'Aucun établissement actif : un événement d’agenda appartient à un site.',
            );
        }
        $data->setEstablishment($etablissement);

        if ($this->demandeUnEvenementDuSite()) {
            if (!$this->peutEcrirePourLeSite($utilisateur, $etablissement)) {
                throw new AccessDeniedHttpException(
                    'Poser un événement dans l’agenda du site demande un droit que votre profil n’a pas. '
                    . 'Vous pouvez en revanche l’ajouter à votre propre agenda.',
                );
            }
            $data->setOwner(null);
        } else {
            // MODIFICATION D'UN ÉVÉNEMENT EXISTANT : on ne réattribue pas un événement du site à la
            // personne qui le corrige. Sans cette garde, le premier exploitant à rectifier une
            // heure ferait disparaître la réunion de l'agenda de tous les autres.
            if ($data->getOwner() === null && $operation instanceof \ApiPlatform\Metadata\Post) {
                $data->setOwner($utilisateur);
            } elseif ($data->getOwner() === null && !$this->peutEcrirePourLeSite($utilisateur, $etablissement)) {
                throw new AccessDeniedHttpException('Cet événement appartient à l’agenda du site.');
            }
        }

        // ⚠ NORMALISATION EN UTC AVANT DE PERSISTER — ET C'EST INDISPENSABLE.
        //
        // Le type Doctrine `datetime_immutable` ne convertit AUCUN fuseau : il formate l'objet tel
        // quel et écrit l'heure murale. Une réunion envoyée `09:00:00+02:00` était donc stockée
        // « 09:00:00 », puis relue dans le fuseau par défaut du processus — UTC dans ce conteneur —
        // et ressortait `09:00:00+00:00`. L'heure murale conservée, l'instant décalé de deux heures.
        //
        // Vu au navigateur : une réunion créée à 9 h s'affichait à 11 h. Rien n'avait levé.
        $data->setStart($this->enUtc($data->getStart()));
        $data->setEnd($this->enUtc($data->getEnd()));

        $debut = $data->getStart();
        $fin = $data->getEnd();
        if ($debut !== null && $fin !== null && $fin <= $debut) {
            throw new UnprocessableEntityHttpException('L’heure de fin doit suivre l’heure de début.');
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Le même INSTANT, exprimé en UTC. On ne change pas le moment, on change la façon de l'écrire —
     * et c'est cette façon-là que la base sait relire sans se tromper.
     */
    private function enUtc(?\DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        return $moment?->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * `duSite` est un drapeau de la REQUÊTE, pas une propriété de l'entité : c'est une intention,
     * et elle ne se stocke pas. Ce qui se stocke est son résultat — un propriétaire, ou pas.
     */
    private function demandeUnEvenementDuSite(): bool
    {
        $requete = $this->requetes->getCurrentRequest();
        $corps = json_decode((string) $requete?->getContent(), true);

        return \is_array($corps) && ($corps['siteWide'] ?? false) === true;
    }

    private function peutEcrirePourLeSite(Utilisateur $utilisateur, Etablissement $etablissement): bool
    {
        $codes = $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId());
        foreach (self::DROITS_DU_SITE as [$module, $action]) {
            if ($this->calculateur->autorise($codes, $module, $action)) {
                return true;
            }
        }

        return false;
    }
}
