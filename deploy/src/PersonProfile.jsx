import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as d3 from 'd3';
import './PersonProfile.css';
import { API_BASE } from './apiConfig';
import MeetingsTable from './MeetingsTable.jsx';

/**
 * PersonProfile (Web of Influence)
 * - Search by first/last name
 * - Builds a connections graph:
 *   - Person node
 *   - Party node (2023 overview) -> linked to person
 *   - Donor nodes -> linked to person (edge weight ~ amount)
 *   - Other people connected via the same donors (donor -> peer person edges)
 *   - Attendees from meetings (meeting -> person links)
 * - Tables for Donations (all years) and Meetings
 *
 * Back-end PHP endpoints used:
 *   GET /candidates/search?first_name=&last_name=
 *   GET /candidates/election-overview/2023/search/combined?first_name=&last_name=
 *   GET /party/search-id?party_id=
 *   GET /donations/by-person?people_id=ID  (returns year/date/amount plus donor_first/last/org)
 *   GET /donations/by-donor?donor_id=ID&exclude_people_id=ID (other people funded by donor)
 *   GET /ministerial_diaries/search-cand-filter?first_name=&last_name=
 */
const PersonProfile = () => {
  const navigate = useNavigate();

  const [searchQuery, setSearchQuery] = useState({
    firstName: '',
    lastName: '',
    orgName: ''
  });
  const [activeFirstName, setActiveFirstName] = useState('');
  const [activeLastName, setActiveLastName] = useState('');

  const [profileData, setProfileData] = useState(null); // {id, first_name, last_name}
  const [partyGuess, setPartyGuess] = useState(null); // string name
  const [partyId, setPartyId] = useState(null);

  const [donations, setDonations] = useState([]); // rows from /donations/by-person
  const [donorPeers, setDonorPeers] = useState({}); // donor_id -> [{people_id, first_name, last_name}]
  const [meetings, setMeetings] = useState([]);
  const [suggestions, setSuggestions] = useState([]); // suggested matches from historical overviews
  const [selectedPeopleId, setSelectedPeopleId] = useState(null); // explicit person id chosen from suggestions
  const [peopleMatches, setPeopleMatches] = useState([]); // direct matches from /candidates/search
  const [orgResults, setOrgResults] = useState([]); // donors/orgs results
  const [isOrgLoading, setIsOrgLoading] = useState(false);
  const [orgError, setOrgError] = useState(null);
  const [activeOrgPeers, setActiveOrgPeers] = useState({}); // donor_id -> peers (from /donations/by-donor)

  // Expandable sections
  const [showDonations, setShowDonations] = useState(false);
  const [showMeetings, setShowMeetings] = useState(false);
  const [selectedConnection, setSelectedConnection] = useState(null);
  const [connectionDetails, setConnectionDetails] = useState([]);
  // Graph sliders: edge thickness and number of connections to show (0 = all)
  const [edgeScale, setEdgeScale] = useState(1);
  const [connectionCap, setConnectionCap] = useState(0);

  const [isLoading, setIsLoading] = useState(false);
  const [hasSearched, setHasSearched] = useState(false);
  const [error, setError] = useState(null);

  // Sorting config for tables
  const [sortConfig, setSortConfig] = useState({ key: 'date', direction: 'asc' });

  // D3 refs
  const svgRef = useRef(null);
  const containerRef = useRef(null);
  const zoomRef = useRef(null);

  const handleBackToHome = () => navigate('/home');

  const handleReset = () => {
    setSearchQuery({ firstName: '', lastName: '', orgName: '' });
    setActiveFirstName('');
    setActiveLastName('');
    setProfileData(null);
    setDonations([]);
    setDonorPeers({});
    setMeetings([]);
    setSuggestions([]);
    setSelectedPeopleId(null);
    setError(null);
    setPartyGuess(null);
    setPartyId(null);
    setHasSearched(false);
    setPeopleMatches([]);
    setOrgResults([]);
    setOrgError(null);
    setIsOrgLoading(false);
    setActiveOrgPeers({});
    setShowDonations(false);
    setShowMeetings(false);
  };

  const handleSearchChange = (event) => {
    const { name, value } = event.target;
    setSearchQuery((prev) => ({ ...prev, [name]: value }));
  };

  const handleSearchSubmit = () => {
    const fn = (searchQuery.firstName || '').trim();
    const ln = (searchQuery.lastName || '').trim();
    const org = (searchQuery.orgName || '').trim();

    const hasPerson = !!fn && !!ln;
    const hasOrg = !!org;

    if (!hasPerson && !hasOrg) {
      setError('Please enter both first and last name, or an organisation.');
      setHasSearched(false);
      return;
    }

    setError(null);
    setSuggestions([]);
    setSelectedPeopleId(null);
    setHasSearched(true);

    if (hasPerson) {
      setActiveFirstName(fn);
      setActiveLastName(ln);
    } else {
      // Clear any previous person search state if only org search is requested
      setActiveFirstName('');
      setActiveLastName('');
      setProfileData(null);
      setDonations([]);
      setDonorPeers({});
      setMeetings([]);
    }

    if (hasOrg) {
      // Fire organisation search in parallel
      handleOrgSearchSubmit();
    } else {
      // Clear previous org results if not searching orgs
      setOrgResults([]);
      setActiveOrgPeers({});
      setOrgError(null);
    }
  };

  // Choose a suggestion and refetch with known people_id
  const handleUseSuggestion = (s) => {
    if (!s) return;
    setSelectedPeopleId(s.people_id ?? null);
    setActiveFirstName((s.first_name || '').trim());
    setActiveLastName((s.last_name || '').trim());
    setSearchQuery({ firstName: s.first_name || '', lastName: s.last_name || '', orgName: '' });
    setError(null);
    setHasSearched(true);
  };

  // Click a direct person match from /candidates/search
  const handleClickPersonMatch = (p) => {
    if (!p) return;
    setSelectedPeopleId(p.id ?? null);
    const fn = (p.first_name || '').trim();
    const ln = (p.last_name || '').trim();
    setActiveFirstName(fn);
    setActiveLastName(ln);
    setSearchQuery({ firstName: fn, lastName: ln, orgName: '' });
    setError(null);
    setHasSearched(true);
  };

  // Search organisations (donors) by org name
  const handleOrgSearchSubmit = async () => {
    const org = (searchQuery.orgName || '').trim();
    setOrgError(null);
    setOrgResults([]);
    setActiveOrgPeers({});
    if (!org) {
      setOrgError('Please enter an organisation name.');
      return;
    }
    try {
      setIsOrgLoading(true);
      const resp = await fetch(`${API_BASE}/donors/search?org_name=${encodeURIComponent(org)}`);
      if (!resp.ok) {
        try {
          const er = await resp.json();
          setOrgError(er?.error || 'Organisation search failed.');
        } catch {
          setOrgError('Organisation search failed.');
        }
        setOrgResults([]);
      } else {
        const rows = await resp.json();
        setOrgResults(Array.isArray(rows) ? rows : []);
      }
    } catch (e) {
      setOrgError('Organisation search failed.');
      setOrgResults([]);
    } finally {
      setIsOrgLoading(false);
    }
  };

  // Expand a donor to view connected people; clicking a person opens the Person form
  const loadOrgPeers = async (donorId) => {
    if (!donorId) return;
    try {
      const resp = await fetch(`${API_BASE}/donations/by-donor?donor_id=${encodeURIComponent(donorId)}`);
      if (!resp.ok) {
        setActiveOrgPeers((prev) => ({ ...prev, [donorId]: [] }));
        return;
      }
      const rows = await resp.json();
      setActiveOrgPeers((prev) => ({ ...prev, [donorId]: Array.isArray(rows) ? rows : [] }));
    } catch {
      setActiveOrgPeers((prev) => ({ ...prev, [donorId]: [] }));
    }
  };

  // Click a connection to show underlying entries (meetings/donations/affiliation)
  const handleClickConnection = (conn) => {
    try {
      setSelectedConnection(conn || null);
      let details = [];
      if (!conn) {
        setConnectionDetails([]);
        return;
      }
      const nodeType = (conn.nodeType || '').toLowerCase();
      if (nodeType === 'attendee') {
        const label = String(conn.label || '').trim().toLowerCase();
        const matched = (meetings || []).filter((m) => {
          const raw = m.attendees_names || m.with_text || '';
          const hay = String(Array.isArray(raw) ? raw.join('; ') : raw).toLowerCase();
          return label && hay.includes(label);
        });
        details = matched.map((m) => ({ kind: 'meeting', data: m }));
      } else if (nodeType === 'donor') {
        const donorId = (String(conn.id || '').startsWith('donor:') ? String(conn.id).split(':')[1] : null);
        const matched = (donations || []).filter((d) => donorId && String(d.donor_id) === String(donorId));
        details = matched.map((d) => ({ kind: 'donation', data: d }));
      } else if (nodeType === 'party') {
        details = [{ kind: 'party', data: { party: partyGuess, party_id: partyId } }];
      } else if (nodeType === 'person') {
        // For peer persons, we can show donations shared via donorPeers edges indirectly if needed.
        details = [];
      }
      setConnectionDetails(details);
    } catch {
      setConnectionDetails([]);
    }
  };

  // Fetch data whenever active names or selected person id change
  useEffect(() => {
    const fetchData = async () => {
      if (!activeFirstName && !activeLastName) {
        setProfileData(null);
        setDonations([]);
        setDonorPeers({});
        setMeetings([]);
        setIsLoading(false);
        return;
      }

      setIsLoading(true);
      setError(null);

      try {
        // 1) Resolve person (exact match on PHP API)
        const personResp = await fetch(
          `${API_BASE}/candidates/search?first_name=${encodeURIComponent(activeFirstName || '')}&last_name=${encodeURIComponent(activeLastName || '')}`
        );
        const personRows = personResp.ok ? await personResp.json() : [];
        const person = Array.isArray(personRows) && personRows.length > 0 ? personRows[0] : null;
        setProfileData(person);
        setPeopleMatches(Array.isArray(personRows) ? personRows : []);

        // 2) Try to infer party for 2023
        try {
          const ovResp = await fetch(
            `${API_BASE}/candidates/election-overview/2023/search/combined?first_name=${encodeURIComponent(activeFirstName || '')}&last_name=${encodeURIComponent(activeLastName || '')}`
          );
          if (ovResp.ok) {
            const ovRows = await ovResp.json();
            const first = Array.isArray(ovRows) && ovRows.length > 0 ? ovRows[0] : null;
            const pid = first && (first.party_id || first.partyId || first.party);
            const pname = first && (first.party_name || first.party || null);
            if (pid) {
              setPartyId(pid);
              if (!pname) {
                const pResp = await fetch(`${API_BASE}/party/search-id?party_id=${encodeURIComponent(pid)}`);
                if (pResp.ok) {
                  const pRows = await pResp.json();
                  const pName = Array.isArray(pRows) && pRows.length > 0 ? (pRows[0].party_name || pRows[0].name) : null;
                  if (pName) setPartyGuess(pName);
                }
              } else {
                setPartyGuess(pname);
              }
            } else if (pname) {
              setPartyGuess(pname);
            }
          }
        } catch {
          // ignore party inference failure
        }

        // 2b) If no exact person found, search historical overviews for suggestions
        let foundSuggestions = [];
        if (!person) {
          try {
            const years = ['2023', '2020', '2017', '2014', '2011'];
            const seen = new Set();
            for (const y of years) {
              const sResp = await fetch(
                `${API_BASE}/candidates/election-overview/${y}/search/combined?first_name=${encodeURIComponent(activeFirstName || '')}&last_name=${encodeURIComponent(activeLastName || '')}`
              );
              if (!sResp.ok) continue;
              const rows = await sResp.json();
              const arr = Array.isArray(rows) ? rows : [];
              for (const r of arr) {
                const pid = r.people_id ?? null;
                if (pid != null && !seen.has(pid)) {
                  seen.add(pid);
                  foundSuggestions.push({
                    people_id: pid,
                    first_name: r.first_name || activeFirstName || '',
                    last_name: r.last_name || activeLastName || '',
                    party_id: r.party_id ?? null,
                    party_name: r.party_name || null,
                    electorate_name: r.electorate_name || null,
                    year: r.year || y
                  });
                }
              }
            }
          } catch {
            // ignore suggestions failure
          }
          if (foundSuggestions.length > 0) {
            setSuggestions(foundSuggestions);
          } else {
            setSuggestions([]);
          }
          // 2c) If we still don't have a party, use the first suggestion that carries party info
          if (!partyGuess && Array.isArray(foundSuggestions) && foundSuggestions.length > 0) {
            const withParty = foundSuggestions.find((s) => s.party_name) || foundSuggestions[0];
            if (withParty) {
              if (withParty.party_id != null) setPartyId(withParty.party_id);
              if (withParty.party_name) setPartyGuess(withParty.party_name);
            }
          }
        }

        // 3) Donations across all supported years (single endpoint)
        let donationsRows = [];
        try {
          const peopleIdEff = (selectedPeopleId != null) ? selectedPeopleId : (person?.id ?? null);
          const url = peopleIdEff
            ? `${API_BASE}/donations/by-person?people_id=${encodeURIComponent(peopleIdEff)}`
            : `${API_BASE}/donations/by-person?first_name=${encodeURIComponent(activeFirstName || '')}&last_name=${encodeURIComponent(activeLastName || '')}`;
          const dResp = await fetch(url);
          if (dResp.ok) {
            const d = await dResp.json();
            donationsRows = Array.isArray(d) ? d : [];
          }
        } catch {
          donationsRows = [];
        }
        setDonations(donationsRows);

        // 4) For each donor, pull other connected people (shared donors)
        const uniqueDonors = Array.from(
          new Set(donationsRows.filter((r) => r.donor_id != null).map((r) => String(r.donor_id)))
        );
        // Limit to avoid huge graphs
        const maxDonors = 30;
        const donorsLimited = uniqueDonors.slice(0, maxDonors);

        const peersEntries = await Promise.all(
          donorsLimited.map(async (donorId) => {
            try {
              const peerResp = await fetch(
                `${API_BASE}/donations/by-donor?donor_id=${encodeURIComponent(donorId)}${(selectedPeopleId != null || (person?.id != null)) ? `&exclude_people_id=${encodeURIComponent((selectedPeopleId != null) ? selectedPeopleId : person.id)}` : ''}`
              );
              if (!peerResp.ok) return [donorId, []];
              const rows = await peerResp.json();
              const peers = Array.isArray(rows) ? rows : [];
              return [donorId, peers];
            } catch {
              return [donorId, []];
            }
          })
        );
        const peersMap = {};
        for (const [did, peers] of peersEntries) peersMap[did] = peers;
        setDonorPeers(peersMap);

        // 5) Meetings data
        let meetingsData = [];
        try {
          const meetingsResp = await fetch(
            `${API_BASE}/ministerial_diaries/search-cand-filter?first_name=${encodeURIComponent(activeFirstName || '')}&last_name=${encodeURIComponent(activeLastName || '')}`
          );
          if (meetingsResp.ok) {
            const md = await meetingsResp.json();
            meetingsData = Array.isArray(md) ? md : [];
          }
        } catch {
          meetingsData = [];
        }
        setMeetings(meetingsData);

        if (!person && donationsRows.length === 0 && meetingsData.length === 0) {
          if (Array.isArray(foundSuggestions) && foundSuggestions.length > 0) {
            // Show suggestions UI instead of an error banner
            setError(null);
          } else {
            setError('No data found for this person.');
          }
        }
      } catch (e) {
        console.error('Error fetching person profile data:', e);
        setError('An error occurred while fetching data. Please try again.');
      } finally {
        setIsLoading(false);
      }
    };

    fetchData();
  }, [activeFirstName, activeLastName, selectedPeopleId]);

  const sortTable = (key) => {
    let direction = 'asc';
    if (sortConfig.key === key && sortConfig.direction === 'asc') direction = 'desc';
    setSortConfig({ key, direction });
  };

  const sortedDonations = useMemo(() => {
    const arr = [...donations];
    arr.sort((a, b) => {
      if (sortConfig.key === 'date') {
        const ad = a.date ? new Date(a.date) : new Date(`${a.year}-01-01`);
        const bd = b.date ? new Date(b.date) : new Date(`${b.year}-01-01`);
        return sortConfig.direction === 'asc' ? ad - bd : bd - ad;
      }
      if (sortConfig.key === 'amount') {
        const aAmt = Number(a.amount) || 0;
        const bAmt = Number(b.amount) || 0;
        return sortConfig.direction === 'asc' ? aAmt - bAmt : bAmt - aAmt;
      }
      return 0;
    });
    return arr;
  }, [donations, sortConfig]);

  const sortedMeetings = useMemo(() => {
    const arr = [...meetings];
    arr.sort((a, b) => {
      if (sortConfig.key === 'date') {
        return sortConfig.direction === 'asc'
          ? new Date(a.date) - new Date(b.date)
          : new Date(b.date) - new Date(a.date);
      }
      return 0;
    });
    return arr;
  }, [meetings, sortConfig]);


  // Programmatic zoom controls
  const handleZoom = (direction) => {
    if (!svgRef.current || !zoomRef.current) return;
    const svg = d3.select(svgRef.current);
    const factor = direction === 'in' ? 1.2 : 1 / 1.2;
    try {
      svg.transition().duration(200).call(zoomRef.current.scaleBy, factor);
    } catch {
      // ignore if zoom not initialized yet
    }
  };

  // Build graph data (nodes, links)
  const graphData = useMemo(() => {
    const nodes = [];
    const links = [];

    const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
    const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
    const personLabel = profileLabel || inputLabel || 'Selected Person';
    const personId = (selectedPeopleId != null)
      ? `person:${selectedPeopleId}`
      : ((profileData && profileData.id != null)
        ? `person:${profileData.id}`
        : `person:${personLabel}`);

    // Ensure a person node is always present
    nodes.push({
      id: personId,
      label: personLabel,
      type: 'person'
    });

    const partyName = partyGuess || null;

    if (partyName) {
      const pId = partyId ? `partyid:${partyId}` : `party:${partyName}`;
      nodes.push({ id: pId, label: partyName, type: 'party' });
      if (personId) {
        links.push({
          source: personId,
          target: pId,
          type: 'affiliation',
          value: 1
        });
      }
    }

    // Aggregate donation amounts per donor for edge weighting
    const donorAgg = new Map();
    for (const d of donations) {
      const did = d.donor_id != null ? String(d.donor_id) : null;
      if (!did) continue;
      const prev = donorAgg.get(did) || { amount: 0, rows: [] };
      prev.amount += Number(d.amount) || 0;
      prev.rows.push(d);
      donorAgg.set(did, prev);
    }

    // Create donor nodes and link to person
    for (const [did, agg] of donorAgg.entries()) {
      const any = agg.rows[0] || {};
      const df = any.donor_first_name ? String(any.donor_first_name) : '';
      const dl = any.donor_last_name ? String(any.donor_last_name) : '';
      const org = any.donor_org_name ? String(any.donor_org_name) : '';
      const label = org || [df, dl].filter(Boolean).join(' ') || `Donor ${did}`;
      const donorNodeId = `donor:${did}`;

      if (!nodes.some((n) => n.id === donorNodeId)) {
        nodes.push({ id: donorNodeId, label, type: 'donor' });
      }

      if (personId) {
        links.push({
          source: personId,
          target: donorNodeId,
          type: 'donation',
          value: Math.max(1, Math.log10(Math.abs(agg.amount) + 1))
        });
      }

      // Add other people this donor has funded
      const peers = donorPeers[did] || [];
      for (const pr of peers) {
        const peerId = Number(pr.people_id);
        const peerLabel = [pr.first_name || '', pr.last_name || ''].filter(Boolean).join(' ') || `Person ${peerId}`;
        const peerNodeId = `person:${peerId}`;
        if (!nodes.some((n) => n.id === peerNodeId)) {
          nodes.push({ id: peerNodeId, label: peerLabel, type: 'person' });
        }
        links.push({
          source: donorNodeId,
          target: peerNodeId,
          type: 'donation',
          value: 1
        });
      }
    }

    // Attendee nodes from meetings (use attendees_names if available, else with_text)
    const ensureNode = (id, label, type) => {
      if (!nodes.some((n) => n.id === id)) {
        nodes.push({ id, label, type });
      }
    };

    const addAttendeeLink = (label) => {
      const name = (label || '').trim();
      if (!name) return;
      // skip generic placeholders
      if (/^(attendees|officials|multiple ministers|ministers|delegation|representatives|committee|board|council|group|members|staff)$/i.test(name)) {
        return;
      }
      const id = `attendee:${name}`;
      ensureNode(id, name, 'attendee');
      if (personId) {
        links.push({ source: personId, target: id, type: 'meeting', value: 1 });
      }
    };

    for (const m of meetings || []) {
      const raw = m.attendees_names || m.with_text || '';
      if (!raw) continue;
      const parts = String(raw).split(/;|,|&| and |\/|\+/gi);
      for (let p of parts) {
        addAttendeeLink(p);
      }
    }

    return { nodes, links };
  }, [donations, donorPeers, meetings, profileData, activeFirstName, activeLastName, partyGuess, partyId, selectedPeopleId]);

  // Count direct connections for the selected person (for slider max)
  const personDegree = useMemo(() => {
    try {
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const personLabel = profileLabel || inputLabel || 'Selected Person';
      const pid = (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${personLabel}`);

      let deg = 0;
      for (const l of (graphData.links || [])) {
        const s = (typeof l.source === 'object') ? l.source?.id : l.source;
        const t = (typeof l.target === 'object') ? l.target?.id : l.target;
        if (s === pid || t === pid) deg++;
      }
      return deg;
    } catch {
      return 0;
    }
  }, [graphData, profileData, activeFirstName, activeLastName, selectedPeopleId]);

  const connections = useMemo(() => {
    // Reconstruct current personId to match the graph
    const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
    const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
    const personLabel = profileLabel || inputLabel || 'Selected Person';
    const pid = (selectedPeopleId != null)
      ? `person:${selectedPeopleId}`
      : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${personLabel}`);

    // Recreate current graph nodes/links (depends on same deps as graphData)
    const nodes = new Map();
    // Reuse graphData if possible for efficiency
    try {
      (graphData.nodes || []).forEach(n => nodes.set(n.id, n));
    } catch {
      // noop
    }
    const list = [];
    const seen = new Map(); // id -> {label, types:Set}

    try {
      for (const l of (graphData.links || [])) {
        let otherId = null;
        if (l.source && typeof l.source === 'object' && l.source.id === pid) {
          otherId = typeof l.target === 'object' ? l.target.id : l.target;
        } else if (l.target && typeof l.target === 'object' && l.target.id === pid) {
          otherId = typeof l.source === 'object' ? l.source.id : l.source;
        } else if (l.source === pid) {
          otherId = l.target;
        } else if (l.target === pid) {
          otherId = l.source;
        }
        if (!otherId) continue;
        const n = nodes.get(otherId);
        if (!n) continue;

        const src = l.type === 'donation'
          ? 'Donation'
          : (l.type === 'meeting' ? 'Meeting' : (l.type === 'affiliation' ? 'Affiliation' : (l.type || 'Link')));

        if (!seen.has(otherId)) {
          seen.set(otherId, { id: otherId, label: n.label || otherId, nodeType: n.type || '', sources: new Set([src]) });
        } else {
          seen.get(otherId).sources.add(src);
        }
      }
    } catch {
      // ignore
    }

    for (const v of seen.values()) {
      list.push({
        id: v.id,
        label: v.label,
        nodeType: v.nodeType,
        sources: Array.from(v.sources)
      });
    }

    // Sort by nodeType then label
    list.sort((a, b) => {
      const t = a.nodeType.localeCompare(b.nodeType);
      if (t !== 0) return t;
      return a.label.localeCompare(b.label);
    });
    return list;
  }, [graphData, profileData, activeFirstName, activeLastName, selectedPeopleId]);

  // Render D3 force-directed graph
  useEffect(() => {
    const svg = d3.select(svgRef.current);

    // Clear previous
    svg.selectAll('*').remove();

    // Use a safe width even if the container has not measured yet (prevent negative/zero viewBox width)
    let cw = containerRef.current?.clientWidth || 0;
    let width = cw - 20;
    if (!width || !Number.isFinite(width) || width <= 0) width = 900;
    const height = 500;

    svg.attr('viewBox', [0, 0, width, height].join(' ')).attr('width', '100%').attr('height', height);

    const zoomLayer = svg.append('g');

    // DEBUG: baseline marker (DEV only)
    if (import.meta?.env?.DEV) {
      const dbg = zoomLayer.append('g').attr('aria-hidden', 'true');
      dbg
        .append('circle')
        .attr('cx', 24)
        .attr('cy', 24)
        .attr('r', 6)
        .attr('fill', '#10b981')
        .attr('stroke', '#065f46')
        .attr('stroke-width', 1.5)
        .attr('opacity', 0.6);

      dbg
        .append('text')
        .text('DEBUG')
        .attr('x', 40)
        .attr('y', 28)
        .attr('font-size', 12)
        .attr('fill', '#6b7280');
    }

    const color = d3
      .scaleOrdinal()
      .domain(['person', 'party', 'donor', 'attendee', 'org', 'region'])
      .range(['#1f77b4', '#9467bd', '#2ca02c', '#17becf', '#ff7f0e', '#8c564b']);

    const linkStrength = (l) => (l.type === 'donation' ? 0.2 : 0.1);
    const linkDistance = (l) => (l.type === 'donation' ? 80 + (l.value || 1) * 20 : 120);

    // Clone data to avoid mutating memoized objects and seed initial positions
    // Determine the selected person id (local in effect)
    const personIdLocal = (() => {
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const lbl = profileLabel || inputLabel || 'Selected Person';
      return (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${lbl}`);
    })();

    const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

    // Build list of links directly connected to the selected person
    const edgesToPerson = graphData.links
      .filter((l) => {
        const s = idOf(l.source);
        const t = idOf(l.target);
        return s === personIdLocal || t === personIdLocal;
      })
      .map((l) => ({ ...l }));

    // Sort direct connections by weight and apply cap based on slider (0 => show all)
    edgesToPerson.sort((a, b) => (b.value || 1) - (a.value || 1));
    const capBase = edgesToPerson.length;
    const cap = connectionCap > 0 ? Math.min(connectionCap, capBase) : capBase;

    // Pick top-k direct connections, then include any secondary links among the selected nodes
    const selectedDirect = edgesToPerson.slice(0, cap);
    const usedIds = new Set();
    for (const l of selectedDirect) {
      usedIds.add(idOf(l.source));
      usedIds.add(idOf(l.target));
    }
    const linksData = (graphData.links || []).map((l) => ({ ...l }));

    // Only keep nodes participating in the kept links (+ always keep the selected person node)
    const nodesData = (graphData.nodes || []).map((d) => ({ ...d }));
    nodesData.forEach((n) => {
      if (n.x == null || Number.isNaN(n.x)) n.x = width / 2;
      if (n.y == null || Number.isNaN(n.y)) n.y = height / 2;
    });

    // Compute current person id for click source-detection
    const personIdForEdges = (() => {
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const lbl = profileLabel || inputLabel || 'Selected Person';
      return (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${lbl}`);
    })();

    // Debug: ensure we have at least the person node
    try {
      // eslint-disable-next-line no-console
      console.debug('WOI PersonProfile graph', { nodes: nodesData.length, node0: nodesData[0], links: linksData.length });
    } catch {}

    const simulation = d3
      .forceSimulation(nodesData)
      .force('link', d3.forceLink(linksData).id((d) => d.id).distance(linkDistance).strength(linkStrength))
      .force('charge', d3.forceManyBody().strength(-250))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius(30));

    const link = zoomLayer
      .append('g')
      .attr('stroke', '#999')
      .attr('stroke-opacity', 0.6)
      .selectAll('line')
      .data(linksData)
      .join('line')
      .attr('class', 'graph-link')
      .attr('stroke-width', (d) => (1 + (d.value || 1) * 0.5) * edgeScale);

    const node = zoomLayer
      .append('g')
      .attr('stroke', '#fff')
      .attr('stroke-width', 1.5)
      .selectAll('circle')
      .data(nodesData)
      .join('circle')
      .attr('class', 'graph-node')
      .attr('r', (d) => (d.type === 'person' ? 15 : 9))
      .attr('fill', (d) => color(d.type))   // attribute fill for maximum compatibility
      .style('fill', (d) => color(d.type))  // inline style fill to override any CSS
      .style('opacity', 1)
      .call(
        d3
          .drag()
          .on('start', (event, d) => {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            d.fx = d.x;
            d.fy = d.y;
          })
          .on('drag', (event, d) => {
            d.fx = event.x;
            d.fy = event.y;
          })
          .on('end', (event, d) => {
            if (!event.active) simulation.alphaTarget(0);
            d.fx = null;
            d.fy = null;
          })
      )
      .on('click', (event, d) => {
        try {
          const destId = typeof d?.id === 'string' ? d.id : null;
          const label = (d?.label ?? destId ?? '').toString();
          const nodeType = (d?.type ?? '').toString();
          const sources = [];
          if (destId && Array.isArray(linksData)) {
            for (const l of linksData) {
              const srcId = (typeof l.source === 'object') ? l.source?.id : l.source;
              const tgtId = (typeof l.target === 'object') ? l.target?.id : l.target;
              let isEdgeToPerson = false;
              if (srcId === personIdForEdges && tgtId === destId) isEdgeToPerson = true;
              if (tgtId === personIdForEdges && srcId === destId) isEdgeToPerson = true;
              if (isEdgeToPerson) {
                const s = l.type === 'donation'
                  ? 'Donation'
                  : (l.type === 'meeting' ? 'Meeting' : (l.type === 'affiliation' ? 'Affiliation' : (l.type || 'Link')));
                if (!sources.includes(s)) sources.push(s);
              }
            }
          }
          handleClickConnection({ id: destId, label, nodeType, sources });
        } catch {
          // ignore
        }
      });

    const labels = zoomLayer
      .append('g')
      .selectAll('text')
      .data(nodesData)
      .join('text')
      .attr('class', 'graph-label')
      .text((d) => d.label)
      .attr('font-size', 10)
      .attr('dx', 10)
      .attr('dy', 4)
      .attr('fill', '#333');

    // Kick the simulation once and set initial positions for immediate render
    try {
      simulation.tick();
    } catch (e) {
      // ignore if tick not available
    }
    link
      .attr('x1', (d) => (d.source && d.source.x != null ? d.source.x : width / 2))
      .attr('y1', (d) => (d.source && d.source.y != null ? d.source.y : height / 2))
      .attr('x2', (d) => (d.target && d.target.x != null ? d.target.x : width / 2))
      .attr('y2', (d) => (d.target && d.target.y != null ? d.target.y : height / 2));

    node
      .attr('cx', (d) => (d.x != null ? d.x : width / 2))
      .attr('cy', (d) => (d.y != null ? d.y : height / 2));

    labels
      .attr('x', (d) => (d.x != null ? d.x : width / 2))
      .attr('y', (d) => (d.y != null ? d.y : height / 2));

    // If only the person node exists, add a subtle highlight ring (avoid duplicate label/circle)
    if (nodesData.length === 1) {
      const cx = nodesData[0].x != null ? nodesData[0].x : width / 2;
      const cy = nodesData[0].y != null ? nodesData[0].y : height / 2;

      zoomLayer
        .append('circle')
        .attr('cx', cx)
        .attr('cy', cy)
        .attr('r', 14)
        .attr('fill', 'none')
        .attr('stroke', '#93c5fd')
        .attr('stroke-width', 2)
        .attr('opacity', 0.8);
    }

    // Zoom and pan (store zoom instance so UI buttons can control it)
    const zoom = d3
      .zoom()
      .scaleExtent([0.5, 3])
      .on('zoom', (event) => {
        zoomLayer.attr('transform', event.transform);
      });
    zoomRef.current = zoom;
    svg.call(zoom);

    simulation.on('tick', () => {
      link
        .attr('x1', (d) => d.source.x)
        .attr('y1', (d) => d.source.y)
        .attr('x2', (d) => d.target.x)
        .attr('y2', (d) => d.target.y);

      node.attr('cx', (d) => d.x).attr('cy', (d) => d.y);

      labels.attr('x', (d) => d.x).attr('y', (d) => d.y);
    });

    return () => {
      simulation.stop();
    };
  }, [graphData]);

  // Update only the link stroke widths when the thickness slider changes
  // to avoid tearing down and rebuilding the whole graph (which could
  // briefly drop nodes/links during re-init on some browsers).
  useEffect(() => {
    try {
      const svg = d3.select(svgRef.current);
      svg
        .selectAll('line.graph-link')
        .attr('stroke-width', (d) => (1 + (((d && d.value) || 1) * 0.5)) * edgeScale);
    } catch {
      // ignore if svg not ready yet
    }
  }, [edgeScale]);

  // Show/hide edges and nodes based on "Connections shown" without re-initializing the graph
  useEffect(() => {
    try {
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const personIdLocal = (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${(profileLabel || inputLabel || 'Selected Person')}`);

      const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

      const allLinks = graphData?.links || [];
      const edgesToPerson = allLinks
        .filter((l) => {
          const s = idOf(l.source);
          const t = idOf(l.target);
          return s === personIdLocal || t === personIdLocal;
        })
        .map((l) => ({ ...l }));

      edgesToPerson.sort((a, b) => (b.value || 1) - (a.value || 1));
      const capBase = edgesToPerson.length;
      const cap = connectionCap > 0 ? Math.min(connectionCap, capBase) : capBase;

      const selectedDirect = edgesToPerson.slice(0, cap);
      const usedIds = new Set([personIdLocal]);
      for (const l of selectedDirect) {
        usedIds.add(idOf(l.source));
        usedIds.add(idOf(l.target));
      }

      const svg = d3.select(svgRef.current);
      svg.selectAll('line.graph-link')
        .classed('graph-link--hidden', function(d) {
          const s = idOf(d.source);
          const t = idOf(d.target);
          return !(usedIds.has(s) && usedIds.has(t));
        });

      svg.selectAll('circle.graph-node')
        .classed('graph-node--hidden', (d) => !(usedIds.has(d.id) || d.type === 'person'));

      svg.selectAll('text.graph-label')
        .classed('graph-label--hidden', (d) => !(usedIds.has(d.id) || d.type === 'person'));
    } catch {
      // ignore if not ready
    }
  }, [connectionCap, graphData, activeFirstName, activeLastName, profileData, selectedPeopleId]);

  return (
    <div className="donations-page" ref={containerRef}>
      {/* Header Section */}
      <div className="donations-header">
        <div className="header-content">
          <div className="header-left">
            <div className="page-icon">👤</div>
            <div className="header-text">
              <h1 className="page-title">Person Profile</h1>
              <p className="page-subtitle">Search a person and explore connections across parties, donors, and other people</p>
            </div>
          </div>
          <button onClick={handleBackToHome} className="back-button">
            ← Back to Home
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="donations-container">
        {/* Search Section (left column) */}
        <div className="search-section">
          <div className="search-card">
            <div className="card-header">
              <h2 className="card-title">
                <span className="card-icon">🔍</span>
                Search Filters
              </h2>
              <button type="button" className="reset-button" onClick={handleReset}>
                <span>↺</span>
                Reset
              </button>
            </div>

            {/* Inputs */}
            <div className="inputs-grid">
              <div className="field">
                <span className="icon" aria-hidden>👤</span>
                <input
                  type="text"
                  name="firstName"
                  placeholder="First Name"
                  value={searchQuery.firstName}
                  onChange={handleSearchChange}
                  className="input"
                />
              </div>

              <div className="field">
                <span className="icon" aria-hidden>👤</span>
                <input
                  type="text"
                  name="lastName"
                  placeholder="Last Name"
                  value={searchQuery.lastName}
                  onChange={handleSearchChange}
                  className="input"
                />
              </div>

              <div className="field">
                <span className="icon" aria-hidden>🏢</span>
                <input
                  type="text"
                  name="orgName"
                  placeholder="Organisation (Donor)"
                  value={searchQuery.orgName}
                  onChange={handleSearchChange}
                  className="input"
                />
              </div>
            </div>

            {/* Search Button */}
            <button type="button" className="search-button" onClick={handleSearchSubmit} disabled={isLoading || isOrgLoading}>
              {(isLoading || isOrgLoading) ? (
                <>
                  <span className="loading-spinner">⏳</span>
                  Searching...
                </>
              ) : (
                <>
                  <span>🔍</span>
                  Search
                </>
              )}
            </button>
          </div>
        </div>

        {/* Results Section (right column) */}
        <div className="results-section">
          <div className="results-header">
            <h2 className="results-title">
              <span className="results-icon">📊</span>
              Connections and Data
            </h2>
          </div>

          {error && (
            <div className="error-message">
              <span className="error-icon">⚠️</span>
              {error}
            </div>
          )}

          {isLoading && (
            <div className="loading-message">
              <span className="loading-spinner">⏳</span>
              Loading results...
            </div>
          )}

          {!isLoading && hasSearched && !error && (
            <div className="results-content">
              {/* People Matches (click to open Person form) */}
              {Array.isArray(peopleMatches) && peopleMatches.length > 1 && (
                <div className="table-container" style={{ marginBottom: '1rem' }}>
                  <h3 style={{ marginTop: 0, color: '#1f2937' }}>
                    Matching People ({peopleMatches.length})
                  </h3>
                  <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: '0.5rem' }}>
                    {peopleMatches.map((p) => (
                      <li key={p.id}>
                        <button
                          type="button"
                          className="search-button"
                          onClick={() => handleClickPersonMatch(p)}
                          title="Open person profile"
                        >
                          {(p.first_name || '') + ' ' + (p.last_name || '')}
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Organisation Results */}
              {(searchQuery.orgName || orgResults.length > 0 || orgError) && (
                <div className="table-container" style={{ marginBottom: '1rem' }}>
                  <h3 style={{ marginTop: 0, color: '#1f2937' }}>
                    Organisation Results {isOrgLoading ? ' (loading...)' : ''}
                  </h3>
                  {orgError && (
                    <div className="error-message">
                      <span className="error-icon">⚠️</span>
                      {orgError}
                    </div>
                  )}
                  {!orgError && Array.isArray(orgResults) && orgResults.length > 0 && (
                    <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: '0.5rem' }}>
                      {orgResults.map((d) => {
                        const label = (d.org_name && d.org_name.trim() !== '')
                          ? d.org_name
                          : [d.first_name || '', d.last_name || ''].filter(Boolean).join(' ').trim() || `Donor ${d.id}`;
                        const peers = activeOrgPeers?.[String(d.id)] || null;
                        return (
                          <li key={d.id} style={{ border: '1px solid #e5e7eb', borderRadius: 6, padding: '0.5rem' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                              <span style={{ fontWeight: 600 }}>{label}</span>
                              <button
                                type="button"
                                className="reset-button"
                                onClick={() => loadOrgPeers(d.id)}
                                title="Show linked people (recipients of this donor)"
                              >
                                View linked people
                              </button>
                            </div>
                            {Array.isArray(peers) && peers.length > 0 && (
                              <div style={{ marginTop: '0.5rem' }}>
                                <div style={{ fontSize: '0.9rem', color: '#374151', marginBottom: '0.25rem' }}>
                                  Linked People ({peers.length})
                                </div>
                                <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                                  {peers.map((pr) => (
                                    <button
                                      key={pr.people_id}
                                      type="button"
                                      className="search-button"
                                      onClick={() => handleClickPersonMatch({ id: pr.people_id, first_name: pr.first_name, last_name: pr.last_name })}
                                      title="Open person profile"
                                    >
                                      {(pr.first_name || '') + ' ' + (pr.last_name || '')}
                                    </button>
                                  ))}
                                </div>
                              </div>
                            )}
                            {Array.isArray(peers) && peers.length === 0 && (
                              <div style={{ marginTop: '0.25rem', fontSize: '0.9rem', color: '#6b7280' }}>
                                No linked people found.
                              </div>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  )}
                  {!orgError && !isOrgLoading && Array.isArray(orgResults) && orgResults.length === 0 && searchQuery.orgName && (
                    <div style={{ fontSize: '0.9rem', color: '#6b7280' }}>
                      No organisations found for "{searchQuery.orgName}".
                    </div>
                  )}
                </div>
              )}

              {/* Graph */}
              <div style={{ marginBottom: '1rem' }}>
                {(profileData?.first_name || activeFirstName || activeLastName) && (
                  <h3 style={{ margin: 0, marginBottom: '0.5rem', color: '#1f2937' }}>
                    {(profileData?.first_name || activeFirstName) + ' ' + (profileData?.last_name || activeLastName)}
                  </h3>
                )}
                <div className="graph-wrap">
                  <svg ref={svgRef} className="person-graph" />
                  <div className="graph-zoom" aria-label="Graph zoom controls">
                    <button
                      type="button"
                      aria-label="Zoom in"
                      onClick={() => handleZoom('in')}
                      className="graph-zoom__btn"
                      title="Zoom in"
                    >
                      +
                    </button>
                    <button
                      type="button"
                      aria-label="Zoom out"
                      onClick={() => handleZoom('out')}
                      className="graph-zoom__btn"
                      title="Zoom out"
                    >
                      -
                    </button>
                  </div>
                </div>

                {/* Graph controls */}
                <div className="graph-controls" style={{ display: 'grid', gap: '0.5rem', marginTop: '0.5rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                    <label style={{ minWidth: 160 }}>Edge thickness</label>
                    <input
                      type="range"
                      min="0.5"
                      max="3"
                      step="0.1"
                      value={edgeScale}
                      onChange={(e) => setEdgeScale(parseFloat(e.target.value))}
                    />
                    <span style={{ fontVariantNumeric: 'tabular-nums' }}>{edgeScale.toFixed(1)}x</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                    <label style={{ minWidth: 160 }}>Connections shown</label>
                    <input
                      type="range"
                      min="1"
                      max={Math.max(1, personDegree || graphData.links.length)}
                      step="1"
                      value={Math.min(connectionCap > 0 ? connectionCap : (personDegree || graphData.links.length), (personDegree || graphData.links.length))}
                      onChange={(e) => setConnectionCap(parseInt(e.target.value, 10) || 0)}
                    />
                    <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                      {Math.min(connectionCap > 0 ? connectionCap : (personDegree || graphData.links.length), (personDegree || graphData.links.length))}/{personDegree || graphData.links.length}
                    </span>
                  </div>
                </div>

                <p className="results-note">Colors: Person (blue), Party (purple), Donor (green), Attendee (teal). Drag nodes to explore. Zoom/pan with mouse.</p>
              </div>

              {(Array.isArray(sortedDonations) && sortedDonations.length > 0) ||
              (Array.isArray(sortedMeetings) && sortedMeetings.length > 0) ? (
                <div className="section-toggles" style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem', flexWrap: 'wrap' }}>
                  {Array.isArray(sortedDonations) && sortedDonations.length > 0 && (
                    <button
                      type="button"
                      className="search-button"
                      onClick={() => setShowDonations((v) => !v)}
                      title={showDonations ? 'Hide Donations' : 'Show Donations'}
                    >
                      {showDonations ? '▼ Hide Donations' : '▶ Show Donations'}
                    </button>
                  )}
                  {Array.isArray(sortedMeetings) && sortedMeetings.length > 0 && (
                    <button
                      type="button"
                      className="search-button"
                      onClick={() => setShowMeetings((v) => !v)}
                      title={showMeetings ? 'Hide Meetings' : 'Show Meetings'}
                    >
                      {showMeetings ? '▼ Hide Meetings' : '▶ Show Meetings'}
                    </button>
                  )}
                </div>
              ) : null}

              {/* Donations Table */}
              {showDonations && Array.isArray(sortedDonations) && sortedDonations.length > 0 && (
                <>
                  <h3 style={{ marginTop: '1rem', color: '#1f2937' }}>Donation History (All Years)</h3>
                  <div className="table-container">
                    <table className="min-w-full border">
                      <thead>
                        <tr>
                          <th onClick={() => sortTable('date')}>Date</th>
                          <th onClick={() => sortTable('amount')}>Amount</th>
                          <th>Donor First</th>
                          <th>Donor Last</th>
                          <th>Donor Org</th>
                        </tr>
                      </thead>
                      <tbody>
                        {sortedDonations.map((donation, idx) => (
                          <tr key={idx}>
                            <td>{donation.date ? new Date(donation.date).toLocaleDateString() : (donation.year || '')}</td>
                            <td>{typeof donation.amount === 'number' ? `$${donation.amount.toLocaleString()}` : `$${donation.amount}`}</td>
                            <td>{donation.donor_first_name || ''}</td>
                            <td>{donation.donor_last_name || ''}</td>
                            <td>{donation.donor_org_name || ''}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}

              {/* Meetings Table */}
              {showMeetings && Array.isArray(sortedMeetings) && sortedMeetings.length > 0 && (
                <>
                  <h3 style={{ marginTop: '1rem', color: '#1f2937' }}>Meetings</h3>
                  <div className="table-container">
                    <MeetingsTable meetings={sortedMeetings} />
                  </div>
                </>
              )}

              {/* Connections (summary of direct links from the selected person) */}
              <div>
                <h3 style={{ marginTop: '1rem', color: '#1f2937' }}>
                  Connections {Array.isArray(connections) ? `(${connections.length})` : ''}
                </h3>
                {Array.isArray(connections) && connections.length > 0 ? (
                  <>
                  <div className="table-container">
                    <table className="min-w-full border">
                      <thead>
                        <tr>
                          <th>Name</th>
                          <th>Type</th>
                          <th>Source</th>
                        </tr>
                      </thead>
                      <tbody>
                        {connections.map((c) => (
                          <tr key={c.id} onClick={() => handleClickConnection(c)} style={{ cursor: 'pointer' }} title="Click to view details">
                            <td>{c.label}</td>
                            <td style={{ textTransform: 'capitalize' }}>{c.nodeType || 'unknown'}</td>
                            <td>
                              {c.sources.map((s) => {
                                const cls =
                                  s === 'Donation' ? 'conn-badge conn-badge--donation' :
                                  s === 'Meeting' ? 'conn-badge conn-badge--meeting' :
                                  s === 'Affiliation' ? 'conn-badge conn-badge--affiliation' :
                                  'conn-badge';
                                return <span key={s} className={cls}>{s}</span>;
                              })}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {selectedConnection && Array.isArray(connectionDetails) && connectionDetails.length > 0 && (
                    <div className="table-container" style={{ marginTop: '0.75rem' }}>
                      <h4 style={{ margin: 0, color: '#111827' }}>
                        Details for: {selectedConnection.label} — {selectedConnection.sources && selectedConnection.sources.join(', ')}
                      </h4>
                      <ul style={{ listStyle: 'none', padding: 0, marginTop: '0.5rem', display: 'grid', gap: '0.5rem' }}>
                        {connectionDetails.map((item, idx) => (
                          <li key={idx} style={{ border: '1px solid #e5e7eb', borderRadius: 6, padding: '0.5rem' }}>
                            {item.kind === 'meeting' ? (
                              <div>
                                <div><b>Date:</b> {item.data.date ? new Date(item.data.date).toLocaleDateString() : 'N/A'}</div>
                                <div><b>Title:</b> {item.data.title || 'N/A'}</div>
                                <div><b>Type:</b> {item.data.type || 'N/A'}</div>
                                <div><b>Portfolio:</b> {item.data.portfolio || 'N/A'}</div>
                                <div><b>Location:</b> {item.data.location || 'N/A'}</div>
                                <div><b>Attendees:</b> {item.data.with_text ? String(item.data.with_text) : 'N/A'}</div>
                                <div><b>Notes:</b> {item.data.notes || 'N/A'}</div>
                              </div>
                            ) : item.kind === 'donation' ? (
                              <div>
                                <div><b>Date:</b> {item.data.date ? new Date(item.data.date).toLocaleDateString() : (item.data.year || 'N/A')}</div>
                                <div><b>Amount:</b> {typeof item.data.amount === 'number' ? `$${item.data.amount.toLocaleString()}` : `$${item.data.amount}`}</div>
                                <div><b>Donor:</b> {[item.data.donor_first_name || '', item.data.donor_last_name || ''].filter(Boolean).join(' ') || (item.data.donor_org_name || 'N/A')}</div>
                                <div><b>Location:</b> {item.data.location || 'N/A'}</div>
                                <div><b>Notes:</b> {item.data.notes || 'N/A'}</div>
                              </div>
                            ) : (
                              <div>
                                <div><b>Party:</b> {partyGuess || 'N/A'}</div>
                              </div>
                            )}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  </>
                ) : (
                  <p className="results-note">No direct connections were found for this person.</p>
                )}
              </div>

              {/* No results after search */}
              {(!sortedDonations || sortedDonations.length === 0) &&
                (!sortedMeetings || sortedMeetings.length === 0) &&
                !profileData && (
                  <div className="no-results">
                    <span className="no-results-icon">🔍</span>
                    <h3>No data found</h3>
                    {Array.isArray(suggestions) && suggestions.length > 0 ? (
                      <>
                        <p>We couldn't find a direct profile, but found matching candidates in historical election data:</p>
                        <ul className="suggestions-list" style={{ listStyle: 'none', padding: 0 }}>
                          {suggestions.map((s, idx) => (
                            <li key={idx} style={{ marginBottom: '0.5rem' }}>
                              <button
                                type="button"
                                className="search-button"
                                onClick={() => handleUseSuggestion(s)}
                              >
                                {(s.first_name || '') + ' ' + (s.last_name || '')} — {(s.party_name || 'Unknown party')} — {(s.electorate_name || 'Unknown electorate')} — {s.year}
                              </button>
                            </li>
                          ))}
                        </ul>
                      </>
                    ) : (
                      <p>Try adjusting the name.</p>
                    )}
                  </div>
                )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default PersonProfile;
