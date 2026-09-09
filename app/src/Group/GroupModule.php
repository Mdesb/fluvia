<?php

declare(strict_types=1);

namespace App\Group;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Group` — les **groupes de participants**, transverses aux métiers.
 *
 * **`capability()` renvoie `null` : c'est un service transverse** (comme OCR ou GED), consommé par les
 * verticales, ni vendu ni activable par établissement. Un exploitant qui reçoit des groupes le fait
 * quel que soit son métier ; en faire une capacité à cocher créerait une porte fermée là où il n'en
 * faut pas. C'est aussi ce qui l'exempte du garde-fou n°41 (catalogue de capacités).
 *
 * ⚠ Ce module n'a **rien à voir** avec `App\Organisation\Entity\Groupe` (le locataire / tenant). Le
 * nom anglais du module et de ses entités (`ParticipantGroup`, `GroupBooking`) lève l'ambiguïté (D5).
 *
 * `dependencies()` reste vide alors que `GroupBooking` référence `App\Reservation` et `App\Crm` :
 * comme `MuseeModule`, on ne déclare pas une dépendance vers un module sans manifeste (RG-PLAT-07
 * ferait échouer le démarrage). Le typage exprime déjà le lien.
 */
final class GroupModule implements ModuleManifest
{
    public function id(): string
    {
        return 'group';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'group.read',
            'group.manage',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
