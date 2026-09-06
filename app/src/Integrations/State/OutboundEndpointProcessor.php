<?php

declare(strict_types=1);

namespace App\Integrations\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Integrations\Crypto\WebhookUrlCipher;
use App\Integrations\Entity\OutboundEndpoint;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pose l'établissement, chiffre l'URL, et la laisse tomber.
 *
 * ⚠ **L'ÉTABLISSEMENT VIENT DU SERVEUR, JAMAIS DU CORPS.** Une destination déposée dans
 * l'établissement d'un voisin lui ferait recevoir les événements d'autrui chez lui — et l'auteur
 * n'aurait eu qu'à changer un identifiant dans une requête.
 *
 * ⚠ **L'URL EST OBLIGATOIRE À LA CRÉATION, FACULTATIVE À LA MODIFICATION.** Renommer une
 * destination ou changer ses événements ne doit pas obliger à recoller la clé : personne ne l'a
 * sous la main, et l'exiger pousserait à la stocker ailleurs, en clair, dans un fichier partagé.
 *
 * @implements ProcessorInterface<OutboundEndpoint, OutboundEndpoint>
 */
final class OutboundEndpointProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebhookUrlCipher $coffre,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OutboundEndpoint
    {
        \assert($data instanceof OutboundEndpoint);

        $creation = $data->getEstablishment() === null;

        if ($creation) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de savoir à qui appartiendrait cette destination.',
                );
            }
            $data->setEstablishment($etablissement);
        }

        $url = $data->url;
        if ($url !== null && trim($url) !== '') {
            $url = trim($url);

            // ⚠ HTTPS EXIGÉ, ET CE N'EST PAS UNE PRÉFÉRENCE. L'URL EST la clé : en http elle
            // traverse le réseau en clair à chaque envoi, et il suffit de l'observer une fois pour
            // pouvoir publier au nom de l'établissement pour toujours.
            if (!str_starts_with($url, 'https://')) {
                throw new UnprocessableEntityHttpException(
                    'L\'adresse doit être en https : cette URL vaut un mot de passe, elle ne peut pas circuler en clair.',
                );
            }

            $hote = parse_url($url, \PHP_URL_HOST);
            if (!\is_string($hote) || $hote === '') {
                throw new UnprocessableEntityHttpException('Adresse illisible : le nom d\'hôte est introuvable.');
            }

            $data->setUrlChiffree($this->coffre->chiffrer($url));
            $data->setHote($hote);
            // Une modification d'URL efface le dernier échec : il portait sur l'adresse d'avant, et
            // le laisser ferait croire que la nouvelle est déjà cassée.
            $data->setDernierEchec(null);
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException(
                'Une destination sans adresse n\'enverrait rien : renseignez l\'URL du webhook.',
            );
        }

        // On la laisse tomber tout de suite. Le groupe de lecture ne la contient pas, donc elle ne
        // repartirait pas dans la réponse d'aujourd'hui — mais la propriété est PUBLIQUE, et il
        // suffira d'un `dump()`, d'un rapport d'exception ou d'un groupe élargi un jour pour qu'elle
        // ressorte. Une valeur qu'on ne garde pas ne fuit par aucun de ces chemins.
        $data->url = null;

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
