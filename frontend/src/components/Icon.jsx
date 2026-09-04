/**
 * LES PUCES DE MENU ETAIENT DES GLYPHES UNICODE, ET ELLES MENTAIENT SUR LA DESTINATION.
 *
 * Relevé avant de dessiner : 53 entrées de menu pour 35 glyphes distincts. Le problème n'était pas
 * qu'ils soient laids — c'est que **sept d'entre eux désignaient des destinations sans rapport** :
 *
 *     ▤   Caisse   ·   Agenda   ·   Facturation      (trois écrans, une seule puce)
 *     ▥   Catalogue   ·   Offres
 *     ◨   Affaires   ·   Reporting
 *     €   Facturation   ·   Achats & trésorerie
 *     ⇄   Règlements   ·   Prélèvements SEPA
 *     ◈   Accès support   ·   Campagnes
 *
 * Une puce qui vaut pour trois écrans n'aide pas à choisir : elle fait croire à un repère et le
 * retire. Et l'œil s'en sert avant de lire le libellé — c'est même tout son intérêt.
 *
 * ⚠ ET NEUF GLYPHES ETAIENT HORS DE LA COUVERTURE DES POLICES D'INTERFACE. `⛫ ⛬ ⛊ ⛨ ⚿ ⬤ 🗎 ❆ ☺`
 * étaient rendus par des polices de repli, différentes d'une plateforme à l'autre — d'où des
 * graisses et des tailles incohérentes dans une même colonne, et un `🗎` qui part en emoji couleur
 * chez certains. Ce n'était pas réglable par du CSS : ces caractères n'existaient pas dans la police.
 *
 * ── LA GRAMMAIRE, ET POURQUOI CELLE-LA ────────────────────────────────────────────────────────
 *
 * Grille de 24, trait de 1,7, bouts et jonctions ronds, aucun remplissage. C'est la langue du mark
 * Fluvia — un monoline à terminaisons arrondies — et c'est la seule chose de l'identité qui
 * survive à toutes les tailles et à l'impression en noir, le dégradé n'y survivant à aucune.
 *
 * `currentColor` partout : les icônes prennent la couleur de leur lien, donc `--side-ink` au repos
 * et `--sur-accent` sur l'élément actif. Aucune couleur en dur — c'est le garde-fou n°42.
 */

