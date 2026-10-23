// LES ENTRÉES DU MENU QUI APPARTIENNENT À UN MODULE MASQUABLE (recentrage, PR 2).
//
// Une entrée de cette table n'apparaît que si son module figure dans `modulesActifs` de `/me` — la
// même règle que la garde serveur, qui rend 404 aux opérations d'un module éteint. Sans elle, le menu
// montrerait un écran dont chaque appel échoue.
//
// ⚠ UNE SEULE TABLE, ET UN TEST QUI LA TIENT. `moduleMenu.test.js` lit les manifestes PHP : une
// entrée dont les droits appartiennent à un module masqué et qui manque ici le fait échouer. Les
// valeurs sont les identifiants des manifestes (`ModuleManifest::id()`), pas des capacités.
export const MODULE_OF_MENU_ENTRY = {
  padel: 'padel',
  patinoire: 'patinoire',
  musee: 'musee',
  sejours: 'stay',
  campagnes: 'marketing',
  projets: 'project',
  finance: 'finance',
  caution: 'caution',
  relance_recettes: 'revenue_recovery',
  places_liberees: 'smart_flow',
  imports: 'import',
  social: 'social',
}

// `modules` absent (serveur qui ne le dit pas encore) : on ne cache rien. Le menu n'est pas le
// contrôle — la garde serveur l'est — et un menu vide enfermerait l'utilisateur hors de son logiciel.
export function moduleAllowsEntry(entryId, modules) {
  const module = MODULE_OF_MENU_ENTRY[entryId]
  return !module || !Array.isArray(modules) || modules.includes(module)
}

// LES SOUS-ONGLETS QUI APPARTIENNENT À UN MODULE MASQUABLE — clé « Page.sousOnglet ». Même règle que
// les entrées de menu, et même test (`moduleMenu.test.js`).
export const MODULE_OF_SUBTAB = {
  'Parametres.connecteurs': 'connecteurs',
}

export function moduleAllowsSubtab(key, modules) {
  const module = MODULE_OF_SUBTAB[key]
  return !module || isModuleActive(module, modules)
}

// Un écran du CŒUR qui lit les données d'un module masqué (fiche produit → expositions du musée,
// fiche client → fidélité et parrainage) ne l'appelle que si le module est actif : sinon chaque
// ouverture produirait un 404 en arrière-plan, ou une bannière « Not Found ».
export function isModuleActive(module, modules) {
  return !Array.isArray(modules) || modules.includes(module)
}
