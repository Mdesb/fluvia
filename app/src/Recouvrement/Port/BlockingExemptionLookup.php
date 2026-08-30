<?php

declare(strict_types=1);

namespace App\Recouvrement\Port;

/**
 * « Ce redevable est-il exempté de tout blocage ? » — la seule chose que la propagation ait besoin
 * de savoir des exemptions.
 *
 * ── POURQUOI UN PORT PLUTÔT QUE LE REGISTRE DIRECTEMENT ────────────────────────────────────────
 *
 * `BlockingExemptionRegistry` sait aussi ACCORDER et RETIRER. `PropagationAccesHandler` n'a rien à
 * faire de ces deux pouvoirs : il pose une question, il ne décide pas d'exempter. Dépendre de la
 * classe entière lui donnerait, en lecture, l'air de pouvoir en poser une — et le jour où quelqu'un
 * cherchera « qui accorde des exemptions », il trouvera la propagation dans la liste.
 *
 * ⚠ C'EST UN TEST QUI L'A RÉVÉLÉ, ET IL AVAIT RAISON POUR UNE AUTRE RAISON QUE LA MIENNE. Le test
 * unitaire de la propagation devait fournir un registre ; j'y ai mis un double qui LEVAIT une
 * exception « ce cas ne doit pas consulter les exemptions ». Il l'a levée : `desactiver()` les
 * consulte, évidemment, puisque c'est là qu'on refuse de bloquer un exempté. Mon double était faux,
 * mais il a montré que ces cas-là ne devraient avoir à fournir qu'une réponse booléenne — pas une
 * infrastructure de persistance pour un test qui ne touche pas la base.
 */
interface BlockingExemptionLookup
{
    public function estExempte(string $typeRedevable, string $referenceRedevable): bool;
}
