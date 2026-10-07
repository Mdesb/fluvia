<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Service\ValidationPassageHandler;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Deux passages concurrents sur une carte à 5 entrées en laissent 3, pas 4.
 *
 * Le décompte s'écrit par un `UPDATE ... SET credit_restant = credit_restant - 1` conditionnel :
 * correct sous concurrence. Mais le handler recopiait ensuite `ancienne_valeur_en_mémoire - 1` sur
 * l'objet, et le `flush()` final réécrivait cette valeur ABSOLUE par-dessus. Si un autre passage
 * avait décompté entre la lecture du droit et cet `UPDATE`, son décompte était effacé : une entrée
 * gratuite. Même défaut, même remède que `CardRechargeHandler` (CA-7) : recharger le droit.
 *
 * La concurrence est simulée sans fil d'exécution : le droit est chargé dans la map d'identité
 * (5), puis un passage concurrent est écrit directement en base (4), puis le passage testé passe
 * par le vrai handler, qui retrouve l'instance périmée.
 */
final class ConcurrentCreditDecrementTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    public function testUnPassageApresUnDecompteConcurrentNeLEffacePas(): void
    {
        [$droitId, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 1, 5);
        $equipement = Uuid::fromString($this->idEquipement());
        self::assertSame(5, $this->findRight($droitId)->getCreditRestant(), 'témoin : le droit est en mémoire à 5');

        $connexion = $this->snapshotEm()->getConnection();
        $hex = bin2hex(Uuid::fromString($droitId)->toBinary());
        $connexion->executeStatement('UPDATE acces_droit_acces SET credit_restant = credit_restant - 1 WHERE id = UNHEX(:h)', ['h' => $hex]);

        /** @var ValidationPassageHandler $handler */
        $handler = static::getContainer()->get(ValidationPassageHandler::class);
        $passage = $handler->valider(new EvenementPassageDto($equipement, $identifiant, null, new \DateTimeImmutable(), Uuid::v4()));
        self::assertSame(ResultatPassage::Valide, $passage->getResultat(), (string) $passage->getMotif());

        self::assertSame(3, (int) $connexion->fetchOne('SELECT credit_restant FROM acces_droit_acces WHERE id = UNHEX(:h)', ['h' => $hex]), 'Deux décomptes : 5 - 2 = 3. 4 = un passage gratuit.');
        self::assertSame(3, $this->findRight($droitId)->getCreditRestant(), 'L\'objet en mémoire dit la valeur de la base.');
    }
}
