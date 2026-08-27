import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * LES VITRINES, AVEC LEUR ADRESSE ET DE QUOI L'INTÉGRER.
 *
 * **Ce que la liste précédente ne disait pas.** Elle affichait un libellé et un statut. Pas l'URL.
 * Un exploitant qui vient d'ouvrir sa boutique en ligne n'avait donc **aucun moyen de savoir où elle
 * est** — il devait la deviner, ou la demander.
 *
 * > **Une boutique en ligne dont on ne connaît pas l'adresse n'est pas en ligne.**
 *
 * Trois choses sont donc rendues, et ce sont les trois seules qu'on vient chercher ici :
 *
 * 1. **L'adresse publique**, en clair et copiable — c'est elle qu'on met sur une affiche.
 * 2. **Le nom d'URL, modifiable.** C'est l'adresse de l'exploitant, elle porte son nom ; le défaut
 *    fabriqué depuis le nom de l'établissement n'est qu'une proposition.
 * 3. **Le code d'intégration en iframe**, prêt à coller dans son site.
 *
 * **Sur l'iframe, deux précautions sont dans le code lui-même plutôt que dans une documentation que
 * personne ne lira.** `title` (obligatoire pour un lecteur d'écran, sans quoi l'iframe est annoncée
 * comme un cadre anonyme) et une hauteur généreuse — une boutique dans un cadre de 400 px fait
 * défiler deux ascenseurs imbriqués, ce qui est la façon la plus sûre de perdre un acheteur sur
 * téléphone.
 */
export default function VitrinesBoutique({ droits = [] }) {
  const peutGerer = aLeDroit(droits, 'boutique.gerer_vitrine')

  const [vitrines, setVitrines] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edite, setEdite] = useState(null)
  const [valeur, setValeur] = useState('')
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setVitrines(membres(await api.vitrines()))
    } catch (e) {
      setErreur(e.message || 'Les vitrines n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function enregistrer(v) {
    const slug = valeur.trim()
    if (!slug) return
    setBusy(true)
    setErreur(null)
    try {
      await api.majVitrine(v.id, { slug })
      setEdite(null)
      setSucces(`Adresse mise à jour : /b/${slug}`)
      recharger()
    } catch (e) {
      // Le message du serveur est conservé tel quel : sur une collision d'unicité, c'est lui qui
      // explique que le nom est déjà pris. Le remplacer par « échec » ferait réessayer à l'identique.
      setErreur(e.message || 'L’adresse n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      {erreur && <div className="alert crit">{erreur}</div>}
      {succes && <div className="alert good">{succes}</div>}

      {vitrines.length === 0 ? (
        <div className="panel">
          <div className="sub" style={{ textAlign: 'center', padding: 24 }}>Aucune vitrine configurée.</div>
        </div>
      ) : (
        vitrines.map((v) => {
          const reference = v.slug || v.id
          const url = `${window.location.origin}/b/${reference}`
          const iframe = `<iframe src="${url}" title="Billetterie en ligne" width="100%" height="900" style="border:0" loading="lazy"></iframe>`

          return (
            <section className="panel" key={v.id}>
              <div className="panel-h">
                <span>{v.etablissement?.nom || 'Vitrine'}</span>
                {!v.slug && (
                  // Une vitrine sans nom d'URL fonctionne — par son identifiant — mais son adresse est
                  // indonnable. On le dit, plutôt que d'afficher l'UUID comme si c'était normal.
                  <span className="badge warn" style={{ marginLeft: 8 }}>adresse en identifiant technique</span>
                )}
              </div>

              <div style={{ display: 'grid', gap: 14, padding: 14 }}>
                <div>
                  <div className="st-lib">Adresse publique</div>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4, flexWrap: 'wrap' }}>
                    <code style={{ fontSize: 13.5 }}>{url}</code>
                    <BoutonCopier texte={url} />
                    <a className="btn ghost sm" href={url} target="_blank" rel="noreferrer">Ouvrir</a>
                  </div>
                </div>

                {peutGerer && (
                  <div>
                    <div className="st-lib">Nom dans l&rsquo;adresse</div>
                    {edite === v.id ? (
                      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4, flexWrap: 'wrap' }}>
                        <span className="sub">/b/</span>
                        <input
                          className="input sm"
                          style={{ width: 260 }}
                          value={valeur}
                          onChange={(e) => setValeur(e.target.value)}
                          placeholder="piscine-municipale"
                        />
                        <button className="btn primary sm" type="button" disabled={busy} onClick={() => enregistrer(v)}>
                          Enregistrer
                        </button>
                        <button className="btn ghost sm" type="button" onClick={() => setEdite(null)}>Annuler</button>
                      </div>
                    ) : (
                      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4 }}>
                        <code>{v.slug || <span className="sub">aucun</span>}</code>
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => { setEdite(v.id); setValeur(v.slug || '') }}
                        >
                          Modifier
                        </button>
                      </div>
                    )}
                    <div className="hint" style={{ margin: '4px 0 0' }}>
                      L&rsquo;ancienne adresse cesse alors de fonctionner : ne la changez pas après
                      l&rsquo;avoir diffusée.
                    </div>
                  </div>
                )}

                <DomainesIntegration
                  vitrine={v}
                  onEnregistre={(msg) => { setSucces(msg); recharger() }}
                  onErreur={setErreur}
                />

                <div>
                  <div className="st-lib">Intégrer dans votre site</div>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginTop: 4 }}>
                    <code
                      style={{
                        fontSize: 12,
                        display: 'block',
                        flex: 1,
                        padding: 8,
                        background: 'var(--panel-2)',
                        borderRadius: 6,
                        overflowX: 'auto',
                        whiteSpace: 'pre',
                      }}
                    >
                      {iframe}
                    </code>
                    <BoutonCopier texte={iframe} />
                  </div>
                  <div className="hint" style={{ margin: '4px 0 0' }}>
                    Sur Safari et Firefox, le panier d&rsquo;un visiteur en cadre intégré n&rsquo;est
                    pas conservé d&rsquo;une visite à l&rsquo;autre : le navigateur cloisonne le
                    stockage par site hôte. La boutique reste utilisable pendant toute la visite.
                  </div>
                </div>
              </div>
            </section>
          )
        })
      )}
    </div>
  )
}

