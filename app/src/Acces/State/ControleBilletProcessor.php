<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\ApiResource\ControleBillet;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\VerdictBilletHandler;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Controle manuel d'un billet (POST /acces/controle-billet). Corps : { "identifiantSupport": "..." }.
 *
 * @implements ProcessorInterface<mixed, ControleBillet>
 */
final class ControleBilletProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly VerdictBilletHandler $verdict,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ControleBillet
    {
        $corps = $this->lecteur->corps();
        $brut = $corps['identifiantSupport'] ?? null;
        $identifiant = \is_string($brut) ? trim($brut) : '';
        if ($identifiant === '') {
            throw new UnprocessableEntityHttpException('Identifiant de support obligatoire.');
        }

        // L'ETABLISSEMENT ACTIF EST EXIGE, ET CE N'EST PAS UNE FORMALITE.
        //
        // `Passage::setEspace()` derive l'etablissement DE L'ESPACE. Ici l'espace est nul, donc la
        // derivation ne se fait pas : sans cette exigence on ecrirait des passages sans
        // etablissement, invisibles au cloisonnement et donc lisibles par tous les sites. C'est le
        // meme defaut que la supervision corrigee ce matin, mais a l'ecriture — et il ne se verrait
        // pas, parce qu'une ligne sans etablissement ne se plaint jamais.
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException(
                'Etablissement actif requis (en-tete X-Etablissement) : un controle est rattache a un site.'
            );
        }

        $vue = new ControleBillet();
        $verdict = $this->verdict->evaluer($identifiant);

        if (!$verdict->valide) {
            $this->tracer($verdict->support, $verdict->droit, $etablissement, ResultatPassage::Refuse, $verdict->codeMotif, $verdict->message);

            $vue->resultat = 'refuse';
            $vue->codeMotif = $verdict->codeMotif?->value;
            $vue->libelleMotif = $verdict->message;
            $vue->billet = $this->billet($verdict->droit);

            return $vue;
        }

        $droit = $verdict->droit;
        $support = $verdict->support;
        $credit = $droit?->getCreditRestant();

        // DEUX REGIMES, ET ILS NE SE CONFONDENT PAS.
        //
        // Un droit qui porte un credit est multi-usage : il se decompte, et l'epuisement se dit
        // `credit_epuise` — le geste est de recharger a la caisse. Un droit sans credit est une
        // entree unique : un second controle n'epuise rien, il revele que le billet a deja servi, et
        // le geste est une QUESTION, pas une recharge. D'ou deux motifs distincts : la supervision
        // compte les refus par motif, et un motif emprunte est une statistique fausse.
        if ($credit === null) {
            $precedent = $this->dernierControleValide($support?->getId());
            if ($precedent instanceof Passage) {
                $this->tracer($support, $droit, $etablissement, ResultatPassage::Refuse, CodeMotifRefus::DejaConsomme, 'Billet deja controle.');

                $vue->resultat = 'refuse';
                $vue->codeMotif = CodeMotifRefus::DejaConsomme->value;
                $vue->libelleMotif = 'Billet deja controle.';
                $vue->billet = $this->billet($droit);
                $vue->dejaControleLe = $precedent->getHorodatage()->format(\DATE_ATOM);

                return $vue;
            }
        } elseif ($credit <= 0) {
            $this->tracer($support, $droit, $etablissement, ResultatPassage::Refuse, CodeMotifRefus::CreditEpuise, 'Credit epuise.');

            $vue->resultat = 'refuse';
            $vue->codeMotif = CodeMotifRefus::CreditEpuise->value;
            $vue->libelleMotif = 'Credit epuise.';
            $vue->billet = $this->billet($droit);
            $vue->credit = ['restant' => $credit];

            return $vue;
        } else {
            $droit->setCreditRestant($credit - 1);
        }

        $this->tracer($support, $droit, $etablissement, ResultatPassage::Valide, null, 'Controle manuel.');

        $vue->resultat = 'valide';
        $vue->libelleMotif = 'Billet valide.';
        $vue->billet = $this->billet($droit);
        $vue->credit = $credit === null ? null : ['restant' => $credit - 1];
        $vue->consomme = true;

        return $vue;
    }

    /**
     * LE CONTROLE MANUEL LAISSE UNE TRACE, SANS ESPACE ET SANS EQUIPEMENT (D86).
     *
     * Sans elle, l'historique des passages aurait un trou exactement la ou il n'y a pas de materiel
     * — donc la ou l'on a le plus besoin de savoir qui est entre.
     */
    private function tracer(
        ?Support $support,
        ?DroitAcces $droit,
        Etablissement $etablissement,
        ResultatPassage $resultat,
        ?CodeMotifRefus $codeMotif,
        string $motif,
    ): void {
        $agent = $this->security->getUser();

        $passage = new Passage();
        $passage->setHorodatage(new \DateTimeImmutable())
            ->setEspace(null)
            ->setEtablissement($etablissement)
            ->setSupport($support)
            ->setDroit($droit)
            ->setSens(SensPassage::Entree)
            ->setResultat($resultat)
            ->setCodeMotif($codeMotif)
            ->setMotif($motif)
            ->setAgent($agent instanceof Utilisateur ? $agent : null);

        $this->em->persist($passage);
        $this->em->flush();
    }

    private function dernierControleValide(?Uuid $supportId): ?Passage
    {
        if (!$supportId instanceof Uuid) {
            return null;
        }

        return $this->em->getRepository(Passage::class)->findOneBy(
            ['support' => $supportId, 'resultat' => ResultatPassage::Valide],
            ['horodatage' => 'DESC'],
        );
    }

    /** @return array{produit: ?string, porteur: ?string}|null */
    private function billet(?DroitAcces $droit): ?array
    {
        if (!$droit instanceof DroitAcces) {
            return null;
        }

        return [
            'produit' => $droit->getSourceType()->value,
            'porteur' => null,
        ];
    }
}
