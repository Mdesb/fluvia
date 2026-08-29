import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { useEtatUrl } from '../api/url.js'
import MessagerieAssistance from '../components/MessagerieAssistance.jsx'

/**
 * ASSISTANCE — l'écran qui manquait à un module entièrement construit.
 *
 * **Pourquoi il n'existait pas.** `App\Support` expose onze opérations de tickets et sept d'articles
 * d'aide : ouverture, prise en charge, changement de statut, escalade N1→N2, réaffectation, notes
 * internes, base de connaissances versionnée et publiable. Rien n'y menait. L'entrée de menu était
 * écrite depuis le début, avec `absent: true` — la porte était dessinée et condamnée.
 *
 * Maxime, le 27/08 : *« il manque la partie support dans les écrans »*. Il ne demandait pas une
 * fonctionnalité ; il constatait qu'une fonctionnalité livrée était inatteignable. `CARTE-MODULES.md`
 * compte **815 opérations sans porte pour 234 atteignables** : ce qui manque à ce produit n'est
 * presque jamais la règle métier, c'est le chemin qui y mène.
 *
 * **Deux publics, un seul écran, et c'est délibéré.** Le demandeur ouvre un ticket et suit sa
 * réponse ; l'agent traite une file. Les séparer en deux écrans obligerait à choisir *avant* de
 * savoir : un responsable de site est les deux dans la même journée. Ce sont donc les **droits** qui
 * décident de ce qui s'affiche, jamais un onglet à choisir soi-même.
 */

// `STATUTS`, `STATUTS_POSABLES`, `quand` et `nomUtilisateur` ont suivi le tableau et la modale
// qu'ils servaient : ils vivent desormais dans `components/MessagerieAssistance.jsx`. `PRIORITES`
// reste ici, la modale d'ouverture d'une demande s'en sert encore.
const PRIORITES = {
  basse: { libelle: 'Basse', cls: 'mut' },
  normale: { libelle: 'Normale', cls: 'mut' },
  haute: { libelle: 'Haute', cls: 'warn' },
  critique: { libelle: 'Critique', cls: 'crit' },
}

const DEFAUTS = { tab: 'tickets', statut: '', priorite: '' }