const ICONS = {
  modules: (
    <>
      <rect x="3.2" y="3.2" width="7.6" height="7.6" rx="1.6" />
      <rect x="13.2" y="3.2" width="7.6" height="7.6" rx="1.6" />
      <rect x="3.2" y="13.2" width="7.6" height="7.6" rx="1.6" />
      <path d="M17 13.4v7.2M13.4 17h7.2" />
    </>
  ),
  register: (
    <>
      <rect x="3" y="8.5" width="18" height="12" rx="2" />
      <path d="M6.5 8.5V5.2a1.6 1.6 0 0 1 1.6-1.6h7.8a1.6 1.6 0 0 1 1.6 1.6v3.3" />
      <path d="M7.5 12.5h5M7.5 16h9" />
    </>
  ),
  validate: (
    <>
      <path d="M3.2 7.5A1.7 1.7 0 0 1 4.9 5.8h14.2a1.7 1.7 0 0 1 1.7 1.7v2a2.4 2.4 0 0 0 0 4.8v2a1.7 1.7 0 0 1-1.7 1.7H4.9a1.7 1.7 0 0 1-1.7-1.7v-2a2.4 2.4 0 0 0 0-4.8z" />
      <path d="M8.8 12.2l2.2 2.2 4.2-4.6" />
    </>
  ),
  catalog: (
    <>
      <path d="M4 5.4A1.8 1.8 0 0 1 5.8 3.6H19a1 1 0 0 1 1 1v13.8a1 1 0 0 1-1 1H5.8A1.8 1.8 0 0 0 4 21.2z" />
      <path d="M4 18.4a1.8 1.8 0 0 1 1.8-1.8H20" />
      <path d="M8 7.6h7M8 11h5" />
    </>
  ),
  booking: (
    <>
      <rect x="3.2" y="5" width="17.6" height="15.8" rx="2" />
      <path d="M3.2 9.6h17.6M7.8 3.2v3.6M16.2 3.2v3.6" />
      <path d="M12 12.6v2.6l1.9 1.2" />
    </>
  ),
  agenda: (
    <>
      <rect x="3.2" y="5" width="17.6" height="15.8" rx="2" />
      <path d="M3.2 9.6h17.6M7.8 3.2v3.6M16.2 3.2v3.6" />
      <path d="M7.6 13h2.4M14 13h2.4M7.6 17h2.4M14 17h2.4" />
    </>
  ),
  pool: (
    <>
      <path d="M2.6 8.4c1.9 0 1.9 1.6 3.7 1.6s1.9-1.6 3.7-1.6 1.9 1.6 3.7 1.6 1.9-1.6 3.8-1.6 1.9 1.6 3.9 1.6" />
      <path d="M2.6 13.4c1.9 0 1.9 1.6 3.7 1.6s1.9-1.6 3.7-1.6 1.9 1.6 3.7 1.6 1.9-1.6 3.8-1.6 1.9 1.6 3.9 1.6" />
      <path d="M2.6 18.4c1.9 0 1.9 1.6 3.7 1.6s1.9-1.6 3.7-1.6 1.9 1.6 3.7 1.6 1.9-1.6 3.8-1.6 1.9 1.6 3.9 1.6" />
    </>
  ),
  rink: (
    <>
      <path d="M12 2.8v18.4M4 7.4l16 9.2M20 7.4L4 16.6" />
      <path d="M12 6.6l2.4-2.2M12 6.6L9.6 4.4M12 17.4l2.4 2.2M12 17.4l-2.4 2.2" />
    </>
  ),
  padel: (
    <>
      <path d="M15.4 3.4a5.6 5.6 0 0 1 0 11.2 5.6 5.6 0 0 1 0-11.2z" />
      <path d="M12.6 14.2l-2.2 2.2M10.4 16.4l-2 2a1.9 1.9 0 1 1-2.7-2.7l2-2z" />
      <path d="M13.4 6.6v5.6M17.4 6.6v5.6M12.4 9.4h6" />
    </>
  ),
  museum: (
    <>
      <path d="M3 9.4l9-5.6 9 5.6" />
      <path d="M5.6 9.8v8.4M10 9.8v8.4M14 9.8v8.4M18.4 9.8v8.4" />
      <path d="M3.4 20.6h17.2" />
    </>
  ),
  fitness: (
    <>
      <path d="M3 9.6v4.8M6.4 7.2v9.6M17.6 7.2v9.6M21 9.6v4.8" />
      <path d="M6.4 12h11.2" />
    </>
  ),
  supervision: (
    <>
      <rect x="2.6" y="4" width="18.8" height="12.6" rx="2" />
      <path d="M8.4 20.4h7.2M12 16.8v3.6" />
      <path d="M8 10.3s1.6-2.6 4-2.6 4 2.6 4 2.6-1.6 2.6-4 2.6-4-2.6-4-2.6z" />
    </>
  ),
  badges: (
    <>
      <rect x="2.8" y="5" width="18.4" height="14" rx="2" />
      <path d="M6.6 9.4h4.2M6.6 12.6h4.2M6.6 15.6h2.4" />
      <path d="M16.2 12.4a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" />
      <path d="M13.4 16.4c.4-1.5 1.5-2.3 2.8-2.3s2.4.8 2.8 2.3" />
    </>
  ),
  topology: (
    <>
      <path d="M6 5.4a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM18 5.4a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM12 14.6a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4z" />
      <path d="M8.2 7.6h7.6M7.2 9.6l3.4 5M16.8 9.6l-3.4 5" />
    </>
  ),
  customers: (
    <>
      <path d="M12 3.6a3.9 3.9 0 1 0 0 7.8 3.9 3.9 0 0 0 0-7.8z" />
      <path d="M4.6 20.4c0-4 3.3-6.4 7.4-6.4s7.4 2.4 7.4 6.4" />
    </>
  ),
  staff: (
    <>
      <path d="M9 4.2a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 0 0 0-6.8z" />
      <path d="M2.6 20.2c0-3.6 2.9-5.8 6.4-5.8s6.4 2.2 6.4 5.8" />
      <path d="M16.4 5a3.2 3.2 0 0 1 0 6.2M18 14.8c2.1.6 3.4 2.5 3.4 5.4" />
    </>
  ),
  deposits: (
    <>
      <path d="M12 2.8l7.6 2.9v6c0 4.6-3.1 8.2-7.6 9.5-4.5-1.3-7.6-4.9-7.6-9.5v-6z" />
      <rect x="9" y="11" width="6" height="5" rx="1.2" />
      <path d="M10.4 11V9.6a1.6 1.6 0 0 1 3.2 0V11" />
    </>
  ),
  deals: (
    <>
      <rect x="2.8" y="6.8" width="18.4" height="13" rx="2" />
      <path d="M8.6 6.8V5.2a1.6 1.6 0 0 1 1.6-1.6h3.6a1.6 1.6 0 0 1 1.6 1.6v1.6" />
      <path d="M2.8 12.4h18.4M10.4 12.4v2.2h3.2v-2.2" />
    </>
  ),
  projects: (
    <>
      <rect x="2.8" y="4.2" width="18.4" height="15.6" rx="2" />
      <path d="M8.9 4.2v15.6M15.1 4.2v15.6" />
      <path d="M5.4 8h1.2M11.6 8h1.2M17.8 8h1.2" />
    </>
  ),
  campaigns: (
    <>
      <path d="M3.2 9.4v5.2a1.4 1.4 0 0 0 1.4 1.4h2.6l7.4 4.4V3.6L7.2 8H4.6a1.4 1.4 0 0 0-1.4 1.4z" />
      <path d="M18.2 8.6a4.8 4.8 0 0 1 0 6.8" />
    </>
  ),
  accounting: (
    <>
      <path d="M12 3.4v17.2M4.6 6.6h14.8" />
      <path d="M7.6 6.8L4.4 13.2a3.4 3.4 0 0 0 6.4 0zM16.4 6.8l-3.2 6.4a3.4 3.4 0 0 0 6.4 0z" />
      <path d="M8.4 20.6h7.2" />
    </>
  ),
  shop: (
    <>
      <path d="M4.6 7.6h14.8l-1.2 12a1.6 1.6 0 0 1-1.6 1.4H7.4a1.6 1.6 0 0 1-1.6-1.4z" />
      <path d="M8.6 10V6.6a3.4 3.4 0 0 1 6.8 0V10" />
    </>
  ),
  stock: (
    <>
      <path d="M3.2 7.8L12 3.4l8.8 4.4v8.4L12 20.6l-8.8-4.4z" />
      <path d="M3.2 7.8L12 12.2l8.8-4.4M12 12.2v8.4" />
    </>
  ),
  invoicing: (
    <>
      <path d="M5.2 3.4h13.6v17.2l-2.7-1.8-2.7 1.8-2.7-1.8-2.8 1.8-2.7-1.8z" />
      <path d="M8.6 8h6.8M8.6 12h6.8M8.6 15.6h3.6" />
    </>
  ),
  purchases: (
    <>
      <path d="M3.2 8.4a2 2 0 0 1 2-2h13.6a2 2 0 0 1 2 2v9.4a2 2 0 0 1-2 2H5.2a2 2 0 0 1-2-2z" />
      <path d="M3.2 10.6h5a2.5 2.5 0 0 0 0 5h-5" />
      <path d="M6 6.4l9.6-3 1 3" />
    </>
  ),
  sepa: (
    <>
      <path d="M3 9.6l9-5.8 9 5.8" />
      <path d="M5.6 10v7.4M10 10v7.4M14 10v7.4M18.4 10v7.4" />
      <path d="M3 20.6h18" />
    </>
  ),
  settlements: (
    <>
      <rect x="2.8" y="6" width="18.4" height="12" rx="2" />
      <path d="M12 9.4a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2z" />
      <path d="M6.2 9.2v.1M17.8 14.8v.1" />
    </>
  ),
  collections: (
    <>
      <path d="M12 3.6l9.2 15.8H2.8z" />
      <path d="M12 9.6v4.4M12 17v.1" />
    </>
  ),
  documents: (
    <>
      <path d="M13.6 3.4H6.8a1.8 1.8 0 0 0-1.8 1.8v13.6a1.8 1.8 0 0 0 1.8 1.8h10.4a1.8 1.8 0 0 0 1.8-1.8V8.6z" />
      <path d="M13.6 3.4v5.2H19" />
      <path d="M8.6 13h6.8M8.6 16.4h4.4" />
    </>
  ),
  reporting: (
    <>
      <path d="M3.4 20.6h17.2" />
      <path d="M6.6 20.6v-6.4M11 20.6V7.6M15.4 20.6v-9.4M19.8 20.6V4.4" />
    </>
  ),
  support: (
    <>
      <path d="M12 2.8a9.2 9.2 0 1 0 0 18.4 9.2 9.2 0 0 0 0-18.4z" />
      <path d="M9.4 9.4a2.7 2.7 0 0 1 5.2.9c0 1.8-2.6 2.3-2.6 4" />
      <path d="M12 17.4v.1" />
    </>
  ),
  // ── Ajoutees le 01/09 : cinq entrees de menu pointaient vers un dessin inexistant ──────────
  //
  // Mesure : 35 dessins definis, 36 entrees de menu, quatre noms sans dessin — `dashboard`,
  // `legal`, `personal-data`, `social` — dont le TABLEAU DE BORD, la premiere du menu. Plus `api`,
  // arrivee avec l'entree de `claude-B`, qui aurait rejoint les quatre.
  //
  // ⚠ CORRECTION DU 01/09, 23h. J'AVAIS ECRIT ICI QUE LE `<svg>` SORTAIT VIDE. C'EST FAUX.
  //
  // Le `FALLBACK` ci-dessus — un carre barre — etait la depuis 14h07, plusieurs heures avant que
  // j'ecrive cette phrase, et il est DELIBERE : son auteur voulait un repli BRUYANT, parce qu'une
  // icone qui ne dessine rien laisse une ligne de menu sans sa puce, l'alignement tient toujours,
  // et personne ne le remarque. Un nom absent rendait donc un carre barre, VISIBLE, pas un trou.
  //
  // Le comptage etait mesure ; la consequence a l'ecran, elle, etait INFEREE de
  // `ICONS[name] === undefined` sans que je regarde ce que le composant en fait deux ecrans plus
  // bas. J'ai decrit le code que j'imaginais, pas celui qui etait la.
  //
  // ⚠ ET J'AVAIS ECRIT ICI QUE C'ETAIT « claude-A ». C'est un nom d'AUTEUR GIT, pas une session :
  // les dix sessions partagent la meme identite. `git log` ne dit jamais qui a ecrit quoi ici. Le
  // repli est d'`allaccess-8e`, qui me l'a signale — je n'aurais pas pu le savoir en lisant `%an`,
  // et j'ai lu ce champ comme s'il designait quelqu'un.
  //
  // Ce que ca change : le defaut n'etait pas silencieux, il etait laid. Ce que ca ne change pas :
  // rien n'empeche une entree de menu de partir sans son dessin. Le controle reste a poser — mais
  // ce qu'il evite est un carre barre sur « Tableau de bord », pas une absence invisible.
  dashboard: (
    <>
      <rect x="3" y="3" width="7" height="9" rx="1" />
      <rect x="14" y="3" width="7" height="5" rx="1" />
      <rect x="14" y="12" width="7" height="9" rx="1" />
      <rect x="3" y="16" width="7" height="5" rx="1" />
    </>
  ),
  'personal-data': (
    <>
      <circle cx="12" cy="8" r="3.2" />
      <path d="M5.5 20a6.5 6.5 0 0 1 13 0" />
      <path d="M15.5 12.5l2 2 3.5-3.5" />
    </>
  ),
  social: (
    <>
      <circle cx="6" cy="12" r="2.4" />
      <circle cx="17" cy="6.5" r="2.4" />
      <circle cx="17" cy="17.5" r="2.4" />
      <path d="M8.1 10.9l6.8-3.3" />
      <path d="M8.1 13.1l6.8 3.3" />
    </>
  ),
  api: (
    <>
      <path d="M8.5 7.5L4 12l4.5 4.5" />
      <path d="M15.5 7.5L20 12l-4.5 4.5" />
      <path d="M13.5 4.5l-3 15" />
    </>
  ),
  // Reprise initiale : une fleche qui ENTRE dans un contenant. Le sens compte — la meme fleche
  // retournee dirait « exporter », et les deux gestes ne se defont pas de la meme facon.
  import: (
    <>
      <path d="M12 3v10" />
      <path d="M8.5 9.5L12 13l3.5-3.5" />
      <path d="M4 15v3.5a1.5 1.5 0 0 0 1.5 1.5h13a1.5 1.5 0 0 0 1.5-1.5V15" />
    </>
  ),
  settings: (
    <>
      <path d="M12 8.6a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 0 0 0-6.8z" />
      <path d="M19.2 14.6a1.5 1.5 0 0 0 .3 1.7l.1.1a1.9 1.9 0 1 1-2.7 2.7l-.1-.1a1.5 1.5 0 0 0-2.6 1.1v.2a1.9 1.9 0 1 1-3.8 0v-.1a1.5 1.5 0 0 0-2.6-1.1l-.1.1a1.9 1.9 0 1 1-2.7-2.7l.1-.1a1.5 1.5 0 0 0-1.1-2.6h-.2a1.9 1.9 0 1 1 0-3.8h.1a1.5 1.5 0 0 0 1.1-2.6l-.1-.1a1.9 1.9 0 1 1 2.7-2.7l.1.1a1.5 1.5 0 0 0 2.6-1.1v-.2a1.9 1.9 0 1 1 3.8 0v.1a1.5 1.5 0 0 0 2.6 1.1l.1-.1a1.9 1.9 0 1 1 2.7 2.7l-.1.1a1.5 1.5 0 0 0 1.1 2.6h.2a1.9 1.9 0 1 1 0 3.8h-.1a1.5 1.5 0 0 0-1.4.9z" />
    </>
  ),
  escalations: (
    <>
      <path d="M3.4 20.6h17.2" />
      <path d="M12 17.4V5.4M12 5.4L7.6 9.8M12 5.4l4.4 4.4" />
      <path d="M4.6 13.4h3.2M16.2 13.4h3.2" />
    </>
  ),
  'legal': (
    <>
      <path d="M12 3.4v17.2M6.6 20.6h10.8M4.6 6.8h14.8" />
      <path d="M7.6 6.8L4.4 13a3.4 3.4 0 0 0 6.4 0zM16.4 6.8L13.2 13a3.4 3.4 0 0 0 6.4 0z" />
    </>
  ),
  subscriptions: (
    <>
      <path d="M3.6 9.6a8.6 8.6 0 0 1 14.8-4.2l2.4 2.4" />
      <path d="M20.4 14.4a8.6 8.6 0 0 1-14.8 4.2l-2.4-2.4" />
      <path d="M20.8 3.6v4.4h-4.4M3.2 20.4V16h4.4" />
    </>
  ),
  offers: (
    <>
      <path d="M3.6 11.4V5a1.4 1.4 0 0 1 1.4-1.4h6.4a1.4 1.4 0 0 1 1 .4l8 8a1.4 1.4 0 0 1 0 2l-6.4 6.4a1.4 1.4 0 0 1-2 0l-8-8a1.4 1.4 0 0 1-.4-1z" />
      <path d="M7.8 7.6v.1" />
    </>
  ),
  'support-access': (
    <>
      <path d="M15.6 3.4a5.2 5.2 0 1 0 0 10.4 5.2 5.2 0 0 0 0-10.4z" />
      <path d="M11.9 12.1L3.4 20.6M6.6 17.4l2.4 2.4M9 15l2.4 2.4" />
    </>
  ),
};

/**
 * ⚠ LE REPLI EST BRUYANT, ET C'EST DELIBERE.
 *
 * Un nom inconnu rend un carré barré, pas rien. Une icône absente qui ne dessine RIEN produit un
 * menu où une ligne a perdu sa puce sans que personne ne sache pourquoi — et l'alignement, lui,
 * tient toujours, donc ça ne se remarque pas. Le carré barré se voit du premier coup d'œil, et il
 * dit « ce nom n'existe pas » à quelqu'un qui vient d'en écrire un.
 */
const FALLBACK = (
  <>
    <rect x="3.6" y="3.6" width="16.8" height="16.8" rx="2.4" />
    <path d="M5.4 5.4l13.2 13.2" />
  </>
);

export default function Icon({ name, size = 18, className = '' }) {
  const drawing = ICONS[name];

  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {drawing || FALLBACK}
    </svg>
  );
}

export { ICONS };
