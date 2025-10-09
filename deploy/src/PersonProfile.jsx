import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import * as d3 from 'd3';
import './PersonProfile.css';
import { API_BASE } from './apiConfig';
import MeetingsTable from './MeetingsTable.jsx';

/**
 * Organisation aliasing (canonicalise multiple names into one group)
 * - Minimal curated map for known variants
 * - Safe normaliser for matching keys (lowercase, strip punctuation/extra spaces)
 */
const normaliseOrgKey = (s) =>
  String(s || '')
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

const ORG_ALIAS_PATTERNS = [
  {
    canonical: 'Conservative Party',
    patterns: [
      'conservative party',
      'the conservative party',
      'conservative party hq',
      'conservative party of new zealand',
      'conservative party of nz',
      'conservative party nz',
      'conservative party (nz)'
    ]
  }
  // Add more organisations here as needed
];

const ORG_ALIAS_MAP = (() => {
  const map = new Map();
  for (const entry of ORG_ALIAS_PATTERNS) {
    const canKey = normaliseOrgKey(entry.canonical);
    map.set(canKey, entry.canonical); // ensure canonical resolves to itself
    for (const p of entry.patterns) {
      map.set(normaliseOrgKey(p), entry.canonical);
    }
  }
  return map;
})();

const getDonationColor = (amount) => {
  const safeAmount = Math.max(0, Number(amount) || 0);
  if (safeAmount >= 10000) return '#FFD700'; // Gold for $10,000+
  if (safeAmount >= 1000) return '#22C55E'; // Green for $1,000-$9,999
  if (safeAmount >= 100) return '#3B82F6'; // Blue for $100-$999
  return '#9CA3AF'; // Gray for $0-$99
};

const getRingRadiusBoost = (amount = 0) => {
  const safeAmount = Math.max(0, Number(amount) || 0);
  if (safeAmount === 0) return 2;
  return Math.min(8, Math.max(2, Math.log10(safeAmount + 1) * 3));
};

const getRingThickness = (amount = 0) => {
  const safeAmount = Math.max(0, Number(amount) || 0);
  if (safeAmount === 0) return 2;
  return Math.min(8, Math.max(2, Math.log10(safeAmount + 1) * 1.8 + 1));
};

const buildRingMeta = (amount) => {
  const safeAmount = Math.max(0, Number(amount) || 0);
  return {
    ringColor: getDonationColor(safeAmount),
    ringThickness: getRingThickness(safeAmount),
    ringRadiusBoost: getRingRadiusBoost(safeAmount)
  };
};

const currencyFormatter = new Intl.NumberFormat('en-NZ', {
  style: 'currency',
  currency: 'NZD',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});

const formatCurrency = (amount) => {
  const numeric = Number(amount);
  if (!Number.isFinite(numeric)) return '—';
  return currencyFormatter.format(numeric);
};

const getEdgeThickness = (amount = 0) => {
  const safeAmount = Math.max(0, Number(amount) || 0);
  if (safeAmount === 0) return 1.5;
  // Logarithmic scaling keeps proportional differences while limiting extremes
  const base = 1.5 + Math.log10(safeAmount + 1) * 2.8;
  return Math.max(1.5, Math.min(14, base));
};

/**
 * Group donor search results by canonical organisation name.
 * Returns items shaped as:
 * {
 *   key: 'org:conservative party',
 *   displayName: 'Conservative Party',
 *   aliasNames: ['Conservative Party', 'Conservative Party HQ', ...],
 *   ids: [123, 456, ...],          // donor ids in this group
 *   donors: [original donor rows], // original rows
 *   peerKey: 'group:123+456'       // key used for caching linked people
 * }
 * Individuals (no org_name) return per-row groups with key 'ind:<id>'
 */
