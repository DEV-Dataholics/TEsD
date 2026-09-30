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

function formatTime(value) {
  if (!value) return ''
  return value.length > 5 ? value.slice(0, 5) : value
}

function googleMapsUrl(venueName, venueAddress) {
  const query = [venueName, venueAddress].filter(Boolean).join(', ').trim()
  if (!query) return null
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`
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
    provider: ['Registro de Proveedores.', 'Envía tus datos para colaborar en eventos.'],
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
          {mode === 'provider' && (
            <>
              <label>Nombre o Empresa<input name="nombre" placeholder="Ej. Banquetería Doña Rosa" required /></label>
              <label>Servicios ofrecidos<textarea name="detalles_servicios" rows="3" placeholder="Banquete, Sonido, Mobiliario, Fotografía..." required /></label>
            </>
          )}
          {message && <p className="form-success" role="status">{message}</p>}
          {error && <p className="form-error" role="alert">{error}</p>}
          <button className="button button-primary button-wide" disabled={busy}>
            {busy ? 'Procesando…' : ({ login: 'Entrar', register: 'Crear cuenta', recover: 'Enviar enlace', resend: 'Reenviar verificación', reset: 'Guardar contraseña', provider: 'Enviar solicitud' }[mode])}
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
  const [invite, setInvite] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [adults, setAdults] = useState(0)
  const [children, setChildren] = useState(0)

  useEffect(() => {
    post('/rsvp/lookup', { token })
      .then((response) => {
        setInvite(response.data)
        setAdults(response.data.actual_adults ?? response.data.estimated_adults)
        setChildren(response.data.actual_children ?? response.data.estimated_children)
      })
      .catch((requestError) => setError(requestError.message))
  }, [token])

  async function answer(status) {
    setBusy(true)
    setError('')
    try {
      await post('/rsvp/respond', { token, status, actual_adults: adults, actual_children: children })
      setInvite((current) => ({ ...current, status, actual_adults: status === 'accepted' ? adults : 0, actual_children: status === 'accepted' ? children : 0 }))
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
        <p className="eyebrow">INVITACIÓN FAMILIAR</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        {invite ? <>
          <h1>Hola, {invite.responsible_name}.</h1>
          <p className="login-copy">La familia <strong>{invite.family_label}</strong> está invitada a <strong>{invite.event_name}</strong>{invite.event_date ? ` · ${invite.event_date}` : ''}{invite.event_time ? ` · ${formatTime(invite.event_time)}` : ''}.</p>
          {invite.theme && <p className="rsvp-detail"><strong>Tema:</strong> {invite.theme}</p>}
          {(invite.venue_name || invite.venue_address) && <p className="rsvp-detail"><strong>Lugar:</strong> {[invite.venue_name, invite.venue_address].filter(Boolean).join(' · ')}{googleMapsUrl(invite.venue_name, invite.venue_address) && <> · <a href={googleMapsUrl(invite.venue_name, invite.venue_address)} target="_blank" rel="noreferrer">Ver en Google Maps</a></>}</p>}
          <p className="rsvp-current">{message || (invite.status === 'pending' ? '¿Nos acompañan? Ajusta cuántos son si hace falta.' : invite.status === 'accepted' ? `Su respuesta: asistirán ${invite.actual_adults} adulto(s) y ${invite.actual_children} niño(s).` : 'Su respuesta: no podrán asistir.')}</p>
          {invite.status !== 'declined' && <div className="contact-form-row rsvp-count-row">
            <label className="field"><span>Adultos que asistirán</span><input type="number" min="0" max="1000" value={adults} onChange={(event) => setAdults(Number(event.target.value))} /></label>
            <label className="field"><span>Niños que asistirán</span><input type="number" min="0" max="1000" value={children} onChange={(event) => setChildren(Number(event.target.value))} /></label>
          </div>}
          <div className="rsvp-actions">
            <button className="button button-primary" disabled={busy} onClick={() => answer('accepted')}><Check size={16} /> Sí, asistiremos</button>
            <button className="button button-quiet" disabled={busy} onClick={() => answer('declined')}><X size={16} /> No podremos</button>
          </div>
        </> : !error && <p className="login-copy">Cargando invitación…</p>}
      </section>
    </main>
  )
}

const EVENT_FACILITY_OPTIONS = [
  { key: 'speaker', label: 'Bocina / equipo de sonido' },
  { key: 'ice_boxes', label: 'Hieleras' },
  { key: 'fridge', label: 'Refrigerador' },
  { key: 'coal_grill', label: 'Asador de carbón' },
  { key: 'gas_grill', label: 'Asador de gas' },
  { key: 'pool', label: 'Alberca' },
  { key: 'parking', label: 'Estacionamiento' },
  { key: 'wifi', label: 'Wifi' },
  { key: 'tables_chairs', label: 'Mesas y sillas' },
  { key: 'restrooms', label: 'Baños' },
]

function EventForm({ event, onSave, onCancel, busy }) {
  const [facilities, setFacilities] = useState(() => new Set(event?.venue_facilities || []))
  const knownKeys = EVENT_FACILITY_OPTIONS.map((option) => option.key)
  const [otherFacilities, setOtherFacilities] = useState(() => (event?.venue_facilities || []).filter((facility) => !knownKeys.includes(facility)).join(', '))

  function toggleFacility(key) {
    setFacilities((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  return (
    <form className="inline-create-form event-form" onSubmit={(submitEvent) => {
      submitEvent.preventDefault()
      const data = Object.fromEntries(new FormData(submitEvent.currentTarget).entries())
      const extras = otherFacilities.split(',').map((item) => item.trim()).filter(Boolean)
      onSave({
        event_name: data.event_name,
        event_date: data.event_date || null,
        event_time: data.event_time || null,
        theme: data.theme || null,
        venue_name: data.venue_name || null,
        venue_address: data.venue_address || null,
        venue_capacity: data.venue_capacity || null,
        venue_facilities: [...facilities, ...extras],
        host_notes: data.host_notes || null,
      })
    }}>
      <label className="field"><span>Nombre del evento</span><input name="event_name" defaultValue={event?.event_name || ''} maxLength={160} required placeholder="Ej. Boda de Ana y Luis" /></label>
      <div className="contact-form-row">
        <label className="field"><span>Fecha</span><input name="event_date" type="date" defaultValue={event?.event_date || ''} /></label>
        <label className="field"><span>Hora</span><input name="event_time" type="time" defaultValue={event?.event_time || ''} /></label>
      </div>
      <label className="field"><span>Tema (opcional)</span><input name="theme" defaultValue={event?.theme || ''} maxLength={160} placeholder="Ej. Años 80, blanco y negro…" /></label>
      <label className="field"><span>Lugar (opcional)</span><input name="venue_name" defaultValue={event?.venue_name || ''} maxLength={160} placeholder="Ej. Salón Los Encinos" /></label>
      <label className="field"><span>Dirección (opcional)</span><input name="venue_address" defaultValue={event?.venue_address || ''} maxLength={300} placeholder="Calle, número, colonia…" /></label>

      <div className="host-only-panel">
        <p className="host-only-label">Solo para ti (el organizador); tus invitados no ven esto</p>
        <label className="field"><span>Aforo del lugar (opcional)</span><input name="venue_capacity" type="number" min="0" max="100000" defaultValue={event?.venue_capacity ?? ''} placeholder="Ej. 80" /></label>
        <fieldset className="facilities-fieldset">
          <legend>¿Con qué cuenta el lugar?</legend>
          <div className="facilities-grid">
            {EVENT_FACILITY_OPTIONS.map((option) => (
              <label className="facility-option" key={option.key}>
                <input type="checkbox" checked={facilities.has(option.key)} onChange={() => toggleFacility(option.key)} />
                <span>{option.label}</span>
              </label>
            ))}
          </div>
        </fieldset>
        <label className="field"><span>Otras instalaciones (separadas por coma)</span><input value={otherFacilities} onChange={(inputEvent) => setOtherFacilities(inputEvent.target.value)} placeholder="Ej. terraza, chapoteadero" /></label>
        <label className="field"><span>Notas del organizador (opcional)</span><textarea name="host_notes" rows="2" defaultValue={event?.host_notes || ''} placeholder="Ej. el dueño cobra depósito, llegar 1h antes…" /></label>
      </div>

      <div className="inline-actions"><button type="button" className="button button-quiet" onClick={onCancel}>Cancelar</button><button className="button button-primary" disabled={busy}>{busy ? 'Guardando…' : event ? 'Guardar cambios' : 'Crear evento'} <ArrowRight size={15} /></button></div>
    </form>
  )
}

function emptyMember(type) {
  return { id: null, type, name: '', age: '', dietary_restrictions: '', notes: '', expanded: false }
}

function resizeMemberList(list, size, type, onRemoved) {
  if (size < list.length) {
    const removed = list.slice(size).filter((member) => member.id)
    if (removed.length && onRemoved) onRemoved(removed.map((member) => member.id))
    return list.slice(0, size)
  }
  const next = list.slice()
  while (next.length < size) next.push(emptyMember(type))
  return next
}

function MemberAccordionRow({ label, member, onToggle, onChange }) {
  const title = member.name.trim() || label
  return (
    <div className="member-row">
      <button type="button" className="member-row-header" onClick={onToggle}>
        <ChevronRight size={14} className={member.expanded ? 'chevron chevron-open' : 'chevron'} />
        <span>{title}</span>
      </button>
      {member.expanded && <div className="member-row-body">
        <label className="field"><span>Nombre (opcional)</span><input value={member.name} maxLength={160} placeholder={label} onChange={(event) => onChange('name', event.target.value)} /></label>
        <label className="field"><span>Edad (opcional)</span><input type="number" min="0" max="120" value={member.age} onChange={(event) => onChange('age', event.target.value)} /></label>
        <label className="field"><span>Restricciones (opcional)</span><input value={member.dietary_restrictions} maxLength={300} placeholder="Ej. vegetariano" onChange={(event) => onChange('dietary_restrictions', event.target.value)} /></label>
        <label className="field"><span>Notas (opcional)</span><input value={member.notes} maxLength={300} onChange={(event) => onChange('notes', event.target.value)} /></label>
      </div>}
    </div>
  )
}

function FamilyGroupForm({ group, onSave, onCancel, busy, loadMembers }) {
  const [adults, setAdults] = useState(group?.estimated_adults ?? 1)
  const [children, setChildren] = useState(group?.estimated_children ?? 0)
  const [adultMembers, setAdultMembers] = useState(() => resizeMemberList([], group?.estimated_adults ?? 1, 'adult'))
  const [childMembers, setChildMembers] = useState(() => resizeMemberList([], group?.estimated_children ?? 0, 'child'))
  const [removedMemberIds, setRemovedMemberIds] = useState([])

  useEffect(() => {
    let cancelled = false
    async function bootstrap() {
      if (!group?.id || !loadMembers) return
      try {
        const existing = await loadMembers(group.id)
        if (cancelled) return
        const shape = (member) => ({ id: member.id, type: member.type, name: member.name || '', age: member.age === null || member.age === undefined ? '' : String(member.age), dietary_restrictions: member.dietary_restrictions || '', notes: member.notes || '', expanded: false })
        const existingAdults = existing.filter((member) => member.type === 'adult').map(shape)
        const existingChildren = existing.filter((member) => member.type === 'child').map(shape)
        setAdultMembers(resizeMemberList(existingAdults, adults, 'adult'))
        setChildMembers(resizeMemberList(existingChildren, children, 'child'))
      } catch {
        // Keep the empty accordion slots if members fail to load; details stay optional.
      }
    }
    bootstrap()
    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [group?.id])

  function updateAdults(value) {
    const count = Math.max(0, Math.min(100, Number(value) || 0))
    setAdults(count)
    setAdultMembers((prev) => resizeMemberList(prev, count, 'adult', (ids) => setRemovedMemberIds((current) => [...current, ...ids])))
  }

  function updateChildren(value) {
    const count = Math.max(0, Math.min(100, Number(value) || 0))
    setChildren(count)
    setChildMembers((prev) => resizeMemberList(prev, count, 'child', (ids) => setRemovedMemberIds((current) => [...current, ...ids])))
  }

  function toggleMember(setList, index) {
    setList((prev) => prev.map((member, i) => (i === index ? { ...member, expanded: !member.expanded } : member)))
  }

  function changeMember(setList, index, field, value) {
    setList((prev) => prev.map((member, i) => (i === index ? { ...member, [field]: value } : member)))
  }

  return (
    <form className="contact-form" onSubmit={(event) => {
      event.preventDefault()
      const data = Object.fromEntries(new FormData(event.currentTarget).entries())
      const finalRemoved = removedMemberIds.slice()
      const members = []
      for (const member of [...adultMembers, ...childMembers]) {
        const name = member.name.trim()
        if (name === '') {
          if (member.id) finalRemoved.push(member.id)
          continue
        }
        members.push({ id: member.id, type: member.type, name, age: member.age === '' ? '' : member.age, dietary_restrictions: member.dietary_restrictions, notes: member.notes })
      }
      onSave({ ...data, estimated_adults: adults, estimated_children: children, members, removedMemberIds: finalRemoved })
    }}>
      <label className="field"><span>Nombre de la familia</span><input name="family_label" defaultValue={group?.family_label || ''} required maxLength={160} placeholder="Ej. Familia Gutiérrez" /></label>
      <label className="field"><span>Punto de contacto responsable</span><input name="responsible_name" defaultValue={group?.responsible_name || ''} required maxLength={160} placeholder="Ej. Gustavo Gutiérrez" /></label>
      <label className="field"><span>Correo del responsable (opcional)</span><input name="responsible_email" type="email" defaultValue={group?.responsible_email || ''} maxLength={254} /></label>
      <label className="field"><span>Teléfono del responsable (opcional)</span><input name="responsible_phone" defaultValue={group?.responsible_phone || ''} maxLength={40} /></label>
      <div className="contact-form-row">
        <label className="field"><span>Adultos estimados</span><input type="number" min="0" max="100" value={adults} required onChange={(event) => updateAdults(event.target.value)} /></label>
        <label className="field"><span>Niños estimados</span><input type="number" min="0" max="100" value={children} required onChange={(event) => updateChildren(event.target.value)} /></label>
      </div>
      {(adultMembers.length > 0 || childMembers.length > 0) && <div className="member-accordion">
        <p className="member-accordion-hint">Detalles por persona (opcional). Déjalos vacíos si no los recuerdas.</p>
        {adultMembers.map((member, index) => (
          <MemberAccordionRow key={`adult-${index}`} label={`Adulto ${index + 1}`} member={member}
            onToggle={() => toggleMember(setAdultMembers, index)}
            onChange={(field, value) => changeMember(setAdultMembers, index, field, value)} />
        ))}
        {childMembers.map((member, index) => (
          <MemberAccordionRow key={`child-${index}`} label={`Niño ${index + 1}`} member={member}
            onToggle={() => toggleMember(setChildMembers, index)}
            onChange={(field, value) => changeMember(setChildMembers, index, field, value)} />
        ))}
      </div>}
      <label className="field"><span>Notas (opcional; restricciones, detalles sueltos)</span><textarea name="notes" rows="2" defaultValue={group?.notes || ''} placeholder="Ej. no recuerdo la edad de sus hijos" /></label>
      <div className="inline-actions"><button type="button" className="button button-quiet" onClick={onCancel}>Cancelar</button><button className="button button-primary" disabled={busy}>{busy ? 'Guardando…' : group ? 'Guardar cambios' : 'Guardar familia'}</button></div>
    </form>
  )
}

function Workspace({ user, csrfToken, onLogout }) {
  const [view, setView] = useState('events')
  const [events, setEvents] = useState([])
  const [familyGroups, setFamilyGroups] = useState([])
  const [familyInvites, setFamilyInvites] = useState([])
  const [families, setFamilies] = useState([])
  const [users, setUsers] = useState([])
  const [solicitudesProveedores, setSolicitudesProveedores] = useState([])
  const [activeEvent, setActiveEvent] = useState(null)
  const [eventTab, setEventTab] = useState('invitations')
  const [selectedGroups, setSelectedGroups] = useState([])
  const [eventFormOpen, setEventFormOpen] = useState(false)
  const [editingEvent, setEditingEvent] = useState(null)
  const [groupFormOpen, setGroupFormOpen] = useState(false)
  const [editingGroup, setEditingGroup] = useState(null)
  const [links, setLinks] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)
  const [search, setSearch] = useState('')

  async function loadEvents() {
    const result = await apiRequest('/events')
    setEvents(result.data || [])
  }

  async function loadFamilyGroups() {
    const result = await apiRequest('/family-groups')
    setFamilyGroups(result.data || [])
  }

  async function loadEvent(event) {
    const [inviteResult, familyResult] = await Promise.all([
      apiRequest(`/events/${event.id}/family-invites`),
      apiRequest(`/families?event_id=${event.id}`),
    ])
    setFamilyInvites(inviteResult.data || [])
    setFamilies(familyResult.data || [])
  }

  useEffect(() => {
    loadEvents().catch((error) => setNotice({ kind: 'error', text: error.message }))
    loadFamilyGroups().catch((error) => setNotice({ kind: 'error', text: error.message }))
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

  async function loadFamilyGroupMembers(groupId) {
    const result = await apiRequest(`/family-groups/${groupId}/members`)
    return result.data || []
  }

  async function saveFamilyGroup(data) {
    setBusy(true)
    try {
      const { members = [], removedMemberIds = [], ...groupData } = data
      let groupId = editingGroup?.id
      if (editingGroup) {
        await apiRequest(`/family-groups/${editingGroup.id}`, { method: 'PATCH', body: groupData }, csrfToken)
      } else {
        const result = await post('/family-groups', groupData, csrfToken)
        groupId = result.data.id
      }
      for (const memberId of removedMemberIds) {
        await apiRequest(`/family-group-members/${memberId}`, { method: 'DELETE' }, csrfToken)
      }
      for (const member of members) {
        const { id, ...memberData } = member
        if (id) {
          await apiRequest(`/family-group-members/${id}`, { method: 'PATCH', body: memberData }, csrfToken)
        } else {
          await post(`/family-groups/${groupId}/members`, memberData, csrfToken)
        }
      }
      setGroupFormOpen(false)
      setEditingGroup(null)
      await loadFamilyGroups()
      setNotice({ kind: 'success', text: editingGroup ? 'Familia actualizada.' : 'Familia agregada al roster.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function createFamilyInvites() {
    if (!activeEvent || !selectedGroups.length) return
    setBusy(true)
    try {
      const result = await post(`/events/${activeEvent.id}/family-invites`, { family_group_ids: selectedGroups }, csrfToken)
      setLinks((current) => ({ ...current, ...Object.fromEntries(result.data.map((invite) => [invite.id, invite.rsvp_url])) }))
      setSelectedGroups([])
      await loadEvent(activeEvent)
      const mailed = result.data.filter((invite) => invite.email_sent).length
      setNotice({ kind: 'success', text: `${result.data.length} invitación(es) creada(s).${mailed ? ` ${mailed} correo(s) enviado(s).` : ' Puedes copiar los enlaces RSVP.'}` })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function updateFamilyInvite(invite, changes) {
    try {
      await apiRequest(`/family-invites/${invite.id}`, {
        method: 'PATCH',
        body: {
          status: invite.status,
          actual_adults: invite.actual_adults ?? invite.estimated_adults,
          actual_children: invite.actual_children ?? invite.estimated_children,
          ...changes,
        },
      }, csrfToken)
      await loadEvent(activeEvent)
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function revokeFamilyInvite(invite) {
    try {
      await apiRequest(`/family-invites/${invite.id}`, { method: 'DELETE' }, csrfToken)
      setLinks((current) => { const next = { ...current }; delete next[invite.id]; return next })
      await loadEvent(activeEvent)
      setNotice({ kind: 'success', text: 'Invitación revocada.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function deleteFamilyGroup(group) {
    if (!window.confirm(`¿Eliminar a la familia ${group.family_label} del roster?`)) return
    try {
      await apiRequest(`/family-groups/${group.id}`, { method: 'DELETE' }, csrfToken)
      await loadFamilyGroups()
      setNotice({ kind: 'success', text: 'Familia eliminada; sus invitaciones conservan el historial.' })
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
      const [uRes, sRes] = await Promise.all([
        apiRequest('/admin/users'),
        apiRequest('/solicitudes_proveedores').catch(() => ({ data: [] }))
      ])
      setUsers(uRes.data || [])
      setSolicitudesProveedores(sRes.data || [])
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
    }
  }

  async function updateSolicitud(id, estado) {
    try {
      await apiRequest(`/solicitudes_proveedores/${id}`, { method: 'PATCH', body: { estado } }, csrfToken)
      setSolicitudesProveedores(prev => prev.map(s => s.id === id ? { ...s, estado } : s))
      setNotice({ kind: 'success', text: `Solicitud actualizada a ${estado}.` })
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

  async function copyLink(invite) {
    try {
      let link = links[invite.id]
      if (!link) {
        const result = await post(`/family-invites/${invite.id}/link`, {}, csrfToken)
        link = result.data.rsvp_url
        setLinks((current) => ({ ...current, [invite.id]: link }))
      }
      await navigator.clipboard.writeText(link)
      setNotice({ kind: 'success', text: `Enlace RSVP copiado para la familia ${invite.family_label}.` })
    } catch (error) {
      setNotice({ kind: 'error', text: error.message || 'No se pudo copiar el enlace.' })
    }
  }

  function openAdmin() {
    setView('admin')
    loadAdmin()
  }

  const filteredGroups = familyGroups.filter((group) => `${group.family_label} ${group.responsible_name}`.toLocaleLowerCase('es').includes(search.trim().toLocaleLowerCase('es')))
  const acceptedCount = familyInvites.filter((invite) => invite.status === 'accepted').length
  const pendingCount = familyInvites.filter((invite) => invite.status === 'pending').length
  const declinedCount = familyInvites.filter((invite) => invite.status === 'declined').length
  const estimatedGuests = familyInvites.reduce((sum, invite) => sum + invite.estimated_adults + invite.estimated_children, 0)
  const confirmedGuests = familyInvites.filter((invite) => invite.status === 'accepted').reduce((sum, invite) => sum + (invite.actual_adults ?? invite.estimated_adults) + (invite.actual_children ?? invite.estimated_children), 0)

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="#inicio" onClick={(event) => { event.preventDefault(); setView('events'); setActiveEvent(null) }} aria-label="Tu evento, en orden"><span className="brand-mark"><CheckCheck size={20} /></span><span>encuentro<span className="brand-period">.</span></span></a>
        <div className="sidebar-section-label">ORGANIZACIÓN</div>
        <nav className="sidebar-nav" aria-label="Navegación principal">
          <button className={`nav-item ${view === 'events' || view === 'event' ? 'nav-item-active' : ''}`} onClick={() => { setView('events'); setActiveEvent(null); setEventFormOpen(false); setEditingEvent(null) }}><CalendarDays size={17} /> Eventos <span className="nav-count">{events.length}</span></button>
          <button className={`nav-item ${view === 'contacts' ? 'nav-item-active' : ''}`} onClick={() => { setView('contacts'); setActiveEvent(null) }}><Users size={17} /> Familias <span className="nav-count">{familyGroups.length}</span></button>
          {user.role === 'admin' && <button className={`nav-item ${view === 'admin' ? 'nav-item-active' : ''}`} onClick={openAdmin}><ShieldCheck size={17} /> Administración</button>}
        </nav>
        <div className="sidebar-bottom"><div className="privacy-note"><UserRound size={16} /><span>{user.display_name}<br />{user.email}</span></div><button className="logout-button" onClick={onLogout}><LogOut size={16} /> Cerrar sesión</button></div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div className="breadcrumb"><span>Workspace</span><span className="crumb-divider">/</span><strong>{view === 'event' ? activeEvent?.event_name : view === 'contacts' ? 'Familias' : view === 'admin' ? 'Administración' : 'Eventos'}</strong></div>
          <div className="topbar-actions"><span className="event-name">{user.role === 'admin' ? 'Administrador' : 'Cuenta personal'}</span><button className="icon-button" title="Actualizar" aria-label="Actualizar" onClick={() => view === 'contacts' ? loadFamilyGroups() : view === 'event' && activeEvent ? loadEvent(activeEvent) : loadEvents()}><RefreshCw size={17} /></button></div>
        </header>

        <div className="page-wrap">
          {notice && <div className={`notice notice-${notice.kind}`} role={notice.kind === 'error' ? 'alert' : 'status'}><CircleHelp size={16} /><span>{notice.text}</span><button className="icon-button quiet" onClick={() => setNotice(null)} aria-label="Cerrar aviso"><X size={15} /></button></div>}

          {view === 'events' && <>
            <section className="page-heading"><div><p className="eyebrow">TU ESPACIO</p><h1>Tus eventos,<br />a tu manera.</h1><p className="page-subtitle">Cada evento tiene su lista y sus respuestas.</p></div><button className="button button-primary" onClick={() => { setEditingEvent(null); setEventFormOpen(true) }}><Plus size={17} /> Nuevo evento</button></section>
            {eventFormOpen && <section className="management-panel"><div className="panel-title-row"><h2>Crear evento</h2><button className="icon-button quiet" onClick={() => setEventFormOpen(false)} aria-label="Cerrar"><X size={17} /></button></div><EventForm onSave={saveEvent} onCancel={() => setEventFormOpen(false)} busy={busy} /></section>}
            <section className="event-list" aria-label="Tus eventos">
              {events.length ? events.map((event) => <button className="event-row" key={event.id} onClick={() => { setActiveEvent(event); setView('event'); setEventTab('invitations') }}><span className="event-row-icon"><CalendarDays size={19} /></span><span className="event-row-info"><strong>{event.event_name}</strong><small>{[event.event_date || 'Fecha por definir', formatTime(event.event_time), event.venue_name].filter(Boolean).join(' · ')}{user.role === 'admin' && event.owner_email ? ` · ${event.owner_email}` : ''}</small></span><ChevronRight size={17} /></button>) : !eventFormOpen && <div className="empty-state"><div className="empty-mark"><CalendarDays size={22} /></div><h2>Aún no tienes eventos</h2><p>Crea un evento para invitar a tu roster.</p><button className="button button-primary" onClick={() => setEventFormOpen(true)}><Plus size={16} /> Crear evento</button></div>}
            </section>
          </>}

          {view === 'event' && activeEvent && <>
            <section className="page-heading event-page-heading"><div><button className="back-link" onClick={() => { setView('events'); setActiveEvent(null); setEventFormOpen(false); setEditingEvent(null) }}><ArrowLeft size={15} /> Todos los eventos</button><p className="eyebrow">EVENTO</p><h1>{activeEvent.event_name}</h1><p className="page-subtitle">{[activeEvent.event_date || 'Fecha por definir', formatTime(activeEvent.event_time), activeEvent.theme].filter(Boolean).join(' · ')}</p></div><div className="event-actions"><button className="button button-secondary" onClick={() => { setEditingEvent(activeEvent); setEventFormOpen(true) }}><Pencil size={15} /> Editar</button><button className="button button-quiet" onClick={() => deleteEvent(activeEvent)}><Trash2 size={15} /> Eliminar evento</button></div></section>
            {(activeEvent.venue_name || activeEvent.venue_address || activeEvent.venue_capacity || (activeEvent.venue_facilities && activeEvent.venue_facilities.length) || activeEvent.host_notes) && <section className="event-details-panel">
              {(activeEvent.venue_name || activeEvent.venue_address) && <p><strong>Lugar:</strong> {[activeEvent.venue_name, activeEvent.venue_address].filter(Boolean).join(' · ')}{googleMapsUrl(activeEvent.venue_name, activeEvent.venue_address) && <> · <a href={googleMapsUrl(activeEvent.venue_name, activeEvent.venue_address)} target="_blank" rel="noreferrer">Ver en Google Maps</a></>}</p>}
              {(activeEvent.venue_capacity || (activeEvent.venue_facilities && activeEvent.venue_facilities.length) || activeEvent.host_notes) && <div className="host-only-panel host-only-summary">
                <p className="host-only-label">Solo para ti (el organizador)</p>
                {activeEvent.venue_capacity ? <p><strong>Aforo:</strong> {activeEvent.venue_capacity} personas</p> : null}
                {activeEvent.venue_facilities && activeEvent.venue_facilities.length ? <p><strong>Instalaciones:</strong> {activeEvent.venue_facilities.map((key) => EVENT_FACILITY_OPTIONS.find((option) => option.key === key)?.label || key).join(', ')}</p> : null}
                {activeEvent.host_notes ? <p><strong>Notas:</strong> {activeEvent.host_notes}</p> : null}
              </div>}
            </section>}
            {eventFormOpen && <section className="management-panel"><div className="panel-title-row"><h2>Editar evento</h2><button className="icon-button quiet" onClick={() => { setEventFormOpen(false); setEditingEvent(null) }} aria-label="Cerrar"><X size={17} /></button></div><EventForm event={editingEvent} onSave={saveEvent} onCancel={() => { setEventFormOpen(false); setEditingEvent(null) }} busy={busy} /></section>}
            <section className="stats-row"><div className="stat-block"><span>Familias</span><strong>{familyInvites.length.toString().padStart(2, '0')}</strong><small>invitadas</small></div><div className="stat-block"><span>Personas estimadas</span><strong>{estimatedGuests.toString().padStart(2, '0')}</strong><small>al confirmar todos</small></div><div className="stat-block stat-accent"><span>Confirmados</span><strong>{confirmedGuests.toString().padStart(2, '0')}</strong><small>personas asistirán</small></div><div className="stat-block"><span>Pendientes</span><strong>{pendingCount.toString().padStart(2, '0')}</strong><small>respuestas</small></div><div className="stat-block"><span>Declinaron</span><strong>{declinedCount.toString().padStart(2, '0')}</strong><small>respuestas</small></div></section>
            <div className="management-tabs" role="tablist"><button className={eventTab === 'invitations' ? 'management-tab active' : 'management-tab'} onClick={() => setEventTab('invitations')} role="tab" aria-selected={eventTab === 'invitations'}>Familias invitadas <span>{familyInvites.length}</span></button><button className={eventTab === 'families' ? 'management-tab active' : 'management-tab'} onClick={() => setEventTab('families')} role="tab" aria-selected={eventTab === 'families'}>Lista familiar histórica <span>{families.length}</span></button></div>
            {eventTab === 'invitations' ? <section className="event-manager-grid">
              <div className="management-panel"><div className="panel-title-row"><div><p className="eyebrow">ROSTER DISPONIBLE</p><h2>Elige qué familias invitar</h2></div><button className="button button-secondary" onClick={() => setView('contacts')}><Users size={15} /> Editar familias</button></div>
                {familyGroups.length ? <><div className="selectable-roster">{familyGroups.map((group) => <label className="selectable-contact" key={group.id}><input type="checkbox" checked={selectedGroups.includes(group.id)} onChange={(event) => setSelectedGroups((current) => event.target.checked ? [...current, group.id] : current.filter((id) => id !== group.id))} /><span className={`member-avatar ${group.semaphore_color === 'red' ? 'avatar-child' : ''}`}>{group.family_label.charAt(0).toUpperCase()}</span><span className="member-info"><strong>{group.family_label}</strong><small>{group.responsible_name} · {group.estimated_adults} adulto(s), {group.estimated_children} niño(s)</small></span></label>)}</div><button className="button button-primary" disabled={busy || !selectedGroups.length} onClick={createFamilyInvites}><Plus size={15} /> Invitar seleccionadas ({selectedGroups.length})</button></> : <div className="empty-state compact"><p>Agrega familias al roster antes de invitar.</p><button className="button button-secondary" onClick={() => setView('contacts')}><Plus size={15} /> Abrir familias</button></div>}
              </div>
              <div className="management-panel"><div className="panel-title-row"><div><p className="eyebrow">SEGUIMIENTO</p><h2>Respuestas</h2></div><span className="response-count">{familyInvites.length}</span></div>
                {familyInvites.length ? <div className="invitation-list">{familyInvites.map((invite) => <article className="invitation-row" key={invite.id}><span className={`rsvp-dot rsvp-${invite.status}`} /><div className="member-info"><strong>{invite.family_label}</strong><small>{invite.responsible_name} · estimado {invite.estimated_adults}A/{invite.estimated_children}N</small></div><div className="count-adjust" title="Conteo real de asistentes"><input type="number" min="0" max="1000" aria-label={`Adultos confirmados de ${invite.family_label}`} defaultValue={invite.actual_adults ?? invite.estimated_adults} onBlur={(event) => updateFamilyInvite(invite, { actual_adults: Number(event.target.value) })} /><span>A</span><input type="number" min="0" max="1000" aria-label={`Niños confirmados de ${invite.family_label}`} defaultValue={invite.actual_children ?? invite.estimated_children} onBlur={(event) => updateFamilyInvite(invite, { actual_children: Number(event.target.value) })} /><span>N</span></div><select className="role-select" value={invite.status} onChange={(event) => updateFamilyInvite(invite, { status: event.target.value })} aria-label={`Estado de ${invite.family_label}`}><option value="pending">Pendiente</option><option value="accepted">Aceptó</option><option value="declined">Declinó</option></select><button className="icon-button" title="Copiar enlace RSVP" aria-label={`Copiar enlace para ${invite.family_label}`} onClick={() => copyLink(invite)}><Copy size={15} /></button><button className="icon-button quiet" title="Revocar invitación" aria-label={`Revocar invitación de ${invite.family_label}`} onClick={() => revokeFamilyInvite(invite)}><X size={15} /></button></article>)}</div> : <div className="empty-state compact"><p>Todavía no hay familias invitadas a este evento.</p></div>}
              </div>
            </section> : <section className="family-grid legacy-grid">{families.length ? families.map((family) => <article className="family-card" key={family.id}><div className="family-card-topline"><span className={`complexity complexity-${family.semaphore_color}`}><span className="complexity-dot" />Histórico</span><span className="status-label status-draft">{family.status === 'confirmed' ? 'Confirmada' : 'Borrador'}</span></div><div className="family-heading"><div><h3>{family.family_name}</h3><p>{family.members.length} integrantes · {family.actual_count} asistencia histórica</p></div></div><ul className="member-list">{family.members.map((member) => <li key={member.id}><span className="member-avatar">{member.name.charAt(0).toUpperCase()}</span><span className="member-info"><strong>{member.name}</strong><small>{member.dietary_restrictions || member.notes || 'Sin notas'}</small></span></li>)}</ul></article>) : <div className="empty-state"><p>No hay familias históricas en este evento.</p></div>}</section>}
          </>}

          {view === 'contacts' && <>
            <section className="page-heading"><div><p className="eyebrow">FAMILIAS</p><h1>Tu roster<br />reutilizable.</h1><p className="page-subtitle">Familias privadas de {user.display_name}, con un responsable de contacto y conteos estimados; invítalas a cualquiera de tus eventos.</p></div><button className="button button-primary" onClick={() => setGroupFormOpen(true)}><Plus size={17} /> Nueva familia</button></section>
            {groupFormOpen && <section className="management-panel contact-create-panel"><div className="panel-title-row"><h2>{editingGroup ? 'Editar familia' : 'Agregar familia'}</h2><button className="icon-button quiet" onClick={() => { setGroupFormOpen(false); setEditingGroup(null) }} aria-label="Cerrar"><X size={17} /></button></div><FamilyGroupForm group={editingGroup} onSave={saveFamilyGroup} onCancel={() => { setGroupFormOpen(false); setEditingGroup(null) }} busy={busy} loadMembers={loadFamilyGroupMembers} /></section>}
            <label className="search-box roster-search"><Users size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar familia o responsable" aria-label="Buscar en el roster" /></label>
            <section className="contact-list">{filteredGroups.length ? filteredGroups.map((group) => <article className="contact-row" key={group.id}><span className={`complexity complexity-${group.semaphore_color}`}><span className="complexity-dot" /></span><span className="member-avatar">{group.family_label.charAt(0).toUpperCase()}</span><div className="member-info"><strong>{group.family_label}</strong><small>{group.responsible_name}{group.responsible_email ? ` · ${group.responsible_email}` : ''}{group.responsible_phone ? ` · ${group.responsible_phone}` : ''}</small><small>{group.estimated_adults} adulto(s), {group.estimated_children} niño(s){group.member_count ? ` · ${group.member_count} detalle(s) agregado(s)` : ''}</small>{group.notes && <small>{group.notes}</small>}</div>{group.owner_email && <span className="owner-label">{group.owner_email}</span>}<button className="icon-button quiet" title="Editar familia" aria-label={`Editar ${group.family_label}`} onClick={() => { setEditingGroup(group); setGroupFormOpen(true) }}><Pencil size={15} /></button><button className="icon-button quiet" title="Eliminar familia" aria-label={`Eliminar ${group.family_label}`} onClick={() => deleteFamilyGroup(group)}><Trash2 size={15} /></button></article>) : <div className="empty-state"><div className="empty-mark"><Users size={22} /></div><h2>El roster está vacío</h2><p>Agrega familias con su responsable y conteos estimados; los detalles finos son opcionales.</p><button className="button button-primary" onClick={() => setGroupFormOpen(true)}><Plus size={15} /> Agregar familia</button></div>}</section>
          </>}

          {view === 'admin' && user.role === 'admin' && <>
            <section className="page-heading"><div><p className="eyebrow">CONTROL GLOBAL · AUDITADO</p><h1>Administración.</h1><p className="page-subtitle">Cuentas y acceso a los datos del sistema.</p></div></section>
            <section className="management-panel"><div className="panel-title-row"><div><h2>Cuentas</h2><p>Desactiva el acceso sin eliminar el historial.</p></div><span className="response-count">{users.length}</span></div><div className="admin-user-list">{users.map((account) => <article className="admin-user-row" key={account.id}><span className="member-avatar"><UserRound size={15} /></span><div className="member-info"><strong>{account.display_name}</strong><small>{account.email}</small></div><select className="role-select" value={account.role} disabled={account.id === user.id} onChange={(event) => updateUser(account.id, { role: event.target.value })} aria-label={`Rol de ${account.email}`}><option value="user">Usuario</option><option value="admin">Admin</option></select><span className={`rsvp-status rsvp-status-${account.status === 'active' ? 'accepted' : 'pending'}`}>{account.status === 'active' ? 'Activa' : account.status === 'disabled' ? 'Desactivada' : 'Pendiente'}</span>{account.id !== user.id && <button className="button button-quiet" onClick={() => updateUser(account.id, { status: account.status === 'disabled' ? 'active' : 'disabled' })}>{account.status === 'disabled' ? 'Activar' : 'Desactivar'}</button>}</article>)}</div></section>
            <section className="management-panel" style={{ marginTop: '24px' }}>
              <div className="panel-title-row">
                <div><h2>Solicitudes de Proveedores</h2><p>Proveedores registrados para eventos.</p></div>
                <span className="response-count">{solicitudesProveedores.length}</span>
              </div>
              <div className="admin-user-list">
                {solicitudesProveedores.length ? solicitudesProveedores.map((sol) => (
                  <article className="admin-user-row" key={sol.id}>
                    <span className="member-avatar">{sol.nombre.charAt(0).toUpperCase()}</span>
                    <div className="member-info">
                      <strong>{sol.nombre}</strong>
                      <small>{sol.email} {sol.detalles_servicios ? `• ${sol.detalles_servicios}` : ''}</small>
                    </div>
                    <span className={`rsvp-status rsvp-status-${sol.estado === 'aprobado' ? 'accepted' : sol.estado === 'rechazado' ? 'declined' : 'pending'}`}>
                      {sol.estado === 'aprobado' ? 'Aprobada' : sol.estado === 'rechazado' ? 'Rechazada' : 'Pendiente'}
                    </span>
                    {sol.estado === 'pendiente' && (
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <button className="button button-primary" style={{ padding: '4px 10px', fontSize: '13px' }} onClick={() => updateSolicitud(sol.id, 'aprobado')}>Aprobar</button>
                        <button className="button button-quiet" style={{ padding: '4px 10px', fontSize: '13px' }} onClick={() => updateSolicitud(sol.id, 'rechazado')}>Rechazar</button>
                      </div>
                    )}
                  </article>
                )) : <p style={{ color: 'var(--text-muted)', padding: '16px 0' }}>No hay solicitudes de proveedores pendientes.</p>}
              </div>
            </section>
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
    if (mode === 'provider') return (await post('/solicitudes_proveedores/create_solicitud', data)).message
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
