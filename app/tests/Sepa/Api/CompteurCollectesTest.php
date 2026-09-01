<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Tests\Sepa\SepaApiTestCase;

/**
 * TRANSMETTRE N'EST PAS COLLECTER — ET LE COMPTEUR NE DOIT PLUS BOUGER A LA TRANSMISSION.
 *
 * `GenerationRemiseHandler` incrementait `nbCollectesReussies` sur chaque mandat juste apres
 * `CollecteurSepaInterface::transmettre()`, en lisant le retour du port comme un accuse de
 * reception. Il n'en est pas un — et il ne le serait pas davantage avec une vraie banque :
 * transmettre un pain.008 n'est pas collecter. La collecte se confirme des jours plus tard, par
 * l'absence de rejet ou par un credit CAMT.
 *
 * ── ⚠ CE QUE CE COMPTEUR DECIDE, ET POURQUOI C'EST DE L'ARGENT ──────────────────────────────────
 *
 * `SeqTpResolver` en deduit le type de sequence SEPA : `RCUR` des qu'il depasse zero, `FRST` sinon.
 * Un mandat compte a tort part donc en RCUR a son PREMIER prelevement reel — motif de rejet
 * bancaire classique, mandat par mandat, qui ne se manifeste qu'a la mise en service chez un client.
 *
 * ── ET LE DEFAUT ETAIT ATTEIGNABLE AUJOURD'HUI, PAS SEULEMENT AU RACCORDEMENT ───────────────────
 *
 * Mesure faite : un seul endroit incrementait ce compteur, AUCUN ne le decrementait, et
 * `DeclarerRejetSepaProcessor` — la saisie manuelle d'un rejet, qui existe et a son ecran — lit le
 * mandat sans y toucher. Un mandat rejete restait donc compte comme collecte, et sa presentation
 * suivante partait en RCUR. Le bouchon n'etait qu'une des deux causes.
 *
 * ── CE QUE CE TEST MESURE, ET COMMENT JE SAIS QU'IL MESURE ──────────────────────────────────────
 *
 * Les fixtures generent une remise de demonstration EN PASSANT PAR `GenerationRemiseHandler` — leur
 * en-tete le dit et le code l'appelle. Sous l'ancien comportement, le compteur du mandat regie
 * valait donc 1 apres chargement. Il doit valoir 0.
 *
 * ⚠ VERIFIE EN CASSANT LE FILET : l'incrementation a ete remise une minute, et ce test l'a
 * designee. Un test de non-regression qu'on n'a pas vu echouer ne prouve pas qu'il regarde au bon
 * endroit — celui-ci a ete vu rouge avant d'etre cru vert.
 */
final class CompteurCollectesTest extends SepaApiTestCase
{
    public function testUneRemiseTransmiseNIncrementePasLeCompteurDeCollectes(): void
    {
        // ── TEMOIN : LA REMISE A BIEN ETE GENEREE ET TRANSMISE ──────────────────────────────────
        //
        // Sans lui, un compteur a zero se lirait comme une preuve alors qu'il signifierait
        // simplement que rien ne s'est passe. C'est la forme du faux vert la plus courante :
        // l'absence d'effet mesuree sur une absence de cause.
        $remise = $this->entite(RemiseSepa::class, ['statut' => 'transmise']);
        self::assertInstanceOf(RemiseSepa::class, $remise, 'temoin : les fixtures doivent avoir transmis une remise');
        self::assertNotNull($remise->getReferenceTransmission(), 'temoin : la transmission a bien eu lieu');

        /** @var MandatSepa $mandat */
        $mandat = $this->entite(MandatSepa::class, ['rum' => 'RUM-DEMO-REGIE-0001']);

        self::assertSame(
            0,
            $mandat->getNbCollectesReussies(),
            'transmettre un pain.008 n\'est pas collecter : le compteur ne doit pas bouger a la '
            . 'transmission. `SeqTpResolver` en deduit RCUR des qu\'il depasse zero, et un mandat '
            . 'compte a tort part en RCUR a son premier prelevement reel — motif de rejet bancaire.',
        );
    }

    /**
     * ⚠ LA CONSEQUENCE ASSUMEE, CLOUEE ICI POUR QU'ELLE SOIT UNE DECISION.
     *
     * Plus rien n'incremente ce compteur : toutes les remises partent donc en `FRST` jusqu'a ce
     * qu'un vrai collecteur confirme des collectes. C'est le sens SUR de l'erreur — un FRST
     * presente a tort est generalement accepte, un RCUR presente a tort est rejete.
     *
     * Le jour ou le raccordement arrive, ce test devient FAUX et c'est normal : il faudra
     * incrementer a la confirmation de collecte ET decrementer sur rejet. Les deux ensemble, sinon
     * on reconstruit le meme defaut dans l'autre sens. Ce test est la pour que ce jour-la se
     * remarque.
     */
    public function testToutesLesRemisesPartentEnFrstTantQuAucuneCollecteNEstConfirmee(): void
    {
        $remise = $this->entite(RemiseSepa::class, ['statut' => 'transmise']);
        self::assertInstanceOf(RemiseSepa::class, $remise);

        self::assertSame(
            'FRST',
            $remise->getSeqTp()?->value,
            'aucune collecte n\'etant confirmee, la sequence doit rester FRST',
        );
    }
}