/**
 * Copier, en disant que c'est fait.
 *
 * `navigator.clipboard` échoue hors contexte sécurisé et dans certaines configurations d'entreprise.
 * Un bouton qui ne fait rien et ne dit rien laisse croire que la copie a marché — l'exploitant colle
 * alors le contenu précédent de son presse-papiers dans son site.
 */
function BoutonCopier({ texte }) {
  const [etat, setEtat] = useState('')

  async function copier() {
    try {
      await navigator.clipboard.writeText(texte)
      setEtat('copié')
    } catch {
      setEtat('sélectionnez et copiez à la main')
    }
    setTimeout(() => setEtat(''), 2500)
  }

  return (
    <button className="btn ghost sm" type="button" onClick={copier}>
      {etat || 'Copier'}
    </button>
  )
}


/**
 * QUI A LE DROIT D'ENCADRER CETTE BOUTIQUE.
 *
 * **Le code d'intégration au-dessus ne suffit pas.** Il dit comment afficher la boutique chez soi ;
 * il ne dit pas qui en a le droit. Sans cette liste, la réponse est « tout le monde » : n'importe
 * quel site peut afficher la boutique d'un client sous son propre nom, et le visiteur paie sur une
 * page qu'il croit être celle du site encadrant. Rien ne casse, rien n'alerte.
 *
 * > **Une page qu'on peut encadrer sans le dire est une page qu'on peut porter au nom d'un autre.**
 *
 * **Aucun domaine déclaré = encadrable nulle part.** C'est le sens sûr de l'erreur : une intégration
 * qui ne s'affiche pas se signale tout de suite et se corrige en une ligne ; une boutique encadrable
 * par tout le monde ne se signale jamais.
 *
 * ⚠ **Déclarer ici ne suffit pas encore.** L'en-tête est posé par nginx, qui sert le front statique ;
 * PHP ne voit jamais passer cette requête. L'écran le dit plutôt que de laisser croire que
 * l'autorisation est active à l'enregistrement.
 */
function DomainesIntegration({ vitrine, onEnregistre, onErreur }) {
  const [ouvert, setOuvert] = useState(false)
  const [texte, setTexte] = useState((vitrine.domainesIntegration || []).join('\n'))
  const [busy, setBusy] = useState(false)

  const liste = (vitrine.domainesIntegration || [])

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const domaines = texte.split('\n').map((d) => d.trim()).filter(Boolean)
      await api.majVitrine(vitrine.id, { domainesIntegration: domaines })
      setOuvert(false)
      onEnregistre(
        domaines.length === 0
          ? 'Boutique non encadrable : aucun site autorisé.'
          : `${domaines.length} site(s) autorisé(s) — à appliquer côté serveur pour prendre effet.`,
      )
    } catch (e) {
      onErreur(e.message || 'Les domaines n’ont pas pu être enregistrés.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="st-lib">Sites autorisés à l’encadrer</div>

      {!ouvert ? (
        <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap', marginTop: 4 }}>
          {liste.length === 0 ? (
            <span className="badge warn">Aucun — la boutique n’est encadrable nulle part</span>
          ) : (
            liste.map((d) => <span className="badge good" key={d}>{d}</span>)
          )}
          <button
            className="btn ghost sm"
            type="button"
            onClick={() => { setTexte(liste.join('\n')); setOuvert(true) }}
          >
            Modifier
          </button>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 6, marginTop: 4 }}>
          <textarea
            rows={3}
            value={texte}
            onChange={(e) => setTexte(e.target.value)}
            placeholder={'https://exemple.fr\nhttps://www.exemple.fr'}
            style={{ fontFamily: 'ui-monospace, monospace', fontSize: 12.5 }}
          />
          <div className="hint" style={{ margin: 0 }}>
            Une origine par ligne, schéma compris : <code>https://exemple.fr</code>. Un sous-domaine
            générique s’écrit <code>https://*.exemple.fr</code>. Laisser vide interdit tout
            encadrement.
          </div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button className="btn ghost sm" type="button" disabled={busy} onClick={() => setOuvert(false)}>
              Annuler
            </button>
            <button className="btn primary sm" type="button" disabled={busy} onClick={enregistrer}>
              Enregistrer
            </button>
          </div>
        </div>
      )}

      <div className="hint" style={{ margin: '4px 0 0' }}>
        L’autorisation est appliquée par le serveur web, pas par cet écran : après modification,
        l’exploitant technique régénère la configuration
        (<code>php bin/console app:integration:csp</code>) et la recharge.
      </div>
    </div>
  )
}