export default function Support({ droits = [], etabActif, me = null }) {
  // ⚠ `droits.includes(code)` NE VOIT PAS LE JOKER, et le garde-fou n°13 me l'a refuse a raison.
  //
  // Une permission peut arriver sous la forme `support.*` ou `*.lire` : une egalite stricte la
  // manque, sans lever, et l'ecran s'affiche simplement AMPUTE de ses actions. L'administrateur
  // conclut qu'il n'a pas le droit, alors qu'il a TOUS les droits. Aucun test ne rougit pour ca.
  const peut = useCallback(
    (code) => aLeDroit(droits, code) || aLeDroit(droits, 'support.administrer'),
    [droits],
  )
  const agent = peut('support.traiter_ticket_n1') || peut('support.traiter_ticket_n2')

  // TROISIEME APPLICATION DU MOTIF DE §9.2 : l'etat de l'ecran vit dans l'URL.
  //
  // Ici le gain est direct et quotidien : un ticket devient PARTAGEABLE. << Regarde la demande de la
  // piscine >> se copie-colle au lieu de se decrire. Et une file triee par priorite survit a
  // l'expiration de session, qui tombe toutes les heures -- sans ca, l'agent se reconnecte sur la
  // caisse et refait son tri.
  const [params, majParams] = useEtatUrl('support', DEFAUTS)
  const onglet = params.tab
  const setOnglet = (v) => majParams({ tab: v })
  const filtreStatut = params.statut
  const filtrePriorite = params.priorite
  const setFiltreStatut = (v) => majParams({ statut: v })
  const setFiltrePriorite = (v) => majParams({ priorite: v })
  const [tickets, setTickets] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  // Plus d'etat `ouvert` : la messagerie tient elle-meme le fil choisi. Le garder ici en ferait
  // deux sources pour une meme question — « quelle conversation regarde-t-on ? » — et deux
  // sources finissent toujours par diverger.
  const [nouveau, setNouveau] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // LES FILTRES SONT CEUX DE LA COLLECTION, PAS D'UN SECOND POINT D'ENTRÉE.
      //
      // `/support/tickets/tableau-de-bord` existait et proposait exactement les mêmes filtres, servi
      // par un fournisseur qui **recopiait à la main** la règle de cloisonnement de
      // `PerimetreSupportExtension` — son propre commentaire le disait. Deux chemins vers la même
      // donnée, dont l'un rejoue une règle de sécurité : le jour où la règle change, il en reste une
      // version périmée, et c'est celle-là qui décide qui voit quoi.
      //
      // On lit donc la collection, qui porte déjà `statut`, `priorite` et `niveauAffectation`.
      setTickets(membres(await api.supportTickets({
        ...(filtreStatut ? { statut: filtreStatut } : {}),
        ...(filtrePriorite ? { priorite: filtrePriorite } : {}),
      })))
    } catch (e) {
      setErreur(e.message || 'Les tickets n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [filtreStatut, filtrePriorite])

  useEffect(() => {
    if (onglet === 'tickets') recharger()
  }, [onglet, recharger])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Assistance</h1>
          <div className="sub">Demandes d&rsquo;aide et base de connaissances</div>
        </div>
        {peut('support.ouvrir_ticket') && (
          <button className="btn primary" type="button" onClick={() => setNouveau(true)}>
            + Ouvrir une demande
          </button>
        )}
      </div>

      <Tabs
        onglets={[['tickets', 'Demandes'], ['articles', 'Base de connaissances']]}
        actif={onglet}
        onChange={setOnglet}
      />

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {onglet === 'tickets' ? (
        <MessagerieAssistance
          tickets={tickets}
          chargement={chargement}
          erreur={null}
          filtreStatut={filtreStatut}
          onFiltrerStatut={setFiltreStatut}
          filtrePriorite={filtrePriorite}
          onFiltrerPriorite={setFiltrePriorite}
          agent={agent}
          peut={peut}
          me={me}
          onRecharger={recharger}
          onOuvrirNouveau={() => setNouveau(true)}
        />
      ) : (
        <BaseConnaissances droits={droits} etabActif={etabActif} />
      )}

      <OuvrirDemande
        open={nouveau}
        onFermer={() => setNouveau(false)}
        onOuvert={() => {
          // LA CONVERSATION QU'ON VIENT D'OUVRIR DOIT ETRE CELLE QU'ON LIT.
          //
          // La modale rendait l'identifiant pour ouvrir la fiche ; la messagerie choisit d'elle-meme
          // le premier fil de la liste, et la liste est triee par derniere activite. Une demande
          // qu'on vient d'ecrire EST la plus recente : recharger suffit, et evite un second etat a
          // tenir a jour.
          setNouveau(false)
          recharger()
        }}
      />
    </div>
  )
}

