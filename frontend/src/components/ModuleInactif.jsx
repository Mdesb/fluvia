import { allerA } from '../api/url.js'
import { t } from '../i18n/index.js'

// UN LIEN DIRECT VERS UN MODULE HORS SERVICE (favori, recherche, lien reçu) : on le DIT, à la place
// de l'écran, qui n'est pas monté. Ni page blanche ni renvoi silencieux à la caisse — la personne
// saurait seulement que son lien « ne marche pas ». Le bouton n'est offert qu'à qui peut activer.
export default function ModuleInactif({ onglet, etablissement, peutActiver }) {
  return (
    <div className="view">
      <div className="empty">
        <b>{t('module.inactive.title')}</b>
        <p className="hint">{t('module.inactive.body', { module: t(`nav.${onglet}`), establishment: etablissement })}</p>
        {peutActiver && (
          <button className="btn primary" type="button" onClick={() => allerA('parametres', { sousOnglet: 'capacites' })}>
            {t('module.inactive.enable')}
          </button>
        )}
      </div>
    </div>
  )
}
