import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import './DonationsOverview.css';
import './MeetingsSearch.css';
import './EventsSearch.css';
import { API_BASE } from './apiConfig';

const EventForm = ({ eventData, onRefresh }) => {
  const [adding, setAdding] = useState(false);
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [error, setError] = useState(null);

  // Organization attendee state
  const [addingOrg, setAddingOrg] = useState(false);
  const [orgName, setOrgName] = useState('');
  const [orgError, setOrgError] = useState(null);

  const attendees = useMemo(() => {
    if (!eventData) return [];
    const ppl = Array.isArray(eventData.attendees_people) ? eventData.attendees_people : [];
    return ppl.map(p => ({
      id: p.id,
      first_name: p.first_name,
      last_name: p.last_name
    }));
  }, [eventData]);

  // Format event time from start_time/end_time (MySQL TIME -> local display)
  const renderEventTime = (ev) => {
    const fmt = (t) => {
      if (!t) return null;
      const parts = String(t).split(':');
      const h = parseInt(parts[0] || '0', 10);
      const m = parseInt(parts[1] || '0', 10);
      const d = new Date();
      d.setHours(isNaN(h) ? 0 : h, isNaN(m) ? 0 : m, 0, 0);
      return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    };
    const a = fmt(ev?.start_time);
    const b = fmt(ev?.end_time);
    if (a && b) return `${a} – ${b}`;
    if (a) return a;
    return 'N/A';
  };

  // Organizations linked to this event
  const attendeesOrgs = useMemo(() => {
    if (!eventData) return [];
    const orgs = Array.isArray(eventData.attendees_organizations) ? eventData.attendees_organizations : [];
    return orgs.map(o => ({
      id: o.id,
      name: o.name
    }));
  }, [eventData]);

  const handleAddAttendee = async () => {
    setError(null);
    if (!eventData?.id) return;
    if (!firstName.trim() || !lastName.trim()) {
      setError('Provide both first and last name');
      return;
    }
    setAdding(true);
    try {
      const formData = new FormData();
      formData.append('event_id', String(eventData.id));
      formData.append('first_name', firstName.trim());
      formData.append('last_name', lastName.trim());
      const res = await fetch(`${API_BASE}?route=/events/attendees/add`, {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (!res.ok || data.error) {
        setError(data.error || 'Failed to add attendee');
      } else {
        setFirstName('');
        setLastName('');
        await onRefresh?.();
      }
    } catch (e) {
      console.error(e);
      setError('Network error while adding attendee');
    } finally {
      setAdding(false);
    }
  };

  const handleRemoveAttendee = async (personId) => {
    if (!eventData?.id) return;
    try {
      const formData = new FormData();
      formData.append('event_id', String(eventData.id));
      formData.append('person_id', String(personId));
      const res = await fetch(`${API_BASE}?route=/events/attendees/remove`, {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (!res.ok || data.error) {
        alert(data.error || 'Failed to remove attendee');
      } else {
        await onRefresh?.();
      }
    } catch (e) {
      console.error(e);
      alert('Network error while removing attendee');
    }
  };

  // Add an organization attendee (ensures organization exists and links)
  const handleAddOrgAttendee = async () => {
    setOrgError(null);
    if (!eventData?.id) return;
    if (!orgName.trim()) {
      setOrgError('Provide organization name');
      return;
    }
    setAddingOrg(true);
    try {
      const formData = new FormData();
      formData.append('event_id', String(eventData.id));
      formData.append('org_name', orgName.trim());
      const res = await fetch(`${API_BASE}?route=/events/attendees/add-org`, {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (!res.ok || data.error) {
        setOrgError(data.error || 'Failed to add organization attendee');
      } else {
        setOrgName('');
        await onRefresh?.();
      }
    } catch (e) {
      console.error(e);
      setOrgError('Network error while adding organization');
    } finally {
      setAddingOrg(false);
    }
  };

  // Remove an organization attendee
  const handleRemoveOrgAttendee = async (organizationId) => {
    if (!eventData?.id) return;
    try {
      const formData = new FormData();
      formData.append('event_id', String(eventData.id));
      formData.append('organization_id', String(organizationId));
      const res = await fetch(`${API_BASE}?route=/events/attendees/remove-org`, {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (!res.ok || data.error) {
        alert(data.error || 'Failed to remove organization attendee');
      } else {
        await onRefresh?.();
      }
    } catch (e) {
      console.error(e);
      alert('Network error while removing organization attendee');
    }
  };

  return (
    <div className="results-section">
      <div className="results-header">
        <h2 className="results-title">
          <span className="results-icon">🎟️</span>
          Event
          {eventData?.id ? <span className="results-count"> (ID #{eventData.id})</span> : null}
        </h2>
      </div>

      {/* Event core details */}
      <div className="table-container event-details" style={{ marginBottom: '1rem' }}>
        <table className="meetings-table table-fixed w-full">
          <tbody>
            <tr>
              <td className="py-2 px-4 border label-cell">Title</td>
              <td className="py-2 px-4 border">{eventData?.title || 'N/A'}</td>
            </tr>
            <tr>
              <td className="py-2 px-4 border label-cell">Date</td>
              <td className="py-2 px-4 border">{eventData?.date ? new Date(eventData.date).toLocaleDateString() : 'N/A'}</td>
            </tr>
            <tr>
              <td className="py-2 px-4 border label-cell">Time</td>
              <td className="py-2 px-4 border">{renderEventTime(eventData)}</td>
            </tr>
            <tr>
              <td className="py-2 px-4 border label-cell">Location</td>
              <td className="py-2 px-4 border">{eventData?.location || 'N/A'}</td>
            </tr>
            <tr>
              <td className="py-2 px-4 border label-cell">Notes</td>
              <td className="py-2 px-4 border">{eventData?.notes || 'N/A'}</td>
            </tr>
            {eventData?.source && (
              <tr>
                <td className="py-2 px-4 border label-cell">Source</td>
                <td className="py-2 px-4 border">{eventData.source}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {/* Attendees */}
      <div className="results-header">
        <h3 className="results-title">
          <span className="results-icon">👥</span>
          Attendees
          {attendees.length > 0 ? <span className="results-count"> ({attendees.length})</span> : null}
        </h3>
      </div>

      <div className="table-container" style={{ marginBottom: '1rem' }}>
        <table className="meetings-table table-fixed w-full">
          <thead className="bg-gray-100">
            <tr>
              <th className="py-2 px-4 border">First</th>
              <th className="py-2 px-4 border">Last</th>
              <th className="py-2 px-4 border">Action</th>
            </tr>
          </thead>
          <tbody>
            {attendees.length === 0 ? (
              <tr>
                <td className="py-2 px-4 border" colSpan={3}>No attendees yet</td>
              </tr>
            ) : attendees.map(p => (
              <tr key={p.id}>
                <td className="py-2 px-4 border">{p.first_name}</td>
                <td className="py-2 px-4 border">{p.last_name}</td>
                <td className="py-2 px-4 border">
                  <button className="reset-button" onClick={() => handleRemoveAttendee(p.id)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Add attendee */}
      <div className="search-card">
        <div className="card-header">
          <h3 className="card-title">
            <span className="card-icon">➕</span>
            Add Attendee
          </h3>
        </div>
        <div className="inputs-grid">
          <div className="field">
            <span className="icon" aria-hidden>👤</span>
            <input
              type="text"
              placeholder="First name"
              className="input"
              value={firstName}
              onChange={(e) => setFirstName(e.target.value)}
            />
          </div>
          <div className="field">
            <span className="icon" aria-hidden>👤</span>
            <input
              type="text"
              placeholder="Last name"
              className="input"
              value={lastName}
              onChange={(e) => setLastName(e.target.value)}
            />
          </div>
        </div>
        {error && <div className="error-message"><span className="error-icon">⚠️</span>{error}</div>}
        <button className="search-button" disabled={adding} onClick={handleAddAttendee}>
          {adding ? 'Adding…' : 'Add Attendee'}
        </button>
      </div>

      {/* Organization Attendees */}
      <div className="results-header">
        <h3 className="results-title">
          <span className="results-icon">🏢</span>
          Organizations
          {attendeesOrgs.length > 0 ? <span className="results-count"> ({attendeesOrgs.length})</span> : null}
        </h3>
      </div>

      <div className="table-container" style={{ marginBottom: '1rem' }}>
        <table className="meetings-table table-fixed w-full">
          <thead className="bg-gray-100">
            <tr>
              <th className="py-2 px-4 border">Name</th>
              <th className="py-2 px-4 border">Action</th>
            </tr>
          </thead>
          <tbody>
            {attendeesOrgs.length === 0 ? (
              <tr>
                <td className="py-2 px-4 border" colSpan={2}>No organization attendees yet</td>
              </tr>
            ) : attendeesOrgs.map(o => (
              <tr key={o.id}>
                <td className="py-2 px-4 border">{o.name}</td>
                <td className="py-2 px-4 border">
                  <button className="reset-button" onClick={() => handleRemoveOrgAttendee(o.id)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Add organization attendee */}
      <div className="search-card">
        <div className="card-header">
          <h3 className="card-title">
            <span className="card-icon">➕</span>
            Add Organization
          </h3>
        </div>
        <div className="inputs-grid">
          <div className="field">
            <span className="icon" aria-hidden>🏢</span>
            <input
              type="text"
              placeholder="Organization name"
              className="input"
              value={orgName}
              onChange={(e) => setOrgName(e.target.value)}
            />
          </div>
        </div>
        {orgError && <div className="error-message"><span className="error-icon">⚠️</span>{orgError}</div>}
        <button className="search-button" disabled={addingOrg} onClick={handleAddOrgAttendee}>
          {addingOrg ? 'Adding…' : 'Add Organization'}
        </button>
      </div>
    </div>
  );
};

const EventsSearch = () => {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();

  const [q, setQ] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [events, setEvents] = useState([]);
  const [hasSearched, setHasSearched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState(null);

  const [activeEvent, setActiveEvent] = useState(null);
  const [activeLoading, setActiveLoading] = useState(false);

  const handleBackToHome = () => navigate('/home');

  const fetchEventFromMeeting = async (meetingId) => {
    if (!meetingId) return;
    setActiveLoading(true);
    setErr(null);
    try {
      const res = await fetch(`${API_BASE}?route=/events/from-meeting&meeting_id=${encodeURIComponent(meetingId)}`);
      const data = await res.json();
      if (!res.ok || data.error) {
        setErr(data.error || 'Failed to get event from meeting');
        setActiveEvent(null);
      } else {
        setActiveEvent(data);
      }
    } catch (e) {
      console.error(e);
      setErr('Network error while loading event');
      setActiveEvent(null);
    } finally {
      setActiveLoading(false);
    }
  };

  const fetchEventById = async (eventId) => {
    if (!eventId) return;
    setActiveLoading(true);
    setErr(null);
    try {
      const res = await fetch(`${API_BASE}?route=/events/by-id&event_id=${encodeURIComponent(eventId)}`);
      const data = await res.json();
      if (!res.ok || data.error) {
        setErr(data.error || 'Failed to load event');
        setActiveEvent(null);
      } else {
        setActiveEvent(data);
      }
    } catch (e) {
      console.error(e);
      setErr('Network error while loading event');
      setActiveEvent(null);
    } finally {
      setActiveLoading(false);
    }
  };

  const handleSearch = async () => {
    setHasSearched(true);
    setLoading(true);
    setErr(null);
    setEvents([]);
    try {
      const usp = new URLSearchParams();
      if (q) usp.append('q', q);
      if (startDate) usp.append('start_date', startDate);
      if (endDate) usp.append('end_date', endDate);
      const res = await fetch(`${API_BASE}?route=/events/search&${usp.toString()}`);
      const data = await res.json();
      if (!res.ok || data.error) {
        setErr(data.error || 'Failed to search events');
        setEvents([]);
      } else {
        setEvents(Array.isArray(data) ? data : []);
      }
    } catch (e) {
      console.error(e);
      setErr('Network error while searching events');
      setEvents([]);
    } finally {
      setLoading(false);
    }
  };

  const checkDeepLink = () => {
    const mid = params.get('meeting_id');
    const eid = params.get('event_id');
    if (mid) {
      fetchEventFromMeeting(mid);
    } else if (eid) {
      fetchEventById(eid);
    }
  };

  useEffect(() => {
    checkDeepLink();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshActive = async () => {
    if (!activeEvent?.id) {
      checkDeepLink();
    } else {
      await fetchEventById(activeEvent.id);
    }
  };

  return (
    <div className="donations-page">
      {/* Header Section */}
      <div className="donations-header">
        <div className="header-content">
          <div className="header-left">
            <div className="page-icon">🎟️</div>
            <div className="header-text">
              <h1 className="page-title">Event Search</h1>
              <p className="page-subtitle">Create and explore events derived from ministerial diaries</p>
            </div>
          </div>
          <button onClick={handleBackToHome} className="back-button">← Back to Home</button>
        </div>
      </div>

      <div className="donations-container" style={{ rowGap: '0.25rem' }}>
        {/* Admin notes */}
        <div
          className="search-card"
          style={{
            marginBottom: '0.25rem',
            width: '100%',                 // span full grid width so right edge aligns with column 2
            maxWidth: '100%',
            clear: 'both',
            gridColumn: '1 / -1',
            justifySelf: 'stretch',
            padding: '1.5rem 2rem'         // symmetric left/right padding
          }}
        >
          <div className="card-header">
            <h2 className="card-title">
              <span className="card-icon">🛠️</span>
              Admin tips: When to use Maintenance buttons
            </h2>
          </div>
          <div className="note" style={{ padding: '.25rem 0' }}>
            <ul style={{ marginLeft: '1rem' }}>
              <li><b>Create/Repair Events Tables</b> — Run this the first time you work with Events (or if you see missing table/column errors). It creates/repairs the <code>events</code> table and required columns/constraints (attendees_text, host_person_id, host_organization_id, unique meeting_id). Safe to re-run; it does not delete data.</li>
              <li><b>Bootstrap Events from Meetings</b> — Run after importing or editing ministerial diaries in <code>meetings</code> (or after updating attendee mappings). It creates any missing <code>events</code> rows, pre‑fills attendees from mapping/with_text, and always adds the diary’s minister as an attendee. Safe to re‑run. Hosting is not assumed (<code>host_person_id</code> remains NULL).</li>
            </ul>
            <div style={{ marginTop: '.5rem' }}>
              Open Admin: <a href="./php-api/index.php?route=/admin" target="_blank" rel="noopener noreferrer">Web Of Influence — Admin</a>
            </div>
          </div>
        </div>
        {/* Search */}
        <div className="search-section" style={{ gridColumn: '1 / span 1', position: 'static', top: 'auto', marginTop: '0rem' }}>
          <div className="search-card">
            <div className="card-header">
              <h2 className="card-title">
                <span className="card-icon">🔎</span>
                Search Events
              </h2>
              <button type="button" className="reset-button" onClick={() => {
                setQ(''); setStartDate(''); setEndDate('');
                setErr(null); setHasSearched(false); setEvents([]);
                setActiveEvent(null); setActiveLoading(false);
                setParams({}, { replace: true });
              }}>
                <span>↺</span>
                Reset
              </button>
            </div>

            <div className="inputs-grid">
              <div className="field">
                <span className="icon" aria-hidden>📝</span>
                <input
                  type="text"
                  className="input"
                  placeholder="Title or keyword"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                />
              </div>
            </div>

            <button
              type="button"
              className="search-button"
              onClick={handleSearch}
              disabled={loading}
            >
              {loading ? 'Searching...' : '🔍 Search'}
            </button>
          </div>
        </div>

        {/* Results */}
        {hasSearched && (
          <div className="results-section" style={{ gridColumn: '2 / span 1' }}>
            <div className="results-header">
              <h2 className="results-title">
                <span className="results-icon">📊</span>
                Search Results
                {events && events.length > 0 ? <span className="results-count"> ({events.length})</span> : null}
              </h2>
            </div>

            {err && (
              <div className="error-message">
                <span className="error-icon">⚠️</span>
                {err}
              </div>
            )}

            {loading && (
              <div className="loading-message">
                <span className="loading-spinner">⏳</span>
                Loading results...
              </div>
            )}

            {Array.isArray(events) && events.length > 0 && (
              <div className="table-container" style={{ marginBottom: '1rem' }}>
                <table className="meetings-table table-fixed w-full">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="py-2 px-4 border">Date</th>
                      <th className="py-2 px-4 border">Title</th>
                      <th className="py-2 px-4 border">Location</th>
                      <th className="py-2 px-4 border">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {events.map(ev => (
                      <tr key={ev.id}>
                        <td className="py-2 px-4 border">{ev.date ? new Date(ev.date).toLocaleDateString() : 'N/A'}</td>
                        <td className="py-2 px-4 border"><div className="w-full" style={{ whiteSpace: 'normal', wordBreak: 'break-word' }}>{ev.title || 'N/A'}</div></td>
                        <td className="py-2 px-4 border">{ev.location || 'N/A'}</td>
                        <td className="py-2 px-4 border">
                          <button className="search-button" onClick={() => {
                            setParams({ event_id: String(ev.id) }, { replace: true });
                            fetchEventById(ev.id);
                          }}>
                            Open
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {Array.isArray(events) && events.length === 0 && !loading && !err && (
              <div className="no-results">
                <span className="no-results-icon">🔍</span>
                <h3>No events found</h3>
                <p>Try adjusting your search criteria or date range.</p>
              </div>
            )}
          </div>
        )}

        {/* Active Event (from meeting_id or event_id) */}
        {(params.get('meeting_id') || params.get('event_id')) && (
          <div className="results-section" style={{ gridColumn: '2 / span 1' }}>
            {activeLoading && (
              <div className="loading-message" style={{ marginBottom: '1rem' }}>
                <span className="loading-spinner">⏳</span>
                Loading event…
              </div>
            )}
            {err && (
              <div className="error-message" style={{ marginBottom: '1rem' }}>
                <span className="error-icon">⚠️</span>
                {err}
              </div>
            )}
            {activeEvent && (
              <EventForm
                eventData={activeEvent}
                onRefresh={refreshActive}
              />
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default EventsSearch;