function OuvrirDemande({ open, onFermer, onOuvert }) {
  const [sujet, setSujet] = useState('')
  const [description, setDescription] = useState('')
  const [priorite, setPriorite] = useState('normale')
  const [moduleConcerne, setModule] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setSujet('')
    setDescription('')
    setPriorite('normale')
    setModule('')
    setErreur(null)
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      const cree = await api.ouvrirTicket({
        sujet: sujet.trim(),
        description: description.trim(),
        priorite,
        ...(moduleConcerne.trim() ? { moduleConcerne: moduleConcerne.trim() } : {}),
      })
      onOuvert(cree.id)
    } catch (err) {
      setErreur(err.message || 'La demande n’a pas pu être ouverte.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onFermer} titre="Ouvrir une demande" taille="md">
      <form onSubmit={envoyer} style={{ display: 'grid', gap: 12 }}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div>
          <label htmlFor="tk-sujet">Sujet *</label>
          <input id="tk-sujet" className="input" required value={sujet} onChange={(e) => setSujet(e.target.value)} />
        </div>
        <div>
          <label htmlFor="tk-desc">Description *</label>
          <textarea
            id="tk-desc"
            className="input"
            rows={5}
            required
            placeholder="Ce qui s’est passé, à quel endroit, et ce que vous attendiez."
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div>
            <label htmlFor="tk-prio">Priorité</label>
            <select id="tk-prio" className="select" value={priorite} onChange={(e) => setPriorite(e.target.value)}>
              {Object.entries(PRIORITES).map(([cle, p]) => (
                <option key={cle} value={cle}>{p.libelle}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="tk-mod">Écran concerné</label>
            <input
              id="tk-mod"
              className="input"
              placeholder="Ex. Caisse"
              value={moduleConcerne}
              onChange={(e) => setModule(e.target.value)}
            />
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || sujet.trim() === '' || description.trim() === ''}>
            Ouvrir la demande
          </button>
        </div>
      </form>
    </Modal>
  )
}

/**
 * La base de connaissances, lue par sa route **publique**.
 *
 * `/support/articles/publics` et `/support/articles/recherche` sont en `PUBLIC_ACCESS`, et rendent
 * déjà la version publiée — pas les brouillons. L'écran n'a donc aucun filtrage à refaire : il
 * afficherait sinon une seconde définition de « publié », qui divergerait au premier changement de
 * règle côté serveur.
 */
/**
 * LA BASE DE CONNAISSANCES — et, depuis le 27/08, de quoi la remplir.
 *
 * **Ce que l'écran ne savait pas faire.** Il affichait les articles publiés, et rien d'autre. Créer,
 * modifier, publier, archiver : quatre opérations écrites côté serveur, aucune atteignable. La base
 * était donc en lecture seule **et vide pour toujours** — personne n'avait de moyen d'y écrire un
 * article, et l'écran disait poliment « Aucun article publié pour l'instant », ce qui est exact et
 * ne ressemble pas à un défaut.
 *
 * > **Une bibliothèque sans porte de service ne se remplit jamais.**
 *
 * ⚠ **La liste de rédaction lit la collection PRIVÉE, pas `/support/articles/publics`.** La publique
 * ne rend que le publié : un brouillon qu'on vient d'écrire y serait invisible, et son auteur
 * conclurait que l'enregistrement a échoué — puis le réécrirait.
 *
 * **Publier est un geste, pas une case.** Un article naît en brouillon — le serveur l'impose — et
 * quelqu'un décide de le publier. C'est ce qui permet d'écrire à moitié sans que ça parte chez
 * l'usager.
 */
function BaseConnaissances({ droits = [], etabActif }) {
  const peutEcrire = aLeDroit(droits, 'support.gerer_kb_globale') || aLeDroit(droits, 'support.gerer_kb_locale')
  const seulementLocal = !aLeDroit(droits, 'support.gerer_kb_globale') && aLeDroit(droits, 'support.gerer_kb_locale')

  const [articles, setArticles] = useState([])
  const [categories, setCategories] = useState([])
  const [q, setQ] = useState('')
  const [categorie, setCategorie] = useState('')
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [lu, setLu] = useState(null)
  const [edite, setEdite] = useState(null)
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // Un rédacteur doit voir ses brouillons ; un lecteur ne voit que le publié. Deux besoins, deux
      // collections — et surtout pas la publique pour les deux.
      const rep = peutEcrire
        ? await api.articlesAideStaff(categorie ? { categorie } : undefined)
        : q.trim().length >= 2
          ? await api.rechercheArticles(q.trim())
          : await api.articlesAide()
      setArticles(membres(rep))
    } catch (e) {
      setErreur(e.message || 'Les articles n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [peutEcrire, categorie, q])

  useEffect(() => { recharger() }, [recharger])

  useEffect(() => {
    let annule = false
    api.categoriesAide()
      .then((r) => { if (!annule) setCategories(membres(r)) })
      .catch(() => { /* le filtre par catégorie est un confort : son absence ne casse rien */ })
    return () => { annule = true }
  }, [])

  async function agir(action, message) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await action()
      setSucces(message)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  // Le filtre plein texte reste local quand on lit la collection de rédaction : elle n'a pas
  // d'opération de recherche, et en fabriquer une côté serveur serait du travail pour un besoin que
  // personne n'a exprimé.
  const visibles = peutEcrire && q.trim().length >= 2
    ? articles.filter((a) => `${a.titre} ${a.resume || ''}`.toLowerCase().includes(q.trim().toLowerCase()))
    : articles

  return (
    <div className="card">
      <div className="card-h" style={{ gap: 8, flexWrap: 'wrap' }}>
        <span>Articles d&rsquo;aide</span>

        {categories.length > 0 && (
          <select
            className="input sm"
            style={{ width: 180 }}
            value={categorie}
            onChange={(e) => setCategorie(e.target.value)}
          >
            <option value="">Toutes les catégories</option>
            {categories.map((c) => (
              <option key={c.id} value={`/api/categorie_aides/${c.id}`}>{c.libelle || c.nom}</option>
            ))}
          </select>
        )}

        <input
          className="input sm"
          style={{ marginLeft: 'auto', width: 260 }}
          placeholder="Rechercher…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />

        {peutEcrire && (
          <button className="btn primary sm" type="button" onClick={() => setEdite({})}>
            + Nouvel article
          </button>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : visibles.length === 0 ? (
        <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
          {q.trim().length >= 2
            ? `Aucun article ne correspond à « ${q.trim()} ».`
            : peutEcrire
              ? 'Aucun article. Le premier se crée avec le bouton ci-dessus.'
              : 'Aucun article publié pour l’instant.'}
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 10, padding: 4 }}>
          {visibles.map((a) => (
            <article
              key={a.id}
              className="card"
              style={{ padding: 12, border: '1px solid var(--line)', display: 'grid', gap: 6 }}
            >
              <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                <button
                  type="button"
                  style={{ background: 'none', border: 0, padding: 0, cursor: 'pointer', textAlign: 'left', font: 'inherit', fontWeight: 700 }}
                  onClick={() => setLu(a)}
                >
                  {a.titre}
                </button>
                {a.statut && a.statut !== 'publie' && (
                  <span className={`badge ${a.statut === 'archive' ? 'mut' : 'warn'}`}>
                    {a.statut === 'archive' ? 'Archivé' : 'Brouillon'}
                  </span>
                )}
                {a.portee === 'local' && <span className="badge mut">Local</span>}
              </div>

              {a.resume && <div className="sub">{a.resume}</div>}

              {peutEcrire && (
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <button className="btn ghost sm" type="button" disabled={busy} onClick={() => setEdite(a)}>
                    Modifier
                  </button>
                  {a.statut !== 'publie' && (
                    <button
                      className="btn sm"
                      type="button"
                      disabled={busy}
                      onClick={() => agir(() => api.publierArticleAide(a.id), 'Article publié.')}
                    >
                      Publier
                    </button>
                  )}
                  {a.statut !== 'archive' && (
                    <button
                      className="btn ghost sm"
                      type="button"
                      disabled={busy}
                      onClick={() => agir(() => api.archiverArticleAide(a.id), 'Article archivé.')}
                    >
                      Archiver
                    </button>
                  )}
                </div>
              )}
            </article>
          ))}
        </div>
      )}

      <Modal open={!!lu} onClose={() => setLu(null)} titre={lu?.titre || ''} taille="lg">
        {lu && <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{lu.contenu}</div>}
      </Modal>

      <RedactionArticle
        article={edite}
        categories={categories}
        etabActif={etabActif}
        seulementLocal={seulementLocal}
        onFerme={() => setEdite(null)}
        onEnregistre={async (message) => { setEdite(null); setSucces(message); await recharger() }}
      />
    </div>
  )
}

/**
 * ÉCRIRE OU MODIFIER UN ARTICLE.
 *
 * **La portée est le seul champ piégeux, et l'écran la traite comme telle.** Le serveur refuse un
 * article local sans établissement et un article global qui en porte un (RG-SUP-04). Laisser
 * l'utilisateur découvrir cette règle par un 422 lui ferait perdre sa saisie ; le formulaire pose
 * l'établissement actif dès qu'on choisit « local », et l'écrit.
 *
 * **Qui n'a que `gerer_kb_locale` n'a pas le choix**, et l'écran ne le lui propose donc pas : offrir
 * une option que le serveur refusera fait perdre du temps deux fois.
 */
function RedactionArticle({ article, categories, etabActif, seulementLocal, onFerme, onEnregistre }) {
  const ouvert = article !== null && article !== undefined
  const existant = ouvert && !!article.id

  const [titre, setTitre] = useState('')
  const [resume, setResume] = useState('')
  const [contenu, setContenu] = useState('')
  const [categorie, setCategorie] = useState('')
  const [portee, setPortee] = useState('global')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!ouvert) return
    setErreur(null)
    setTitre(article.titre || '')
    setResume(article.resume || '')
    setContenu(article.contenu || '')
    const ref = article.categorie
    setCategorie(typeof ref === 'string' ? ref : ref?.id ? `/api/categorie_aides/${ref.id}` : '')
    setPortee(article.portee || (seulementLocal ? 'local' : 'global'))
  }, [ouvert, article, seulementLocal])

  async function enregistrer() {
    setBusy(true)
    setErreur(null)
    try {
      const corps = {
        titre: titre.trim(),
        resume: resume.trim() || null,
        contenu,
        ...(categorie ? { categorie } : {}),
      }
      if (existant) {
        await api.majArticleAide(article.id, corps)
        await onEnregistre('Article modifié. Il reste à publier pour être visible.')
      } else {
        await api.creerArticleAide({
          ...corps,
          portee,
          // Le serveur refuse un article local sans établissement, et un global qui en porte un.
          ...(portee === 'local' && etabActif ? { etablissement: `/api/etablissements/${etabActif}` } : {}),
        })
        await onEnregistre('Article créé en brouillon. Publiez-le quand il est prêt.')
      }
    } catch (e) {
      setErreur(e.message || 'L’enregistrement a échoué.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={ouvert}
      onClose={onFerme}
      titre={existant ? 'Modifier l’article' : 'Nouvel article'}
      taille="lg"
    >
      <div style={{ display: 'grid', gap: 12 }}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Titre</span>
          <input className="input" value={titre} onChange={(e) => setTitre(e.target.value)} maxLength={200} />
        </label>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Résumé</span>
          <input
            className="input"
            value={resume}
            onChange={(e) => setResume(e.target.value)}
            maxLength={300}
            placeholder="La phrase qui s’affiche dans la liste"
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <label style={{ display: 'grid', gap: 4 }}>
            <span className="sub">Catégorie</span>
            <select className="input" value={categorie} onChange={(e) => setCategorie(e.target.value)}>
              <option value="">Sans catégorie</option>
              {categories.map((c) => (
                <option key={c.id} value={`/api/categorie_aides/${c.id}`}>{c.libelle || c.nom}</option>
              ))}
            </select>
          </label>

          {!existant && (
            <label style={{ display: 'grid', gap: 4 }}>
              <span className="sub">Portée</span>
              <select
                className="input"
                value={portee}
                disabled={seulementLocal}
                onChange={(e) => setPortee(e.target.value)}
              >
                <option value="global">Tous les établissements</option>
                <option value="local">Cet établissement seulement</option>
              </select>
            </label>
          )}
        </div>

        {!existant && portee === 'local' && !etabActif && (
          <div className="banner banner-warn">
            Aucun établissement actif : un article local doit en référencer un.
          </div>
        )}

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Contenu</span>
          <textarea rows={12} value={contenu} onChange={(e) => setContenu(e.target.value)} />
        </label>

        <div className="sub">
          L’article est enregistré en <strong>brouillon</strong> : il n’est visible de personne tant
          qu’il n’est pas publié.
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFerme} disabled={busy}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            onClick={enregistrer}
            disabled={busy || titre.trim() === '' || contenu.trim() === '' || (!existant && portee === 'local' && !etabActif)}
          >
            Enregistrer
          </button>
        </div>
      </div>
    </Modal>
  )
}
