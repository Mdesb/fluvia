<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Notification\ExpediteurCourriel;
use PHPUnit\Framework\TestCase;

/**
 * « UN COURRIEL PARTIRA-T-IL ? » — le fait que six ecrans doivent cesser de promettre.
 *
 * Six services de ce depot composent un courriel et n'envoient rien : `MAILER_DSN=null://null`
 * avale tout en silence. Les ecrans annoncent l'envoi quand meme.
 *
 * ⚠ CE QUE CES TESTS PROTEGENT N'EST PAS LE CALCUL — il tient en une ligne — C'EST SON SENS.
 *
 * On reconnait les transports qui N'ENVOIENT PAS, jamais ceux qui envoient. Une liste blanche
 * (smtp, ses, mailgun, brevo…) serait fausse le jour ou quelqu'un branche un transport qui n'y
 * figure pas, et fausse dans le MAUVAIS sens : « aucun expediteur » sur une instance qui en a un,
 * donc des boutons eteints sans raison. La liste des transports inertes, elle, est courte et stable.
 */
final class ExpediteurCourrielTest extends TestCase
{
    public function testLeTransportNulNEnvoiePas(): void
    {
        self::assertFalse((new ExpediteurCourriel('null://null'))->estBranche());
    }

    /**
     * ⚠ LE DOUTE FERME, ET LE SENS EST DELIBERE.
     *
     * Une valeur absente rend « pas d'expediteur ». L'erreur dans ce sens laisse un bouton eteint
     * alors qu'il pouvait servir : visible, signalable, corrigible. L'erreur inverse fait croire a
     * un envoi qui n'a pas lieu, et personne ne s'en apercoit — c'est le defaut qu'on corrige.
     */
    public function testUneConfigurationAbsenteNePrometRien(): void
    {
        self::assertFalse((new ExpediteurCourriel(''))->estBranche());
        self::assertFalse((new ExpediteurCourriel('   '))->estBranche());
    }

    /**
     * TEMOIN POSITIF, ET IL PORTE LA MOITIE DU SENS.
     *
     * Sans lui, un `estBranche()` qui rendrait `false` en toute circonstance passerait les deux
     * tests precedents. On saurait que le service sait dire non ; on ne saurait pas qu'il sait dire
     * oui — et c'est ce oui qui fera rallumer six ecrans le jour venu.
     */
    public function testUnVraiTransportEstReconnuCommeBranche(): void
    {
        self::assertTrue((new ExpediteurCourriel('smtp://localhost:1025'))->estBranche());
        self::assertTrue((new ExpediteurCourriel('brevo+api://cle@default'))->estBranche());
        self::assertTrue((new ExpediteurCourriel('sendmail://default'))->estBranche());
    }

    /**
     * Un transport invente doit etre tenu pour branche : on ne connait pas la liste des transports
     * reels, et pretendre la connaitre eteindrait des ecrans chez qui en emploie un que nous
     * n'avions pas prevu.
     */
    public function testUnTransportInconnuEstTenuPourBranche(): void
    {
        self::assertTrue((new ExpediteurCourriel('zzz-transport-invente://quelquepart'))->estBranche());
    }
}