function groupDonorOrgs(rows) {
  const groups = new Map();

  const addToGroup = (key, displayName, row, aliasName) => {
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        displayName,
        aliasNames: new Set(),
        ids: [],
        donors: [],
        peerKey: null
      });
    }
    const g = groups.get(key);
    if (aliasName) g.aliasNames.add(aliasName);
    if (row?.id != null) g.ids.push(row.id);
    g.donors.push(row);
  };

  for (const r of Array.isArray(rows) ? rows : []) {
    const org = (r && r.org_name && String(r.org_name).trim() !== '') ? String(r.org_name).trim() : '';
    if (org) {
      const norm = normaliseOrgKey(org);
      const canonical = ORG_ALIAS_MAP.get(norm) || org;
      const canKey = normaliseOrgKey(canonical);
      addToGroup(`org:${canKey}`, canonical, r, org);
    } else {
      // Treat individual donors as their own groups (do not merge)
      const full = [r?.first_name || '', r?.last_name || ''].filter(Boolean).join(' ').trim() || `Donor ${r?.id}`;
      addToGroup(`ind:${r?.id}`, full, r, full);
    }
  }

  // Finalise alias lists and peer keys
  const result = [];
  for (const g of groups.values()) {
    g.aliasNames = Array.from(g.aliasNames).filter((n) => !!n && n !== g.displayName);
    g.ids = Array.from(new Set(g.ids.map((x) => String(x)))).map((x) => Number(x));
    const sortedIds = [...g.ids].sort((a, b) => a - b);
    g.peerKey = `${g.key}:${sortedIds.join('+')}`;
    result.push(g);
  }
  // Sort groups by display name
  result.sort((a, b) => a.displayName.localeCompare(b.displayName));
  return result;
}

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
  const [showGraphDetails, setShowGraphDetails] = useState(true);
  const [showConnectionsDetails, setShowConnectionsDetails] = useState(true);
  // Graph sliders: edge thickness and number of connections to show (0 = all)
  const [edgeScale, setEdgeScale] = useState(1);
  const [connectionCap, setConnectionCap] = useState(0);
  const [nodeSeparation, setNodeSeparation] = useState(1.5);
  const [minDonationAmount, setMinDonationAmount] = useState(0);

  const [isLoading, setIsLoading] = useState(false);
  const [hasSearched, setHasSearched] = useState(false);
  const [error, setError] = useState(null);
  const isSearching = isLoading || isOrgLoading;

  // Sorting config for tables
  const [sortConfig, setSortConfig] = useState({ key: 'date', direction: 'asc' });

  // View modes and org-centric state
  const [viewMode, setViewMode] = useState('person'); // 'person' | 'org'
  const [activeDonor, setActiveDonor] = useState(null); // selected donor object from /donors/search
  const [orgRecipients, setOrgRecipients] = useState([]); // [{people_id, first_name, last_name}]
  const [orgDonationRows, setOrgDonationRows] = useState([]); // donation rows filtered for activeDonor
  const [orgTotalsByPerson, setOrgTotalsByPerson] = useState({}); // people_id -> total amount
  const [orgDonationsByRecipient, setOrgDonationsByRecipient] = useState({}); // people_id -> donation rows
  const [showOrgProfile, setShowOrgProfile] = useState(false);

  // Graph layer toggles and proximity weighting
  const [showPeopleLayer, setShowPeopleLayer] = useState(true);
  const [showDonorIndividuals, setShowDonorIndividuals] = useState(true);
  const [showDonorOrganisations, setShowDonorOrganisations] = useState(true);
  const [showPartiesLayer, setShowPartiesLayer] = useState(true);
  const [graphTooltip, setGraphTooltip] = useState(null);

  // D3 refs
  const svgRef = useRef(null);
  const containerRef = useRef(null);
  const zoomRef = useRef(null);
  const detailsRef = useRef(null);

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
    setGraphTooltip(null);

    // Org view/state
    setViewMode('person');
    setActiveDonor(null);
    setOrgRecipients([]);
    setOrgDonationRows([]);
    setOrgTotalsByPerson({});
    setOrgDonationsByRecipient({});

    // Graph toggles
    setShowPeopleLayer(true);
    setShowDonorIndividuals(true);
    setShowDonorOrganisations(true);
    setShowPartiesLayer(true);
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

    // Set view mode
    if (hasPerson) {
      setViewMode('person');
      setActiveDonor(null);
      setOrgRecipients([]);
      setOrgDonationRows([]);
      setOrgTotalsByPerson({});
      setOrgDonationsByRecipient({});
    } else if (hasOrg) {
      setViewMode('org');
      setActiveDonor(null);
      setOrgRecipients([]);
      setOrgDonationRows([]);
      setOrgTotalsByPerson({});
      setOrgDonationsByRecipient({});
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
    const pid = p.people_id ?? p.id ?? null;
    setSelectedPeopleId(pid);
    const fn = (p.first_name || '').trim();
    const ln = (p.last_name || '').trim();
    setActiveFirstName(fn);
    setActiveLastName(ln);
    setSearchQuery({ firstName: fn, lastName: ln, orgName: '' });

    // Switch to full Person view and clear org-centric state so the UI behaves like a direct person search
    setViewMode('person');
    setActiveDonor(null);
    setOrgRecipients([]);
    setOrgDonationRows([]);
    setOrgTotalsByPerson({});
    setOrgDonationsByRecipient({});
    setShowOrgProfile(false);

    // Clear any previous selection/details
    setSelectedConnection(null);
    setConnectionDetails([]);

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
        // Group results into canonical organisations with aliases
        const groups = groupDonorOrgs(Array.isArray(rows) ? rows : []);
        setOrgResults(groups);
      }
    } catch (e) {
      setOrgError('Organisation search failed.');
      setOrgResults([]);
    } finally {
      setIsOrgLoading(false);
    }
  };

  // Expand a donor to view connected people; clicking a person opens the Person form
  // donorIdOrIds: number | string | Array<number|string>
  const loadOrgPeers = async (donorIdOrIds) => {
    if (donorIdOrIds == null) return;
    const ids = Array.isArray(donorIdOrIds) ? donorIdOrIds : [donorIdOrIds];
    const normIds = ids.map((x) => String(x));
    const groupKey = `group:${normIds.slice().sort().join('+')}`;

    try {
      const results = await Promise.all(
        normIds.map(async (id) => {
          try {
            const resp = await fetch(`${API_BASE}/donations/by-donor?donor_id=${encodeURIComponent(id)}`);
            if (!resp.ok) return [];
            const rows = await resp.json();
            return Array.isArray(rows) ? rows : [];
          } catch {
            return [];
          }
        })
      );
      // Flatten and de-duplicate recipients by people_id
      const combined = [];
      const seen = new Set();
      for (const arr of results) {
        for (const r of arr) {
          const pid = r?.people_id != null ? String(r.people_id) : null;
          const key = pid ? `${pid}` : JSON.stringify(r);
          if (seen.has(key)) continue;
          seen.add(key);
          combined.push(r);
        }
      }
      setActiveOrgPeers((prev) => ({ ...prev, [groupKey]: combined }));
    } catch {
      setActiveOrgPeers((prev) => ({ ...prev, [groupKey]: [] }));
    }
  };

  // Select a donor to open Organisation View graph and tables
  const handleSelectOrg = async (donorGroup) => {
    if (!donorGroup) return;
    // donorGroup may be a grouped organisation with ids[], or a single donor object with id
    const ids = Array.isArray(donorGroup.ids) && donorGroup.ids.length > 0
      ? donorGroup.ids
      : (donorGroup.id != null ? [donorGroup.id] : []);

    if (ids.length === 0) return;

    try {
      setViewMode('org');
      setActiveDonor({
        org_name: donorGroup.displayName || donorGroup.org_name || null,
        ids,
        id: ids.length === 1 ? ids[0] : null,
        aliasNames: Array.isArray(donorGroup.aliasNames) ? donorGroup.aliasNames : []
      });
      setIsOrgLoading(true);
      setSelectedConnection(null);
      setConnectionDetails([]);

      // 1) Load recipients across all donor ids
      const recipientsMap = new Map();
      for (const id of ids) {
        try {
          const resp = await fetch(`${API_BASE}/donations/by-donor?donor_id=${encodeURIComponent(id)}`);
          if (!resp.ok) continue;
          const rows = await resp.json();
          const arr = Array.isArray(rows) ? rows : [];
          for (const r of arr) {
            const pid = r?.people_id != null ? Number(r.people_id) : null;
            if (!pid) continue;
            if (!recipientsMap.has(pid)) {
              recipientsMap.set(pid, { people_id: pid, first_name: r.first_name, last_name: r.last_name });
            }
          }
        } catch {
          // ignore
        }
      }
      const recipients = Array.from(recipientsMap.values());
      setOrgRecipients(recipients);

      // 2) Fetch donation rows per recipient and filter by any donor id in the group
      const idSet = new Set(ids.map((x) => String(x)));
      const allRows = [];
      const totals = {};
      const byRecipient = {};
      for (const r of recipients) {
        const pid = r?.people_id;
        if (!pid) continue;
        try {
          const perResp = await fetch(`${API_BASE}/donations/by-person?people_id=${encodeURIComponent(pid)}`);
          if (!perResp.ok) continue;
          const perRows = await perResp.json();
          const filtered = (Array.isArray(perRows) ? perRows : []).filter((dr) => dr?.donor_id != null && idSet.has(String(dr.donor_id)));
          for (const fr of filtered) {
            allRows.push(fr);
            const amt = Number(fr.amount) || 0;
            const key = String(pid);
            totals[key] = (totals[key] || 0) + amt;
            if (!byRecipient[key]) byRecipient[key] = [];
            byRecipient[key].push(fr);
          }
        } catch {
          // ignore per person failure
        }
      }
      setOrgDonationRows(allRows);
      setOrgTotalsByPerson(totals);
      setOrgDonationsByRecipient(byRecipient);
    } finally {
      setIsOrgLoading(false);
    }
  };

  // Auto-select organisation when only one match (graph should show immediately for org searches)
  const showEdgeTooltip = useCallback((event, payload) => {
    if (!payload) {
      setGraphTooltip(null);
      return;
    }

    const svgEl = svgRef.current;
    if (!svgEl) return;

    const rect = svgEl.getBoundingClientRect();
    const mouseX = event.clientX - rect.left;
    const mouseY = event.clientY - rect.top;

    // Tooltip dimensions
    const tooltipWidth = 250;
    const tooltipHeight = 140;
    const padding = 20;
    const offset = 30; // Distance from click point

    let x, y;

    // Simple left/right positioning based on which side of the graph the click occurred
    const graphCenterX = rect.width / 2;
    
    if (mouseX < graphCenterX) {
      // Node is on left side - show tooltip on the left
      x = padding;
      y = Math.max(padding, Math.min(rect.height - tooltipHeight - padding, mouseY - tooltipHeight / 2));
    } else {
      // Node is on right side - show tooltip on the right
      x = rect.width - tooltipWidth - padding;
      y = Math.max(padding, Math.min(rect.height - tooltipHeight - padding, mouseY - tooltipHeight / 2));
    }

    const amountValue =
      payload.amount != null && Number.isFinite(Number(payload.amount))
        ? Number(payload.amount)
        : null;

    setGraphTooltip({
      x,
      y,
      label: payload.label || '',
      nodeType: payload.nodeType || '',
      sources: payload.sources || [],
      amount: amountValue,
      amountLabel: amountValue != null ? formatCurrency(amountValue) : null,
      isNodeClick: payload.isNodeClick || false,
      isEdgeClick: payload.isEdgeClick || false,
      nodeId: payload.nodeId || null // Add nodeId to track which node was clicked
    });
  }, []);

  useEffect(() => {
    if (viewMode === 'org' && !activeDonor && Array.isArray(orgResults) && orgResults.length === 1) {
      handleSelectOrg(orgResults[0]);
    }
  }, [orgResults, viewMode, activeDonor]);

  // Click a connection to show underlying entries (meetings/donations/affiliation)
  const handleClickConnection = (conn, fromTable = false) => {
    try {
      // Toggle off if the same item is clicked again (from graph or table)
      if (conn && selectedConnection && selectedConnection.id === conn.id) {
        setSelectedConnection(null);
        setConnectionDetails([]);
        setGraphTooltip(null);
        return;
      }

      setSelectedConnection(conn || null);
      // Clear previous details to ensure immediate UI refresh on new selection
      setConnectionDetails([]);
      if (!conn) {
        setGraphTooltip(null);
        setConnectionDetails([]);
        return;
      }

      // If clicked from table, scroll to graph and show tooltip
      if (fromTable) {
        // Scroll to graph
        const graphElement = svgRef.current;
        if (graphElement) {
          graphElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Find the node data to get donation amount
        const nodeData = graphData.nodes?.find(n => n.id === conn.id);
        const nodeAmount = nodeData?.totalAmount;

        // Show tooltip at center of graph (approximate position)
        setTimeout(() => {
          if (svgRef.current) {
            const rect = svgRef.current.getBoundingClientRect();
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            setGraphTooltip({
              x: centerX,
              y: centerY,
              label: conn.label || '',
              nodeType: conn.nodeType || '',
              sources: conn.sources || [],
              amount: nodeAmount,
              amountLabel: nodeAmount != null ? formatCurrency(nodeAmount) : null,
              isNodeClick: true,
              fromTable: true
            });
          }
        }, 500); // Delay to allow scroll to complete
      }
      let details = [];
      const nodeType = (conn.nodeType || '').toLowerCase();
      if (nodeType === 'attendee') {
        // Robust text matching for attendee names across diaries data
        const normalize = (s) =>
          String(s || '')
            .toLowerCase()
            .replace(/&/g, ' and ')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        // naive singularize to help "representatives" ~ "representative"
        const singularize = (s) =>
          s.replace(/\b(\w{4,})s\b/g, '$1');

        const normSing = (s) => singularize(normalize(s));

        // Prefer the canonical name embedded in the node id (attendee:NAME)
        const rawFromId =
          typeof conn.id === 'string' && conn.id.startsWith('attendee:')
            ? conn.id.slice('attendee:'.length)
            : conn.label;

        const target = normSing(rawFromId);
        const matched = (meetings || []).filter((m) => {
          const raw = m.attendees_names || m.with_text || '';
          // try structured attendees list if available
          if (Array.isArray(m.attendees_names) && m.attendees_names.length > 0) {
            const names = m.attendees_names.map((x) => normSing(x));
            if (names.some((n) => n === target || n.includes(target) || target.includes(n))) return true;
          }
          // fallback to free-text match
          const hay = Array.isArray(raw) ? raw.join('; ') : String(raw);
          const nHay = normSing(hay);
          if (target && nHay.includes(target)) return true;

          // Also try tokenized compare against attendees list text - compare tokens to target (not hay)
          const tokens = String(hay).split(/;|,|&| and |\/|\+/gi).map((t) => normSing(t));
          return target && tokens.some((t) => t && (t === target || t.includes(target) || target.includes(t)));
        });
        details = matched.map((m) => ({ kind: 'meeting', data: m }));
      } else if (nodeType === 'donor') {
        const donorId = (String(conn.id || '').startsWith('donor:') ? String(conn.id).split(':')[1] : null);
        const matched = (donations || []).filter((d) => donorId && String(d.donor_id) === String(donorId));
        details = matched.map((d) => ({ kind: 'donation', data: d }));
      } else if (nodeType === 'party') {
        details = [{ kind: 'party', data: { party: partyGuess, party_id: partyId } }];
      } else if (nodeType === 'person') {
        if (viewMode === 'org') {
          // In org view, show donations from this organisation to the selected recipient
          const pid = (() => {
            if (typeof conn.id === 'string' && conn.id.startsWith('person:')) {
              const raw = conn.id.split(':')[1];
              const n = Number(raw);
              return Number.isFinite(n) ? n : null;
            }
            // Fallback: try to match by label (exact match)
            const m = (orgRecipients || []).find((r) => {
              const name = [r.first_name || '', r.last_name || ''].filter(Boolean).join(' ').trim();
              return name === conn.label;
            });
            return m ? Number(m.people_id) : null;
          })();

          const rows = (pid != null && orgDonationsByRecipient) ? (orgDonationsByRecipient[String(pid)] || []) : [];
          details = rows.map((d) => ({ kind: 'donation', data: d }));
        } else {
          // In person view, clicking a peer person has no direct detail rows
          details = [];
        }
      }
      setConnectionDetails(details);
    } catch {
      setConnectionDetails([]);
    }
  };

  // In org view, default Organisation Profile to collapsed after each org selection
  useEffect(() => {
    if (viewMode === 'org' && activeDonor) {
      setShowOrgProfile(false);
    } else if (viewMode !== 'org') {
      setShowOrgProfile(false);
    }
  }, [viewMode, activeDonor]);

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

    // Organisation-centric graph (when only org is searched and a donor is selected)
    if (viewMode === 'org' && activeDonor && (activeDonor.id != null || (Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0))) {
      const ids = Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0 ? activeDonor.ids : [activeDonor.id];
      const donorNodeId = `donor:${ids.slice().sort((a, b) => a - b).join('+')}`;
      const donorLabel =
        (activeDonor.org_name && String(activeDonor.org_name).trim() !== '')
          ? activeDonor.org_name
          : [activeDonor.first_name || '', activeDonor.last_name || ''].filter(Boolean).join(' ').trim() || `Donor ${ids[0]}`;

      let donorNode = nodes.find((n) => n.id === donorNodeId);
      if (!donorNode) {
        donorNode = {
          id: donorNodeId,
          label: donorLabel,
          type: 'donor',
          isOrg: Boolean(activeDonor.org_name && String(activeDonor.org_name).trim() !== '')
        };
        nodes.push(donorNode);
      } else {
        donorNode.label = donorLabel;
        donorNode.type = 'donor';
        donorNode.isOrg = Boolean(activeDonor.org_name && String(activeDonor.org_name).trim() !== '');
      }

      // Build recipients from orgRecipients and totals
      const totalsMap = orgTotalsByPerson || {};
      const recs = Array.isArray(orgRecipients) ? orgRecipients : [];
      const totalOrgAmount = Object.values(totalsMap).reduce((sum, val) => sum + (Number(val) || 0), 0);
      const donorDonationCount = Array.isArray(orgDonationRows) ? orgDonationRows.length : 0;
      donorNode.totalAmount = totalOrgAmount;
      donorNode.donationCount = donorDonationCount;
      donorNode.ringMeta = buildRingMeta(totalOrgAmount);

      for (const r of recs) {
        const pid = r?.people_id;
        if (!pid) continue;
        const label = [r.first_name || '', r.last_name || ''].filter(Boolean).join(' ').trim() || `Person ${pid}`;
        const personNodeId = `person:${pid}`;

        let personNode = nodes.find((n) => n.id === personNodeId);
        if (!personNode) {
          personNode = { id: personNodeId, label, type: 'person', donationCount: 0 };
          nodes.push(personNode);
        } else if (label) {
          personNode.label = label;
        }

        const total = Number(totalsMap[String(pid)] || 0);
        const recipientDonationRows = Array.isArray(orgDonationsByRecipient?.[String(pid)])
          ? orgDonationsByRecipient[String(pid)]
          : [];
        personNode.totalAmount = total;
        personNode.donationCount = recipientDonationRows.length;
        personNode.ringMeta = buildRingMeta(total);

        const value = Math.max(1, Math.log10(Math.abs(total) + 1));
        links.push({
          source: donorNodeId,
          target: personNodeId,
          type: 'donation',
          value
        });
      }

      return { nodes, links };
    }

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
      type: 'person',
      donationCount: donations.length
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
      const peers = Array.isArray(donorPeers[did]) ? donorPeers[did] : [];

      let donorNode = nodes.find((n) => n.id === donorNodeId);
      if (!donorNode) {
        donorNode = { id: donorNodeId, label, type: 'donor', isOrg: Boolean(org && org.trim() !== '') };
        nodes.push(donorNode);
      } else {
        donorNode.label = label;
        donorNode.type = 'donor';
        donorNode.isOrg = Boolean(org && org.trim() !== '');
      }
      donorNode.totalAmount = agg.amount;
      donorNode.donationCount = agg.rows.length + peers.length;
      donorNode.ringMeta = buildRingMeta(agg.amount);

      if (personId) {
        const donationStrength = Math.max(1, Math.log10(Math.abs(agg.amount) + 1));
        const baseThickness = getEdgeThickness(agg.amount);
        links.push({
          source: personId,
          target: donorNodeId,
          type: 'donation',
          value: donationStrength,
          amount: agg.amount,
          baseThickness,
          label
        });
      }

      // Add other people this donor has funded
      for (const pr of peers) {
        const peerId = Number(pr.people_id);
        if (!Number.isFinite(peerId)) continue;
        const peerLabel = [pr.first_name || '', pr.last_name || ''].filter(Boolean).join(' ') || `Person ${peerId}`;
        const peerNodeId = `person:${peerId}`;

        let personNode = nodes.find((n) => n.id === peerNodeId);
        if (!personNode) {
          personNode = { id: peerNodeId, label: peerLabel, type: 'person', donationCount: 0 };
          nodes.push(personNode);
        } else if (peerLabel) {
          personNode.label = peerLabel;
        }
        personNode.donationCount = (personNode.donationCount || 0) + 1;

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

    // Aggregate meeting frequencies per attendee and weight edges by frequency (log-scale)
    const meetCountById = new Map();

    for (const m of meetings || []) {
      const raw = m.attendees_names || m.with_text || '';
      if (!raw) continue;
      const parts = String(raw).split(/;|,|&| and |\/|\+/gi);
      for (let p of parts) {
        const name = (p || '').trim();
        if (!name) continue;
        // skip generic placeholders
        if (/^(attendees|officials|multiple ministers|ministers|delegation|representatives|committee|board|council|group|members|staff)$/i.test(name)) {
          continue;
        }
        const id = `attendee:${name}`;
        meetCountById.set(id, (meetCountById.get(id) || 0) + 1);
      }
    }
    // Emit attendee nodes and links to center, weighted by meeting frequency (log-scale)
    for (const [id, count] of meetCountById.entries()) {
      const label = id.slice('attendee:'.length);
      ensureNode(id, label, 'attendee');
      if (personId) {
        const value = Math.max(1, Math.log10(count + 1));
        links.push({ source: personId, target: id, type: 'meeting', value });
      }
    }

    return { nodes, links };
  }, [donations, donorPeers, meetings, profileData, activeFirstName, activeLastName, partyGuess, partyId, selectedPeopleId, viewMode, activeDonor, orgRecipients, orgTotalsByPerson]);

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

  const totalLinkCount = graphData?.links?.length || 0;

  const maxConnections = useMemo(
    () => Math.max(1, personDegree || totalLinkCount),
    [personDegree, totalLinkCount]
  );

  const effectiveConnectionValue = useMemo(() => {
    if (connectionCap > 0) {
      return Math.min(connectionCap, maxConnections);
    }
    return maxConnections;
  }, [connectionCap, maxConnections]);

  const connectionProgress = useMemo(() => {
    if (maxConnections <= 1) return 100;
    const ratio = (effectiveConnectionValue - 1) / (maxConnections - 1);
    return Math.min(100, Math.max(0, ratio * 100));
  }, [effectiveConnectionValue, maxConnections]);

  const edgeProgress = useMemo(() => {
    const ratio = (edgeScale - 0.5) / (3 - 0.5);
    return Math.min(100, Math.max(0, ratio * 100));
  }, [edgeScale]);

  const connections = useMemo(() => {
    // Determine current graph center id (person or donor)
    const centerIdLocal = (() => {
      if (viewMode === 'org' && activeDonor && (activeDonor.id != null || (Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0))) {
        const ids = Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0 ? activeDonor.ids : [activeDonor.id];
        return `donor:${ids.slice().sort((a, b) => a - b).join('+')}`;
      }
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const personLabel = profileLabel || inputLabel || 'Selected Person';
      return (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${personLabel}`);
    })();

    const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

    // Build a map of nodes for label/type lookup
    const nodes = new Map();
    try {
      (graphData.nodes || []).forEach(n => nodes.set(n.id, n));
    } catch {
      // noop
    }

    const list = [];
    const seen = new Map(); // id -> {label, nodeType, sources:Set}

    try {
      for (const l of (graphData.links || [])) {
        const s = idOf(l.source);
        const t = idOf(l.target);
        let otherId = null;
        if (s === centerIdLocal) otherId = t;
        else if (t === centerIdLocal) otherId = s;
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
  }, [graphData, viewMode, activeDonor, profileData, activeFirstName, activeLastName, selectedPeopleId]);

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

    // Color coding for donation amounts
    const getDonationColor = (amount) => {
      if (amount >= 10000) return '#FFD700'; // Gold for $10,000+
      if (amount >= 1000) return '#22C55E';  // Green for $1,000-$9,999
      if (amount >= 100) return '#3B82F6';   // Blue for $100-$999
      return '#9CA3AF'; // Gray for $0-$99
    };

    // Edge weighting (always on):
    // - Donations: weighted by total amount. Higher amount => stronger link (keeps nodes closer).
    // - Meetings: weighted by meeting frequency. More meetings => stronger link.
    const linkStrength = (l) => {
      if (l.type === 'donation') {
        const amount = Math.max(0, l.amount || 0);
        // Higher amounts get much stronger link strength to maintain distance hierarchy
        if (amount >= 10000) {
          return 2.0; // Gold rings - very strong link
        } else if (amount >= 1000) {
          return 1.5; // Green rings - strong link
        } else if (amount >= 100) {
          return 1.0; // Blue rings - medium link
        } else {
          return 0.5; // Gray rings - weaker link
        }
      }
      if (l.type === 'meeting') {
        const v = Math.max(0, l.value || 0);
        const w = Math.min(1, v / 2);
        return 0.1 + 0.25 * w;
      }
      return 0.1; // affiliation/others
    };
    const linkDistance = (l) => {
      if (l.type === 'donation') {
        const amount = Math.max(0, l.amount || 0);
        const scaled = (value) => value * nodeSeparation;

        // Different distances based on donation amount - push green and blue donors further out
        let distance;
        if (amount >= 10000) {
          distance = scaled(80); // Gold rings - close to center
        } else if (amount >= 1000) {
          distance = scaled(600); // Green rings - significantly further from the center
        } else if (amount >= 100) {
          distance = scaled(800); // Blue rings - furthest non-gold donors
        } else {
          distance = scaled(120); // Gray rings - baseline distance
        }

        return Math.max(50, distance);
      }
      if (l.type === 'meeting') {
        const v = Math.max(0, l.value || 0);
        const w = Math.min(1, v / 2);
        const baseDistance = 220 * nodeSeparation;
        return Math.max(50, baseDistance - 140 * w);
      }
      const baseDistance = 200 * nodeSeparation;
      return baseDistance;
    };

    // Clone data to avoid mutating memoized objects and seed initial positions
    // Determine the selected graph center id (person or donor) for top-K filtering
    const centerIdLocal = (() => {
      if (viewMode === 'org' && activeDonor && (activeDonor.id != null || (Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0))) {
        const ids = Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0 ? activeDonor.ids : [activeDonor.id];
        return `donor:${ids.slice().sort((a, b) => a - b).join('+')}`;
      }
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const lbl = profileLabel || inputLabel || 'Selected Person';
      return (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${lbl}`);
    })();

    const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

    // Build list of links directly connected to the selected center node
    const edgesToCenter = (graphData.links || [])
      .filter((l) => {
        const s = idOf(l.source);
        const t = idOf(l.target);
        return s === centerIdLocal || t === centerIdLocal;
      })
      .map((l) => ({ ...l }));

    // Sort direct connections by weight and apply cap based on slider (0 => show all)
    edgesToCenter.sort((a, b) => (b.value || 1) - (a.value || 1));
    const capBase = edgesToCenter.length;
    const cap = connectionCap > 0 ? Math.min(connectionCap, capBase) : capBase;

    // Pick top-k direct connections, then include any secondary links among the selected nodes
    const selectedDirect = edgesToCenter.slice(0, cap);
    const usedIds = new Set([centerIdLocal]);
    for (const l of selectedDirect) {
      usedIds.add(idOf(l.source));
      usedIds.add(idOf(l.target));
    }
    const linksData = (graphData.links || [])
      .filter((l) => {
        const s = idOf(l.source);
        const t = idOf(l.target);
        return usedIds.has(s) && usedIds.has(t);
      })
      .map((l) => ({ ...l }));

    // Only keep nodes participating in the kept links (always keep the center node)
    const nodesData = (graphData.nodes || [])
      .filter((n) => usedIds.has(n.id))
      .map((d) => ({ ...d }));
    nodesData.forEach((n) => {
      if (n.x == null || Number.isNaN(n.x)) n.x = width / 2;
      if (n.y == null || Number.isNaN(n.y)) n.y = height / 2;
    });

    const donorRadiusByCount = (count) => {
      const safe = Math.max(1, count || 1);
      if (safe === 1) return 14;
      if (safe === 2) return 22;
      return Math.min(40, 14 + Math.log2(safe) * 10);
    };

    const personRadiusByCount = (count, isCenter) => {
      const safe = Math.max(0, count || 0);
      const base = isCenter ? 12 : 9;
      if (safe <= 1) return isCenter ? base + 2 : base + 1;
      const factor = isCenter ? 3.5 : 4.5;
      const boosted = base + 2 + Math.log2(safe) * factor;
      return Math.min(isCenter ? 26 : 22, boosted);
    };

    // Compute current center id for click source-detection
    const centerIdForEdges = centerIdLocal;

    // Debug: ensure we have at least the person node
    try {
      // eslint-disable-next-line no-console
      console.debug('WOI PersonProfile graph', { nodes: nodesData.length, node0: nodesData[0], links: linksData.length });
    } catch {}

    const simulation = d3
      .forceSimulation(nodesData)
      .force('link', d3.forceLink(linksData).id((d) => d.id).distance(linkDistance).strength(linkStrength))
      .force('charge', d3.forceManyBody().strength(-100)) // Reduced repulsion
      .force('center', d3.forceCenter(width / 2, height / 2).strength(0.1)) // Much weaker center force
      .force('collision', d3.forceCollide().radius(30)); // Smaller collision radius

    const link = zoomLayer
      .append('g')
      .attr('stroke', '#999')
      .attr('stroke-opacity', 0.6)
      .selectAll('line')
      .data(linksData)
      .join('line')
      .attr('class', 'graph-link')
      .attr('stroke-width', (d) => (d?.baseThickness ?? (1 + (d.value || 1) * 0.5)) * edgeScale)
      .on('click', (event, d) => {
        const sourceLabel =
          typeof d.source === 'object' && d.source?.label ? d.source.label : d.label || 'Connection';
        showEdgeTooltip(event, {
          label: sourceLabel,
          nodeType: d.type || 'link',
          sources: [d.type === 'meeting' ? 'Meeting' : d.type === 'donation' ? 'Donation' : 'Link'],
          amount: d.amount ?? null
        });
      });

    const node = zoomLayer
      .append('g')
      .attr('stroke', '#fff')
      .attr('stroke-width', 1.5)
      .selectAll('circle')
      .data(nodesData)
      .join('circle')
      .attr('class', 'graph-node')
      .attr('r', (d) => {
        if (d.type === 'donor') {
          const ringBoost = d?.ringMeta ? Math.min(4, d.ringMeta.ringRadiusBoost || 0) : 0;
          return donorRadiusByCount(d.donationCount) + ringBoost;
        }
        if (d.type === 'person') {
          const isCenter = d.id === centerIdForEdges;
          const ringBoost = d?.ringMeta ? Math.min(4, d.ringMeta.ringRadiusBoost || 0) : 0;
          return personRadiusByCount(d.donationCount, isCenter) + ringBoost;
        }
        const baseRadius = d.type === 'party' ? 12 : 9;
        const ringBoost = d?.ringMeta ? Math.min(4, d.ringMeta.ringRadiusBoost || 0) : 0;
        return baseRadius + ringBoost;
      })
      .attr('fill', (d) => (d?.ringMeta ? '#FFFFFF' : color(d.type)))
      .style('fill', (d) => (d?.ringMeta ? '#FFFFFF' : color(d.type)))
      .classed('graph-node--selectable', true)
      .style('opacity', 1) // Ensure immediate visibility
      .attr('stroke', (d) => (d?.ringMeta ? d.ringMeta.ringColor : '#FFFFFF'))
      .style('stroke', (d) => (d?.ringMeta ? d.ringMeta.ringColor : '#FFFFFF'))
      .attr('stroke-width', (d) => (d?.ringMeta ? d.ringMeta.ringThickness : 2))
      .style('stroke-width', (d) => (d?.ringMeta ? d.ringMeta.ringThickness : 2))
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
        event.stopPropagation?.();
        try {
          const destId = typeof d?.id === 'string' ? d.id : null;
          const label = (d?.label ?? destId ?? '').toString();
          const nodeType = (d?.type ?? '').toString();
          const sources = [];
          
          // Check if clicking the same node that currently has a tooltip showing
          if (graphTooltip && graphTooltip.nodeId === destId) {
            setGraphTooltip(null);
            setSelectedConnection(null);
            setConnectionDetails([]);
            return;
          }
          
          // Build sources array based on connected links
          if (destId && Array.isArray(linksData)) {
            for (const l of linksData) {
              const srcId = (typeof l.source === 'object') ? l.source?.id : l.source;
              const tgtId = (typeof l.target === 'object') ? l.target?.id : l.target;
              if (srcId === destId || tgtId === destId) {
                const sourceType = l.type === 'donation' ? 'Donation' : 
                                 l.type === 'meeting' ? 'Meeting' : 
                                 l.type === 'affiliation' ? 'Affiliation' : 'Link';
                if (!sources.includes(sourceType)) {
                  sources.push(sourceType);
                }
              }
            }
          }

          // Create connection object for handleClickConnection
          const connectionObj = {
            id: destId,
            label: label,
            nodeType: nodeType,
            sources: sources
          };

          // Call the existing connection handler to highlight in table and show details
          handleClickConnection(connectionObj);

          // Show tooltip with donation amount for all nodes that have amount data
          const nodeAmount = d.totalAmount != null ? d.totalAmount : null;
          showEdgeTooltip(event, {
            label: label,
            nodeType: nodeType,
            sources: sources,
            amount: nodeAmount,
            amountLabel: nodeAmount != null ? formatCurrency(nodeAmount) : null,
            isNodeClick: true,
            nodeId: destId // Pass the node ID to track which node was clicked
          });
        } catch (error) {
          console.log('Node click error:', error, 'destId:', destId, 'graphTooltip:', graphTooltip, 'selectedConnection:', selectedConnection);
        }
      });

    const labels = zoomLayer
      .append('g')
      .selectAll('text')
      .data(nodesData)
      .join('text')
      .attr('class', 'graph-label')
      .text((d) => d.label)
      .attr('font-size', 15)
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
  }, [graphData, connectionCap]);

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

  // Enhanced click handler for edges to show donation amounts
  const handleEdgeClick = useCallback((event, d) => {
    event.stopPropagation();

    // Determine the label for the tooltip
    const sourceNode = typeof d.source === 'object' ? d.source : null;
    const targetNode = typeof d.target === 'object' ? d.target : null;
    const sourceLabel = sourceNode?.label || 'Connection';
    const targetLabel = targetNode?.label || 'Connection';

    // Create a more descriptive label for the edge
    let edgeLabel = `${sourceLabel} → ${targetLabel}`;
    if (d.type === 'donation') {
      edgeLabel = `Donation from ${sourceLabel} to ${targetLabel}`;
    } else if (d.type === 'meeting') {
      edgeLabel = `Meeting with ${targetLabel}`;
    } else if (d.type === 'affiliation') {
      edgeLabel = `Party affiliation: ${targetLabel}`;
    }

    // Show tooltip with enhanced donation amount display
    showEdgeTooltip(event, {
      label: edgeLabel,
      nodeType: d.type || 'link',
      sources: [d.type === 'meeting' ? 'Meeting' : d.type === 'donation' ? 'Donation' : 'Link'],
      amount: d.amount ?? null,
      isEdgeClick: true
    });
  }, [showEdgeTooltip]);

  // Show/hide edges and nodes based on cap and layer toggles (without re-initializing the graph)
  useEffect(() => {
    try {
      const centerIdLocal = (() => {
        if (viewMode === 'org' && activeDonor && (activeDonor.id != null || (Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0))) {
          const ids = Array.isArray(activeDonor.ids) && activeDonor.ids.length > 0 ? activeDonor.ids : [activeDonor.id];
          return `donor:${ids.slice().sort((a, b) => a - b).join('+')}`;
        }
        const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
        const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
        return (selectedPeopleId != null)
          ? `person:${selectedPeopleId}`
          : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${(profileLabel || inputLabel || 'Selected Person')}`);
      })();

      const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

      // Determine per-type visibility based on toggles
      // Attendees are treated as People; donors are split into individuals vs organisations
      const isTypeVisible = (type) => {
        if (type === 'person' || type === 'attendee') return showPeopleLayer;
        if (type === 'party') return showPartiesLayer;
        return true; // donor visibility handled per-node via isNodeLayerVisibleById
      };

      // Build top-K usedIds around center
      const allLinks = graphData?.links || [];
      const edgesToCenter = allLinks
        .filter((l) => {
          const s = idOf(l.source);
          const t = idOf(l.target);
          return s === centerIdLocal || t === centerIdLocal;
        })
        .map((l) => ({ ...l }));

      edgesToCenter.sort((a, b) => (b.value || 1) - (a.value || 1));
      const capBase = edgesToCenter.length;
      const cap = connectionCap > 0 ? Math.min(connectionCap, capBase) : capBase;

      const selectedDirect = edgesToCenter.slice(0, cap);
      const usedIds = new Set([centerIdLocal]);
      for (const l of selectedDirect) {
        usedIds.add(idOf(l.source));
        usedIds.add(idOf(l.target));
      }

      const svg = d3.select(svgRef.current);

      // Hide links if either endpoint is outside usedIds or hidden by layer toggle
      svg.selectAll('line.graph-link').classed('graph-link--hidden', function (d) {
        const s = idOf(d.source);
        const t = idOf(d.target);
        if (!(usedIds.has(s) && usedIds.has(t))) return true;
        // Need node types for layer filtering; we can infer by matching node selection
        // Pull node data bound to circles into a map for quick lookup
        // To avoid performance issues, compute once
        return false;
      });

      // Build a quick lookup for node type and donor org flag by id
      const nodeTypeById = {};
      const nodeIsOrgById = {};
      svg.selectAll('circle.graph-node').each(function (nd) {
        if (nd && nd.id) {
          nodeTypeById[nd.id] = nd.type || '';
          nodeIsOrgById[nd.id] = !!nd.isOrg;
        }
      });

      // Resolve per-node layer visibility (splits donors by individuals vs organisations)
      const isNodeLayerVisibleById = (id) => {
        const t = nodeTypeById[id] || '';
        if (t === 'donor') {
          const isOrg = nodeIsOrgById[id] === true;
          return isOrg ? showDonorOrganisations : showDonorIndividuals;
        }
        return isTypeVisible(t);
      };

      // Re-apply link visibility with layer conditions
      svg.selectAll('line.graph-link').classed('graph-link--hidden', function (d) {
        const s = idOf(d.source);
        const t = idOf(d.target);
        if (!(usedIds.has(s) && usedIds.has(t))) return true;
        if (!isNodeLayerVisibleById(s) || !isNodeLayerVisibleById(t)) return true;
        return false;
      });

      // Build a set of node ids that still have at least one visible link
      const visibleLinkNodeIds = new Set();
      svg.selectAll('line.graph-link').each(function (d) {
        // Only collect endpoints for links that are NOT hidden
        if (!this.classList.contains('graph-link--hidden')) {
          const s = idOf(d.source);
          const t = idOf(d.target);
          visibleLinkNodeIds.add(s);
          visibleLinkNodeIds.add(t);
        }
      });

      // Determine if center node itself should be visible (based on layer toggle)
      const centerIsLayerVisible = isNodeLayerVisibleById(centerIdLocal);

      // Node visibility:
      // - must be within usedIds
      // - layer for its type must be visible
      // - and must be attached to at least one visible link
      //   (exception: keep center visible only if its layer is visible)
      svg.selectAll('circle.graph-node').classed('graph-node--hidden', (n) => {
        if (!n || !n.id) return true;
        if (!usedIds.has(n.id)) return true;
        const typeOk = isNodeLayerVisibleById(n.id);
        if (!typeOk) return true;
        if (n.id === centerIdLocal) return !centerIsLayerVisible;
        return !visibleLinkNodeIds.has(n.id);
      });

      // Label visibility mirrors node visibility rules
      svg.selectAll('text.graph-label').classed('graph-label--hidden', function (n) {
        if (!n || !n.id) return true;
        if (!usedIds.has(n.id)) return true;
        const typeOk = isNodeLayerVisibleById(n.id);
        if (!typeOk) return true;
        if (n.id === centerIdLocal) return !centerIsLayerVisible;
        return !visibleLinkNodeIds.has(n.id);
      });
    } catch {
      // ignore if not ready
    }
  }, [
    connectionCap,
    graphData,
    activeFirstName,
    activeLastName,
    profileData,
    selectedPeopleId,
    viewMode,
    activeDonor,
    showPeopleLayer,
    showDonorIndividuals,
    showDonorOrganisations,
    showPartiesLayer
  ]);

  // Highlight selected connection in graph (node + edge to person)
  useEffect(() => {
    try {
      const svg = d3.select(svgRef.current);
      const selId = selectedConnection?.id || null;

      // Recompute current person id for matching
      const profileLabel = profileData ? `${profileData.first_name ?? ''} ${profileData.last_name ?? ''}`.trim() : '';
      const inputLabel = `${activeFirstName ?? ''} ${activeLastName ?? ''}`.trim();
      const personIdLocal = (selectedPeopleId != null)
        ? `person:${selectedPeopleId}`
        : ((profileData && profileData.id != null) ? `person:${profileData.id}` : `person:${(profileLabel || inputLabel || 'Selected Person')}`);

      const idOf = (endp) => (typeof endp === 'object' ? endp?.id : endp);

      svg.selectAll('circle.graph-node')
        .classed('graph-node--selected', (d) => !!selId && d && d.id === selId);

      svg.selectAll('line.graph-link')
        .classed('graph-link--selected', (d) => {
          if (!selId || !d) return false;
          const s = idOf(d.source);
          const t = idOf(d.target);
          return (s === personIdLocal && t === selId) || (t === personIdLocal && s === selId);
        });

      // Also highlight the label of the selected node
      svg.selectAll('text.graph-label')
        .classed('graph-label--selected', (d) => !!selId && d && d.id === selId);
    } catch {
      // ignore if svg not ready
    }
  }, [selectedConnection, graphData, activeFirstName, activeLastName, profileData, selectedPeopleId]);


  // Export donations data as CSV
  const handleExportDonationsCSV = () => {
    if (!Array.isArray(sortedDonations) || sortedDonations.length === 0) {
      alert('No donations data to export');
      return;
    }

    const defaultName = 'person_donations';
    const filename = prompt('Enter a name for your CSV file:', defaultName) || defaultName;
    const finalFilename = filename.endsWith('.csv') ? filename : `${filename}.csv`;

    const headers = ['Date', 'Amount', 'Donor First Name', 'Donor Last Name', 'Donor Organisation', 'Notes'];

    const csvRows = [
      headers.join(','),
      ...sortedDonations.map(donation => {
        const date = donation.date ? new Date(donation.date).toLocaleDateString() : (donation.year || '');
        const amount = typeof donation.amount === 'number' ? `$${donation.amount.toLocaleString()}` : `$${donation.amount}`;
        const donorFirst = donation.donor_first_name || '';
        const donorLast = donation.donor_last_name || '';
        const donorOrg = donation.donor_org_name || '';
        const notes = donation.notes || '';

        return [date, amount, donorFirst, donorLast, donorOrg, notes].join(',');
      })
    ];

    const csvContent = csvRows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', finalFilename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Export meetings data as CSV
  const handleExportMeetingsCSV = () => {
    if (!Array.isArray(sortedMeetings) || sortedMeetings.length === 0) {
      alert('No meetings data to export');
      return;
    }

    const defaultName = 'person_meetings';
    const filename = prompt('Enter a name for your CSV file:', defaultName) || defaultName;
    const finalFilename = filename.endsWith('.csv') ? filename : `${filename}.csv`;

    const headers = ['Date', 'Title', 'Type', 'Portfolio', 'Location', 'Attendees', 'Notes'];

    const csvRows = [
      headers.join(','),
      ...sortedMeetings.map(meeting => {
        const date = meeting.date ? new Date(meeting.date).toLocaleDateString() : '';
        const title = meeting.title || '';
        const type = meeting.type || '';
        const portfolio = meeting.portfolio || '';
        const location = meeting.location || '';
        const attendees = meeting.with_text || '';
        const notes = meeting.notes || '';

        return [date, title, type, portfolio, location, attendees, notes].join(',');
      })
    ];

    const csvContent = csvRows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', finalFilename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Export connections data as CSV
  const handleExportConnectionsCSV = () => {
    if (!Array.isArray(connections) || connections.length === 0) {
      alert('No connections data to export');
      return;
    }

    const defaultName = 'person_connections';
    const filename = prompt('Enter a name for your CSV file:', defaultName) || defaultName;
    const finalFilename = filename.endsWith('.csv') ? filename : `${filename}.csv`;

    const headers = ['Name', 'Type', 'Connection Sources'];

    const csvRows = [
      headers.join(','),
      ...connections.map(conn => {
        const name = conn.label || '';
        const type = conn.nodeType || '';
        const sources = conn.sources ? conn.sources.join('; ') : '';

        return [name, type, sources].join(',');
      })
    ];

    const csvContent = csvRows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', finalFilename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

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
                  placeholder="Organisation"
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

          {isSearching && (
            <div className="loading-message">
              <span className="loading-spinner">⏳</span>
              Loading results...
            </div>
          )}

          {!isSearching && hasSearched && !error && (
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


              {/* Organisation View summary moved below the graph */}

              {/* Graph */}
              <div style={{ marginBottom: '1rem' }}>
                {(profileData?.first_name || activeFirstName || activeLastName) && (
                  <h3 style={{ margin: 0, marginBottom: '0.5rem', color: '#1f2937' }}>
                    {(profileData?.first_name || activeFirstName) + ' ' + (profileData?.last_name || activeLastName)}
                  </h3>
                )}
                {viewMode === 'org' && activeDonor?.aliasNames?.length > 0 && (
                  <div style={{ color: '#6b7280', marginBottom: '0.25rem' }}>
                    Also known as: {activeDonor.aliasNames.join(', ')}
                  </div>
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
                  {/* Graph tooltip */}
                  {graphTooltip && (
                    <div
                      className="graph-tooltip"
                      style={{
                        position: 'absolute',
                        left: graphTooltip.x + 10,
                        top: graphTooltip.y - 10,
                        backgroundColor: 'rgba(0, 0, 0, 0.9)',
                        color: 'white',
                        padding: '10px 14px',
                        borderRadius: '6px',
                        fontSize: '13px',
                        pointerEvents: 'none',
                        zIndex: 1000,
                        maxWidth: '250px',
                        boxShadow: '0 4px 12px rgba(0, 0, 0, 0.3)',
                        border: '1px solid rgba(255, 255, 255, 0.2)'
                      }}
                    >
                      <div style={{ fontWeight: 'bold', marginBottom: '4px' }}>{graphTooltip.label}</div>
                      {graphTooltip.nodeType && (
                        <div style={{ fontSize: '12px', opacity: 0.8, textTransform: 'capitalize' }}>
                          Type: {graphTooltip.nodeType}
                        </div>
                      )}
                      {graphTooltip.sources && graphTooltip.sources.length > 0 && (
                        <div style={{ fontSize: '12px', opacity: 0.8, marginTop: '2px' }}>
                          Source: {graphTooltip.sources.join(', ')}
                        </div>
                      )}
                      {graphTooltip.amountLabel && (
                        <div style={{ 
                          fontSize: '13px', 
                          fontWeight: 'bold', 
                          color: '#ffd700',
                          marginTop: '6px',
                          padding: '4px 8px',
                          backgroundColor: 'rgba(255, 215, 0, 0.1)',
                          borderRadius: '4px',
                          border: '1px solid rgba(255, 215, 0, 0.3)'
                        }}>
                          {graphTooltip.isNodeClick ? 'Total Donated: ' : 'Amount: '}{graphTooltip.amountLabel}
                        </div>
                      )}
                      {graphTooltip.isNodeClick && graphTooltip.nodeType === 'donor' && (
                        <div style={{ fontSize: '11px', opacity: 0.7, marginTop: '4px', fontStyle: 'italic' }}>
                          Click to see details in connections table
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {/* Debug info for graph */}
                {hasSearched && (profileData || activeFirstName || activeLastName) && (
                  <div style={{ marginTop: '0.5rem', fontSize: '0.8rem', color: '#6b7280' }}>
                    Graph Debug: nodes={graphData.nodes?.length || 0}, links={graphData.links?.length || 0},
                    donations={donations.length}, meetings={meetings.length},
                    profileData={profileData ? 'YES' : 'NO'}, activeNames={activeFirstName || activeLastName ? 'YES' : 'NO'}
                  </div>
                )}

                {/* Show graph even if no data */}
                {hasSearched && (!profileData && !activeFirstName && !activeLastName && !activeDonor && (!Array.isArray(orgResults) || orgResults.length === 0)) && (
                  <div style={{ marginTop: '0.5rem', fontSize: '0.8rem', color: '#ef4444', fontWeight: 'bold' }}>
                    No data found - graph cannot be generated
                  </div>
                )}

                {/* Donation Color Legend */}
                <div
                  className="graph-legend"
                  style={{ marginTop: '0.75rem', marginBottom: '0.75rem', padding: '0.75rem', backgroundColor: '#f9fafb', borderRadius: '0.5rem', border: '1px solid #e5e7eb' }}
                >
                  <h4 style={{ margin: '0 0 0.5rem 0', fontSize: '0.9rem', fontWeight: '600', color: '#374151' }}>
                    Donor Node Color Ring Coding:
                  </h4>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '1rem', fontSize: '0.8rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                      <div style={{ width: '12px', height: '12px', borderRadius: '50%', backgroundColor: '#9CA3AF', border: '1px solid #6b7280' }}></div>
                      <span>$0 - $99</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                      <div style={{ width: '12px', height: '12px', borderRadius: '50%', backgroundColor: '#3B82F6', border: '1px solid #1d4ed8' }}></div>
                      <span>$100 - $999</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                      <div style={{ width: '12px', height: '12px', borderRadius: '50%', backgroundColor: '#22C55E', border: '1px solid #16a34a' }}></div>
                      <span>$1,000 - $9,999</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                      <div style={{ width: '12px', height: '12px', borderRadius: '50%', backgroundColor: '#FFD700', border: '1px solid #d97706' }}></div>
                      <span>$10,000+</span>
                    </div>
                  </div>
                  <p style={{ margin: '0.5rem 0 0 0', fontSize: '0.75rem', color: '#6b7280' }}>
                    Node ring color and thickness show donation amount • Click nodes to see details • Drag nodes to reposition
                  </p>
                </div>

                {/* Graph controls */}
                <div className="graph-controls" style={{ display: 'grid', gap: '0.5rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                    <label style={{ minWidth: 160 }}>Edge thickness</label>
                    <input
                      type="range"
                      min="0.5"
                      max="3"
                      step="0.1"
                      value={edgeScale}
                      onChange={(e) => setEdgeScale(parseFloat(e.target.value))}
                      style={{ '--range-progress': `${edgeProgress}%` }}
                    />
                    <span style={{ fontVariantNumeric: 'tabular-nums' }}>{edgeScale.toFixed(1)}x</span>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                    <label style={{ minWidth: 160 }}>Connections shown</label>
                    <input
                      type="range"
                      min="1"
                      max={maxConnections}
                      step="1"
                      value={effectiveConnectionValue}
                      onChange={(e) => setConnectionCap(parseInt(e.target.value, 10) || 0)}
                      style={{ '--range-progress': `${connectionProgress}%` }}
                    />
                    <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                      {effectiveConnectionValue}/{maxConnections}
                    </span>
                  </div>

                  {/* Layer toggles */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', flexWrap: 'wrap', marginTop: '0.25rem' }}>
                    <div style={{ fontWeight: 600 }}>Show layers:</div>
                    <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                      <input type="checkbox" checked={showPeopleLayer} onChange={(e) => setShowPeopleLayer(e.target.checked)} />
                      People
                    </label>
                    <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                      <input type="checkbox" checked={showDonorIndividuals} onChange={(e) => setShowDonorIndividuals(e.target.checked)} />
                      Donor Individuals
                    </label>
                    <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                      <input type="checkbox" checked={showDonorOrganisations} onChange={(e) => setShowDonorOrganisations(e.target.checked)} />
                      Organisations
                    </label>
                    {viewMode === 'person' && (
                      <>
                        <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.25rem' }}>
                          <input type="checkbox" checked={showPartiesLayer} onChange={(e) => setShowPartiesLayer(e.target.checked)} />
                          Parties
                        </label>
                      </>
                    )}
                  </div>

                  {/* Edge weighting note (always on) */}
                  <div style={{ color: '#6b7280', fontSize: '0.9rem' }}>
                    Donations by total amount; Meetings by frequency. Higher values pull nodes closer.
                  </div>
                </div>

                {/* Toggle for Organisation Profile */}
                {viewMode === 'org' && activeDonor && (
                  <div className="section-toggles" style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem', flexWrap: 'wrap' }}>
                    <button
                      type="button"
                      className="search-button"
                      onClick={() => setShowOrgProfile((v) => !v)}
                      title={showOrgProfile ? 'Hide Organisation Profile' : 'Show Organisation Profile'}
                    >
                      {showOrgProfile ? '▼ Hide Organisation Profile' : '▶ Show Organisation Profile'}
                    </button>
                  </div>
                )}

                {/* Organisation Profile (shown below the graph for organisation view) */}
                {viewMode === 'org' && activeDonor && showOrgProfile && (
                  <div className="table-container" style={{ marginTop: '0.75rem' }}>
                    <h3 style={{ marginTop: 0, color: '#1f2937' }}>
                      Organisation Profile — {activeDonor.org_name || ([activeDonor.first_name || '', activeDonor.last_name || ''].filter(Boolean).join(' ').trim() || (activeDonor.id ? `Donor ${activeDonor.id}` : ''))}
                    </h3>

                    {/* People funded (aggregated) */}
                    <div style={{ marginTop: '0.5rem' }}>
                      <table className="min-w-full border">
                        <thead>
                          <tr>
                            <th>Recipient</th>
                            <th style={{ textAlign: 'right', width: '80px' }}>Number of Donations</th>
                            <th style={{ textAlign: 'right' }}>Total Amount</th>
                          </tr>
                        </thead>
                        <tbody>
                          {Array.isArray(orgRecipients) && orgRecipients.length > 0 ? (
                            orgRecipients.map((r) => {
                              const name = [r.first_name || '', r.last_name || ''].filter(Boolean).join(' ').trim() || `Person ${r.people_id}`;
                              const total = Number(orgTotalsByPerson?.[String(r.people_id)] || 0);
                              const donationRows = orgDonationsByRecipient?.[String(r.people_id)] || [];
                              const donationCount = donationRows.length;
                              return (
                                <tr key={r.people_id}>
                                  <td>
                                    <button
                                      type="button"
                                      className="search-button"
                                      style={{ padding: '4px 8px', fontSize: '12px', minHeight: 'auto' }}
                                      onClick={() => handleClickPersonMatch({ id: r.people_id, first_name: r.first_name, last_name: r.last_name })}
                                      title="Open person profile"
                                    >
                                      {name}
                                    </button>
                                  </td>
                                  <td style={{ textAlign: 'right', width: '80px' }}>{donationCount}</td>
                                  <td style={{ textAlign: 'right' }}>${total.toLocaleString()}</td>
                                </tr>
                              );
                            })
                          ) : (
                            <tr>
                              <td colSpan={3} style={{ color: '#6b7280' }}>No recipients found.</td>
                            </tr>
                          )}
                        </tbody>
                      </table>
                    </div>

                  </div>
                )}

                {selectedConnection && (
                  <div ref={detailsRef} key={(selectedConnection.id || selectedConnection.label || 'sel')} className="table-container" style={{ marginTop: '0.75rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.5rem' }}>
                      <h4 style={{ margin: 0, color: '#111827' }}>
                        Details for: {selectedConnection.label} — {selectedConnection.sources && selectedConnection.sources.join(', ')}
                      </h4>
                      <button
                        type="button"
                        className="reset-button"
                        onClick={() => setShowGraphDetails((v) => !v)}
                        title={showGraphDetails ? 'Hide details' : 'Show details'}
                      >
                        {showGraphDetails ? 'Hide' : 'Show'}
                      </button>
                    </div>
                    {showGraphDetails && (
                      Array.isArray(connectionDetails) && connectionDetails.length > 0 ? (
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
                      ) : (
                        <div style={{ marginTop: '0.5rem', color: '#6b7280' }}>No details found.</div>
                      )
                    )}
                  </div>
                )}

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
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem' }}>
                    <h3 style={{ margin: 0, color: '#1f2937' }}>Donation History (All Years)</h3>
                    <button
                      onClick={handleExportDonationsCSV}
                      className="export-button"
                      title="Export donations data as CSV"
                    >
                      <span>📥</span>
                      Export Donations CSV
                    </button>
                  </div>
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
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem' }}>
                    <h3 style={{ margin: 0, color: '#1f2937' }}>Meetings</h3>
                    <button
                      onClick={handleExportMeetingsCSV}
                      className="export-button"
                      title="Export meetings data as CSV"
                    >
                      <span>📥</span>
                      Export Meetings CSV
                    </button>
                  </div>
                  <div className="table-container">
                    <MeetingsTable meetings={sortedMeetings} />
                  </div>
                </>
              )}

              {/* Connections (summary of direct links from the selected person) */}
              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem' }}>
                  <h3 style={{ margin: 0, color: '#1f2937' }}>
                    Connections {Array.isArray(connections) ? `(${connections.length})` : ''}
                  </h3>
                  {Array.isArray(connections) && connections.length > 0 && (
                    <button
                      onClick={handleExportConnectionsCSV}
                      className="export-button"
                      title="Export connections data as CSV"
                    >
                      <span>📥</span>
                      Export Connections CSV
                    </button>
                  )}
                </div>
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
                          <tr
                            key={c.id}
                            data-id={c.id}
                            className={selectedConnection?.id === c.id ? 'conn-row conn-row--selected' : 'conn-row'}
                            onClick={() => handleClickConnection(c, true)}
                            style={{ cursor: 'pointer' }}
                            title="Click to view details"
                            aria-selected={selectedConnection?.id === c.id}
                          >
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

                  </>
                ) : (
                  <p className="results-note">No direct connections were found for this person.</p>
                )}
              </div>

              {/* No results after search */}
              {viewMode === 'person' &&
                (!sortedDonations || sortedDonations.length === 0) &&
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
