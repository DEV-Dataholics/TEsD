import { useEffect, useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  CalendarDays,
  Check,
  CheckCheck,
  ChevronRight,
  CircleHelp,
  Clipboard,
  Copy,
  LogOut,
  Mail,
  Pencil,
  Plus,
  RefreshCw,
  ShieldCheck,
  Trash2,
  UserRound,
  Users,
  X,
} from 'lucide-react'

async function apiRequest(path, options = {}, csrfToken = '') {
  const headers = new Headers(options.headers || {})
  let body = options.body
  if (body && !(body instanceof FormData) && typeof body !== 'string') {
    body = JSON.stringify(body)
    headers.set('Content-Type', 'application/json')
  } else if (body && !(body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (csrfToken) headers.set('X-CSRF-Token', csrfToken)

  const response = await fetch(`/api${path}`, {
    ...options,
    body,
    credentials: 'same-origin',
    headers,
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.error || `Error ${response.status}`)
  return payload
}

async function post(path, data, csrfToken = '') {
  return apiRequest(path, { method: 'POST', body: data }, csrfToken)
}

function AuthScreen({ onAction, notice, resetToken }) {
  const [mode, setMode] = useState(resetToken ? 'reset' : 'login')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(notice || '')
  const [error, setError] = useState('')

  async function submit(event) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const data = Object.fromEntries(formData.entries())
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const result = await onAction(mode, { ...data, token: resetToken })
      setMessage(result || '')
      if (mode === 'register') setMode('resend')
      if (mode === 'reset') {
        setMode('login')
        window.history.replaceState({}, '', window.location.pathname)
      }
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const titles = {
    login: ['Tu espacio te espera.', 'Ingresa con tu correo y contraseña.'],
    register: ['Empieza a planear.', 'Crea tu cuenta para organizar tus eventos.'],
    recover: ['Recupera tu acceso.', 'Te enviaremos un enlace para restablecer tu contraseña.'],
    resend: ['Confirma tu correo.', 'Solicita un nuevo enlace de verificación.'],
    reset: ['Nueva contraseña.', 'Elige una contraseña de al menos 12 caracteres.'],
  }
  const [title, subtitle] = titles[mode]

  return (
    <main className="login-screen">
      <section className="login-panel">
        <div className="brand-mark"><CheckCheck size={21} strokeWidth={2.2} /></div>
        <p className="eyebrow">TU EVENTO, EN ORDEN</p>
        <h1>{title}</h1>
        <p className="login-copy">{subtitle}</p>
        <form onSubmit={submit} className="login-form">
          {mode === 'register' && <label>Nombre para mostrar<input name="display_name" autoComplete="name" maxLength={120} required /></label>}
          {mode !== 'reset' && <label>Correo electrónico<input name="email" type="email" autoComplete="email" required /></label>}
          {['login', 'register', 'reset'].includes(mode) && (
            <label>{mode === 'reset' ? 'Nueva contraseña' : 'Contraseña'}<input name="password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} minLength={12} required /></label>
          )}
          {message && <p className="form-success" role="status">{message}</p>}
          {error && <p className="form-error" role="alert">{error}</p>}
          <button className="button button-primary button-wide" disabled={busy}>
            {busy ? 'Procesando…' : ({ login: 'Entrar', register: 'Crear cuenta', recover: 'Enviar enlace', resend: 'Reenviar verificación', reset: 'Guardar contraseña' }[mode])}
            {!busy && <ArrowRight size={16} />}
          </button>
        </form>
        <div className="auth-links">
          {mode === 'login' && <>
            <button onClick={() => { setMode('register'); setMessage('') }}>Crear cuenta</button>
            <button onClick={() => { setMode('recover'); setMessage('') }}>Olvidé mi contraseña</button>
            <button onClick={() => { setMode('resend'); setMessage('') }}>Reenviar verificación</button>
          </>}
          {mode !== 'login' && mode !== 'reset' && <button onClick={() => { setMode('login'); setMessage(''); setError('') }}><ArrowLeft size={14} /> Volver a iniciar sesión</button>}
          {mode === 'reset' && <button onClick={() => { setMode('login'); window.history.replaceState({}, '', window.location.pathname) }}>Volver a iniciar sesión</button>}
        </div>
        <p className="login-foot"><ShieldCheck size={15} /> Sesión privada y protegida</p>
      </section>
      <div className="login-side" aria-hidden="true">
        <span className="side-kicker">PLANEAR TAMBIÉN ES CUIDAR</span>
        <div className="side-art"><div className="art-ring art-ring-one" /><div className="art-ring art-ring-two" /><div className="art-note"><span>familia</span><strong>06</strong><small>personas</small></div><div className="art-stamp"><Check size={25} /></div></div>
        <p>Un buen encuentro<br />se prepara con atención.</p>
      </div>
    </main>
  )
}

function RsvpPage({ token }) {
  const [invitation, setInvitation] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    post('/rsvp/lookup', { token })
      .then((response) => setInvitation(response.data))
      .catch((requestError) => setError(requestError.message))
  }, [token])

  async function answer(status) {
    setBusy(true)
    setError('')
    try {
      await post('/rsvp/respond', { token, status })
      setInvitation((current) => ({ ...current, status }))
      setMessage('Tu respuesta quedó registrada.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="login-screen rsvp-screen">
      <section className="login-panel rsvp-panel">
        <div className="brand-mark"><CheckCheck size={21} /></div>
        <p className="eyebrow">INVITACIÓN PERSONAL</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        {invitation ? <>
          <h1>Hola, {invitation.guest_name}.</h1>
          <p className="login-copy">Estás invitado/a a <strong>{invitation.event_name}</strong>{invitation.event_date ? ` · ${invitation.event_date}` : ''}.</p>
          <p className="rsvp-current">{message || (invitation.status === 'pending' ? '¿Nos acompañas?' : invitation.status === 'accepted' ? 'Tu respuesta: asistirás.' : 'Tu respuesta: no podrás asistir.')}</p>
          <div className="rsvp-actions">
            <button className="button button-primary" disabled={busy} onClick={() => answer('accepted')}><Check size={16} /> Sí, asistiré</button>
            <button className="button button-quiet" disabled={busy} onClick={() => answer('declined')}><X size={16} /> No podré</button>
          </div>
        </> : !error && <p className="login-copy">Cargando invitación…</p>}
      </section>
    </main>
  )
}

function EventForm({ event, onSave, onCancel, busy }) {
  return (
    <form className="inline-create-form" onSubmit={(event) => {
      event.preventDefault()
      const data = new FormData(event.currentTarget)
      onSave({ event_name: data.get('event_name'), event_date: data.get('event_date') || null })
    }}>
      <label className="field"><span>Nombre del evento</span><input name="event_name" defaultValue={event?.event_name || ''} maxLength={160} required placeholder="Ej. Boda de Ana y Luis" /></label>
      <label className="field"><span>Fecha</span><input name="event_date" type="date" defaultValue={event?.event_date || ''} /></label>
      <div className="inline-actions"><button type="button" className="button button-quiet" onClick={onCancel}>Cancelar</button><button className="button button-primary" disabled={busy}>{busy ? 'Guardando…' : event ? 'Guardar cambios' : 'Crear evento'} <ArrowRight size={15} /></button></div>
    </form>
  )
}

function ContactForm({ contact, onSave, onCancel, busy }) {
  return (
    <form className="contact-form" onSubmit={(event) => {
      event.preventDefault()
      const data = Object.fromEntries(new FormData(event.currentTarget).entries())
      onSave(data)
    }}>
      <label className="field"><span>Nombre</span><input name="name" defaultValue={contact?.name || ''} required maxLength={160} /></label>
      <label className="field"><span>Correo para RSVP (opcional)</span><input name="email" type="email" defaultValue={contact?.email || ''} maxLength={254} /></label>
      <div className="contact-form-row">
        <label className="field"><span>Edad</span><input name="age" type="number" min="0" max="120" defaultValue={contact?.age ?? ''} /></label>
        <label className="field"><span>Tipo</span><select name="type" defaultValue={contact?.type || 'adult'}><option value="adult">Adulto</option><option value="child">Niño/a</option></select></label>
      </div>
      <label className="field"><span>Restricciones alimentarias</span><input name="dietary_restrictions" defaultValue={contact?.dietary_restrictions || ''} /></label>
      <label className="field"><span>Notas</span><textarea name="notes" rows="2" defaultValue={contact?.notes || ''} /></label>
      <div className="inline-actions"><button type="button" className="button button-quiet" onClick={onCancel}>Cancelar</button><button className="button button-primary" disabled={busy}>{busy ? 'Guardando…' : contact ? 'Guardar cambios' : 'Guardar contacto'}</button></div>
    </form>
  )
}

function Workspace({ user, csrfToken, onLogout }) {
  const [view, setView] = useState('events')
  const [events, setEvents] = useState([])
  const [contacts, setContacts] = useState([])
  const [invitations, setInvitations] = useState([])
  const [families, setFamilies] = useState([])
  const [users, setUsers] = useState([])
  const [activeEvent, setActiveEvent] = useState(null)
  const [eventTab, setEventTab] = useState('invitations')
  const [selectedContacts, setSelectedContacts] = useState([])
  const [eventFormOpen, setEventFormOpen] = useState(false)
  const [editingEvent, setEditingEvent] = useState(null)
  const [contactFormOpen, setContactFormOpen] = useState(false)
  const [editingContact, setEditingContact] = useState(null)
  const [links, setLinks] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)
  const [search, setSearch] = useState('')

  async function loadEvents() {
    const result = await apiRequest('/events')
    setEvents(result.data || [])
  }

  async function loadContacts() {
    const result = await apiRequest('/contacts')
    setContacts(result.data || [])
  }

  async function loadEvent(event) {
    const [inviteResult, familyResult] = await Promise.all([
      apiRequest(`/events/${event.id}/invitations`),
      apiRequest(`/families?event_id=${event.id}`),
    ])
    setInvitations(inviteResult.data || [])
    setFamilies(familyResult.data || [])
  }

  useEffect(() => {
    loadEvents().catch((error) => setNotice({ kind: 'error', text: error.message }))
    loadContacts().catch((error) => setNotice({ kind: 'error', text: error.message }))
  }, [])

  useEffect(() => {
    if (activeEvent) loadEvent(activeEvent).catch((error) => setNotice({ kind: 'error', text: error.message }))
  }, [activeEvent?.id])

  async function saveEvent(data) {
    setBusy(true)
    try {
      const eventToEdit = editingEvent
      const result = eventToEdit
        ? await apiRequest(`/events/${eventToEdit.id}`, { method: 'PATCH', body: data }, csrfToken)
        : await post('/events', data, csrfToken)
      setEventFormOpen(false)
      setEditingEvent(null)
      await loadEvents()
      const created = { id: result.data.id, ...result.data }
      setActiveEvent(created)
      setView('event')
      setNotice({ kind: 'success', text: eventToEdit ? 'Evento actualizado.' : 'Evento creado.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function saveContact(data) {
    setBusy(true)
    try {
      if (editingContact) {
        await apiRequest(`/contacts/${editingContact.id}`, { method: 'PATCH', body: data }, csrfToken)
      } else {
        await post('/contacts', data, csrfToken)
      }
      setContactFormOpen(false)
      setEditingContact(null)
      await loadContacts()
      setNotice({ kind: 'success', text: editingContact ? 'Contacto actualizado.' : 'Contacto agregado al roster.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function createInvitations() {
    if (!activeEvent || !selectedContacts.length) return
    setBusy(true)
    try {
      const result = await post(`/events/${activeEvent.id}/invitations`, { contact_ids: selectedContacts }, csrfToken)
      setLinks((current) => ({ ...current, ...Object.fromEntries(result.data.map((invite) => [invite.id, invite.rsvp_url])) }))
      setSelectedContacts([])
      await loadEvent(activeEvent)
      const mailed = result.data.filter((invite) => invite.email_sent).length
      setNotice({ kind: 'success', text: `${result.data.length} invitación(es) creada(s).${mailed ? ` ${mailed} correo(s) enviado(s).` : ' Puedes copiar los enlaces RSVP.'}` })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function revokeInvitation(invitation) {
    try {
      await apiRequest(`/invitations/${invitation.id}`, { method: 'DELETE' }, csrfToken)
      setLinks((current) => { const next = { ...current }; delete next[invitation.id]; return next })
      await loadEvent(activeEvent)
      setNotice({ kind: 'success', text: 'Invitación revocada.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function deleteContact(contact) {
    if (!window.confirm(`¿Eliminar a ${contact.name} del roster?`)) return
    try {
      await apiRequest(`/contacts/${contact.id}`, { method: 'DELETE' }, csrfToken)
      await loadContacts()
      setNotice({ kind: 'success', text: 'Contacto eliminado; sus invitaciones conservan el historial.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function deleteEvent(event) {
    if (!window.confirm(`¿Eliminar “${event.event_name}” y sus invitaciones?`)) return
    try {
      await apiRequest(`/events/${event.id}`, { method: 'DELETE' }, csrfToken)
      setActiveEvent(null)
      setView('events')
      await loadEvents()
      setNotice({ kind: 'success', text: 'Evento eliminado.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function loadAdmin() {
    try {
      const result = await apiRequest('/admin/users')
      setUsers(result.data || [])
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function updateUser(userId, changes) {
    try {
      await apiRequest(`/admin/users/${userId}`, { method: 'PATCH', body: changes }, csrfToken)
      await loadAdmin()
      setNotice({ kind: 'success', text: 'Cuenta actualizada.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function copyLink(invitation) {
    try {
      let link = links[invitation.id]
      if (!link) {
        const result = await post(`/invitations/${invitation.id}/link`, {}, csrfToken)
        link = result.data.rsvp_url
        setLinks((current) => ({ ...current, [invitation.id]: link }))
      }
      await navigator.clipboard.writeText(link)
      setNotice({ kind: 'success', text: `Enlace RSVP copiado para ${invitation.guest_name}.` })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message || 'No se pudo copiar el enlace.' })
    }
  }

  function openAdmin() {
    setView('admin')
    loadAdmin()
  }

  const filteredContacts = contacts.filter((contact) => `${contact.name} ${contact.email || ''}`.toLocaleLowerCase('es').includes(search.trim().toLocaleLowerCase('es')))
  const acceptedCount = invitations.filter((invite) => invite.status === 'accepted').length
  const pendingCount = invitations.filter((invite) => invite.status === 'pending').length
  const declinedCount = invitations.filter((invite) => invite.status === 'declined').length

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="#inicio" onClick={(event) => { event.preventDefault(); setView('events'); setActiveEvent(null) }} aria-label="Tu evento, en orden"><span className="brand-mark"><CheckCheck size={20} /></span><span>encuentro<span className="brand-period">.</span></span></a>
        <div className="sidebar-section-label">ORGANIZACIÓN</div>
        <nav className="sidebar-nav" aria-label="Navegación principal">
          <button className={`nav-item ${view === 'events' || view === 'event' ? 'nav-item-active' : ''}`} onClick={() => { setView('events'); setActiveEvent(null); setEventFormOpen(false); setEditingEvent(null) }}><CalendarDays size={17} /> Eventos <span className="nav-count">{events.length}</span></button>
          <button className={`nav-item ${view === 'contacts' ? 'nav-item-active' : ''}`} onClick={() => { setView('contacts'); setActiveEvent(null) }}><Users size={17} /> Roster <span className="nav-count">{contacts.length}</span></button>
          {user.role === 'admin' && <button className={`nav-item ${view === 'admin' ? 'nav-item-active' : ''}`} onClick={openAdmin}><ShieldCheck size={17} /> Administración</button>}
        </nav>
        <div className="sidebar-bottom"><div className="privacy-note"><UserRound size={16} /><span>{user.display_name}<br />{user.email}</span></div><button className="logout-button" onClick={onLogout}><LogOut size={16} /> Cerrar sesión</button></div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div className="breadcrumb"><span>Workspace</span><span className="crumb-divider">/</span><strong>{view === 'event' ? activeEvent?.event_name : view === 'contacts' ? 'Roster' : view === 'admin' ? 'Administración' : 'Eventos'}</strong></div>
          <div className="topbar-actions"><span className="event-name">{user.role === 'admin' ? 'Administrador' : 'Cuenta personal'}</span><button className="icon-button" title="Actualizar" aria-label="Actualizar" onClick={() => view === 'contacts' ? loadContacts() : view === 'event' && activeEvent ? loadEvent(activeEvent) : loadEvents()}><RefreshCw size={17} /></button></div>
        </header>

        <div className="page-wrap">
          {notice && <div className={`notice notice-${notice.kind}`} role={notice.kind === 'error' ? 'alert' : 'status'}><CircleHelp size={16} /><span>{notice.text}</span><button className="icon-button quiet" onClick={() => setNotice(null)} aria-label="Cerrar aviso"><X size={15} /></button></div>}

          {view === 'events' && <>
            <section className="page-heading"><div><p className="eyebrow">TU ESPACIO</p><h1>Tus eventos,<br />a tu manera.</h1><p className="page-subtitle">Cada evento tiene su lista y sus respuestas.</p></div><button className="button button-primary" onClick={() => { setEditingEvent(null); setEventFormOpen(true) }}><Plus size={17} /> Nuevo evento</button></section>
            {eventFormOpen && <section className="management-panel"><div className="panel-title-row"><h2>Crear evento</h2><button className="icon-button quiet" onClick={() => setEventFormOpen(false)} aria-label="Cerrar"><X size={17} /></button></div><EventForm onSave={saveEvent} onCancel={() => setEventFormOpen(false)} busy={busy} /></section>}
            <section className="event-list" aria-label="Tus eventos">
              {events.length ? events.map((event) => <button className="event-row" key={event.id} onClick={() => { setActiveEvent(event); setView('event'); setEventTab('invitations') }}><span className="event-row-icon"><CalendarDays size={19} /></span><span className="event-row-info"><strong>{event.event_name}</strong><small>{event.event_date || 'Fecha por definir'}{user.role === 'admin' && event.owner_email ? ` · ${event.owner_email}` : ''}</small></span><ChevronRight size={17} /></button>) : !eventFormOpen && <div className="empty-state"><div className="empty-mark"><CalendarDays size={22} /></div><h2>Aún no tienes eventos</h2><p>Crea un evento para invitar a tu roster.</p><button className="button button-primary" onClick={() => setEventFormOpen(true)}><Plus size={16} /> Crear evento</button></div>}
            </section>
          </>}

          {view === 'event' && activeEvent && <>
            <section className="page-heading event-page-heading"><div><button className="back-link" onClick={() => { setView('events'); setActiveEvent(null); setEventFormOpen(false); setEditingEvent(null) }}><ArrowLeft size={15} /> Todos los eventos</button><p className="eyebrow">EVENTO</p><h1>{activeEvent.event_name}</h1><p className="page-subtitle">{activeEvent.event_date || 'Fecha por definir'} · Invitaciones y respuestas personales.</p></div><div className="event-actions"><button className="button button-secondary" onClick={() => { setEditingEvent(activeEvent); setEventFormOpen(true) }}><Pencil size={15} /> Editar</button><button className="button button-quiet" onClick={() => deleteEvent(activeEvent)}><Trash2 size={15} /> Eliminar evento</button></div></section>
            {eventFormOpen && <section className="management-panel"><div className="panel-title-row"><h2>Editar evento</h2><button className="icon-button quiet" onClick={() => { setEventFormOpen(false); setEditingEvent(null) }} aria-label="Cerrar"><X size={17} /></button></div><EventForm event={editingEvent} onSave={saveEvent} onCancel={() => { setEventFormOpen(false); setEditingEvent(null) }} busy={busy} /></section>}
            <section className="stats-row"><div className="stat-block"><span>Invitados</span><strong>{invitations.length.toString().padStart(2, '0')}</strong><small>personas</small></div><div className="stat-block stat-accent"><span>Aceptaron</span><strong>{acceptedCount.toString().padStart(2, '0')}</strong><small>asistirán</small></div><div className="stat-block"><span>Pendientes</span><strong>{pendingCount.toString().padStart(2, '0')}</strong><small>respuestas</small></div><div className="stat-block"><span>Declinaron</span><strong>{declinedCount.toString().padStart(2, '0')}</strong><small>respuestas</small></div></section>
            <div className="management-tabs" role="tablist"><button className={eventTab === 'invitations' ? 'management-tab active' : 'management-tab'} onClick={() => setEventTab('invitations')} role="tab" aria-selected={eventTab === 'invitations'}>Invitaciones <span>{invitations.length}</span></button><button className={eventTab === 'families' ? 'management-tab active' : 'management-tab'} onClick={() => setEventTab('families')} role="tab" aria-selected={eventTab === 'families'}>Lista familiar histórica <span>{families.length}</span></button></div>
            {eventTab === 'invitations' ? <section className="event-manager-grid">
              <div className="management-panel"><div className="panel-title-row"><div><p className="eyebrow">ROSTER DISPONIBLE</p><h2>Elige a quién invitar</h2></div><button className="button button-secondary" onClick={() => setView('contacts')}><Users size={15} /> Editar roster</button></div>
                {contacts.length ? <><div className="selectable-roster">{contacts.map((contact) => <label className="selectable-contact" key={contact.id}><input type="checkbox" checked={selectedContacts.includes(contact.id)} onChange={(event) => setSelectedContacts((current) => event.target.checked ? [...current, contact.id] : current.filter((id) => id !== contact.id))} /><span className="member-avatar">{contact.name.charAt(0).toUpperCase()}</span><span className="member-info"><strong>{contact.name}</strong><small>{contact.email || 'Sin correo'} · {contact.type === 'child' ? 'Niño/a' : 'Adulto'}</small></span></label>)}</div><button className="button button-primary" disabled={busy || !selectedContacts.length} onClick={createInvitations}><Plus size={15} /> Invitar seleccionados ({selectedContacts.length})</button></> : <div className="empty-state compact"><p>Agrega personas al roster antes de invitar.</p><button className="button button-secondary" onClick={() => setView('contacts')}><Plus size={15} /> Abrir roster</button></div>}
              </div>
              <div className="management-panel"><div className="panel-title-row"><div><p className="eyebrow">SEGUIMIENTO</p><h2>Respuestas</h2></div><span className="response-count">{invitations.length}</span></div>
                {invitations.length ? <div className="invitation-list">{invitations.map((invite) => <article className="invitation-row" key={invite.id}><span className={`rsvp-dot rsvp-${invite.status}`} /><div className="member-info"><strong>{invite.guest_name}</strong><small>{invite.guest_email || 'Enlace para compartir'}</small></div><span className={`rsvp-status rsvp-status-${invite.status}`}>{({ pending: 'Pendiente', accepted: 'Aceptó', declined: 'Declinó' })[invite.status]}</span><button className="icon-button" title="Copiar enlace RSVP" aria-label={`Copiar enlace para ${invite.guest_name}`} onClick={() => copyLink(invite)}><Copy size={15} /></button><button className="icon-button quiet" title="Revocar invitación" aria-label={`Revocar invitación de ${invite.guest_name}`} onClick={() => revokeInvitation(invite)}><X size={15} /></button></article>)}</div> : <div className="empty-state compact"><p>Todavía no hay invitaciones para este evento.</p></div>}
              </div>
            </section> : <section className="family-grid legacy-grid">{families.length ? families.map((family) => <article className="family-card" key={family.id}><div className="family-card-topline"><span className={`complexity complexity-${family.semaphore_color}`}><span className="complexity-dot" />Histórico</span><span className="status-label status-draft">{family.status === 'confirmed' ? 'Confirmada' : 'Borrador'}</span></div><div className="family-heading"><div><h3>{family.family_name}</h3><p>{family.members.length} integrantes · {family.actual_count} asistencia histórica</p></div></div><ul className="member-list">{family.members.map((member) => <li key={member.id}><span className="member-avatar">{member.name.charAt(0).toUpperCase()}</span><span className="member-info"><strong>{member.name}</strong><small>{member.dietary_restrictions || member.notes || 'Sin notas'}</small></span></li>)}</ul></article>) : <div className="empty-state"><p>No hay familias históricas en este evento.</p></div>}</section>}
          </>}

          {view === 'contacts' && <>
            <section className="page-heading"><div><p className="eyebrow">PERSONAS</p><h1>Tu roster<br />reutilizable.</h1><p className="page-subtitle">Contactos privados de {user.display_name}; elige a quién invitar en cada evento.</p></div><button className="button button-primary" onClick={() => setContactFormOpen(true)}><Plus size={17} /> Nuevo contacto</button></section>
            {contactFormOpen && <section className="management-panel contact-create-panel"><div className="panel-title-row"><h2>{editingContact ? 'Editar persona' : 'Agregar persona'}</h2><button className="icon-button quiet" onClick={() => { setContactFormOpen(false); setEditingContact(null) }} aria-label="Cerrar"><X size={17} /></button></div><ContactForm contact={editingContact} onSave={saveContact} onCancel={() => { setContactFormOpen(false); setEditingContact(null) }} busy={busy} /></section>}
            <label className="search-box roster-search"><Users size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar nombre o correo" aria-label="Buscar en el roster" /></label>
            <section className="contact-list">{filteredContacts.length ? filteredContacts.map((contact) => <article className="contact-row" key={contact.id}><span className="member-avatar">{contact.name.charAt(0).toUpperCase()}</span><div className="member-info"><strong>{contact.name}</strong><small>{contact.email || 'Sin correo para RSVP'} · {contact.type === 'child' ? 'Niño/a' : 'Adulto'}{contact.age !== null ? ` · ${contact.age} años` : ''}</small>{(contact.dietary_restrictions || contact.notes) && <small>{[contact.dietary_restrictions, contact.notes].filter(Boolean).join(' · ')}</small>}</div>{contact.owner_email && <span className="owner-label">{contact.owner_email}</span>}<button className="icon-button quiet" title="Editar contacto" aria-label={`Editar ${contact.name}`} onClick={() => { setEditingContact(contact); setContactFormOpen(true) }}><Pencil size={15} /></button><button className="icon-button quiet" title="Eliminar contacto" aria-label={`Eliminar ${contact.name}`} onClick={() => deleteContact(contact)}><Trash2 size={15} /></button></article>) : <div className="empty-state"><div className="empty-mark"><Users size={22} /></div><h2>El roster está vacío</h2><p>Agrega personas y reutilízalas en diferentes eventos.</p><button className="button button-primary" onClick={() => setContactFormOpen(true)}><Plus size={15} /> Agregar persona</button></div>}</section>
          </>}

          {view === 'admin' && user.role === 'admin' && <>
            <section className="page-heading"><div><p className="eyebrow">CONTROL GLOBAL · AUDITADO</p><h1>Administración.</h1><p className="page-subtitle">Cuentas y acceso a los datos del sistema.</p></div></section>
            <section className="management-panel"><div className="panel-title-row"><div><h2>Cuentas</h2><p>Desactiva el acceso sin eliminar el historial.</p></div><span className="response-count">{users.length}</span></div><div className="admin-user-list">{users.map((account) => <article className="admin-user-row" key={account.id}><span className="member-avatar"><UserRound size={15} /></span><div className="member-info"><strong>{account.display_name}</strong><small>{account.email}</small></div><select className="role-select" value={account.role} disabled={account.id === user.id} onChange={(event) => updateUser(account.id, { role: event.target.value })} aria-label={`Rol de ${account.email}`}><option value="user">Usuario</option><option value="admin">Admin</option></select><span className={`rsvp-status rsvp-status-${account.status === 'active' ? 'accepted' : 'pending'}`}>{account.status === 'active' ? 'Activa' : account.status === 'disabled' ? 'Desactivada' : 'Pendiente'}</span>{account.id !== user.id && <button className="button button-quiet" onClick={() => updateUser(account.id, { status: account.status === 'disabled' ? 'active' : 'disabled' })}>{account.status === 'disabled' ? 'Activar' : 'Desactivar'}</button>}</article>)}</div></section>
          </>}
        </div>
      </main>
    </div>
  )
}

function App() {
  const [user, setUser] = useState(null)
  const [csrfToken, setCsrfToken] = useState('')
  const [authChecked, setAuthChecked] = useState(false)
  const [authNotice, setAuthNotice] = useState('')
  const [resetToken, setResetToken] = useState('')
  const [rsvpToken, setRsvpToken] = useState('')

  useEffect(() => {
    const url = new URL(window.location.href)
    const fragment = new URLSearchParams(url.hash.slice(1))
    const verifyToken = fragment.get('verify') || url.searchParams.get('verify')
    const passwordToken = fragment.get('reset') || url.searchParams.get('reset')
    const responseToken = fragment.get('rsvp') || url.searchParams.get('rsvp')
    if (responseToken) {
      setRsvpToken(responseToken)
      window.history.replaceState({}, '', url.pathname)
      setAuthChecked(true)
      return
    }
    if (passwordToken) setResetToken(passwordToken)
    if (verifyToken) {
      post('/auth/verify', { token: verifyToken })
        .then((result) => setAuthNotice(result.message))
        .catch((error) => setAuthNotice(error.message))
        .finally(() => {
          window.history.replaceState({}, '', url.pathname)
          setAuthChecked(true)
        })
      return
    }
    if (passwordToken || fragment.has('rsvp') || url.searchParams.has('verify') || url.searchParams.has('reset') || url.searchParams.has('rsvp')) {
      window.history.replaceState({}, '', url.pathname)
    }
    apiRequest('/auth/me')
      .then((result) => { setUser(result.data.user); setCsrfToken(result.data.csrf_token) })
      .catch(() => {})
      .finally(() => setAuthChecked(true))
  }, [])

  async function authAction(mode, data) {
    if (mode === 'login') {
      const result = await post('/auth/login', data)
      setUser(result.data.user)
      setCsrfToken(result.data.csrf_token)
      return ''
    }
    if (mode === 'register') return (await post('/auth/register', data)).message
    if (mode === 'resend') return (await post('/auth/resend-verification', data)).message
    if (mode === 'recover') return (await post('/auth/forgot-password', data)).message
    if (mode === 'reset') return (await post('/auth/reset-password', data)).message
    return ''
  }

  async function logout() {
    try { await post('/auth/logout', {}, csrfToken) } catch { /* Expired sessions are cleared locally. */ }
    setUser(null)
    setCsrfToken('')
  }

  if (!authChecked) return <main className="auth-loading"><RefreshCw className="spin" size={22} /><span>Preparando tu espacio…</span></main>
  if (rsvpToken) return <RsvpPage token={rsvpToken} />
  if (!user) return <AuthScreen onAction={authAction} notice={authNotice} resetToken={resetToken} />
  return <Workspace user={user} csrfToken={csrfToken} onLogout={logout} />
}

export default App
