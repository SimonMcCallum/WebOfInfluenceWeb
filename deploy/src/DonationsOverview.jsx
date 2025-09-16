import React, { useState } from 'react';
import Output from './Output.jsx';
import { useNavigate } from 'react-router-dom';
import BarChart from './BarChart.jsx';
import { API_BASE } from './apiConfig';
import './DonationsOverview.css';

function DonationsOverview() {
    // State variables for search query
    const [searchQuery, setSearchQuery] = useState({
        firstName: '',
        lastName: '',
        party: '',
        electorate: '',
    });

    // State to manage selected years for filtering
    const [selectedYears, setSelectedYears] = useState({
        2023: true,
        2017: false,
        2014: false,
        2011: false
    });

    // State to manage results and processed results
    const [results, setResults] = useState(null);
    const [processedResults, setProcessedResults] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [hasSearched, setHasSearched] = useState(false);

    const navigate = useNavigate();
    const handleBackToHome = () => navigate('/home');

    const handleSearchChange = (event) => {
        const { name, value } = event.target;
        setSearchQuery(prevState => ({
            ...prevState,
            [name]: value
        }));
    };

    const handleYearChange = (year) => {
        setSelectedYears(prevYears => ({
            ...prevYears,
            [year]: !prevYears[year]
        }));
    };

    const handleSearchSubmit = async () => {
        setHasSearched(true);
        setError(null);
        setIsLoading(true);
        // Reset results and processed results
        setResults([]);
        setProcessedResults(null);

        // Filter selected years
        const activeYears = Object.keys(selectedYears).filter(year => selectedYears[year]);

        // Check if at least one year is selected and if there are search criteria
        const hasCriteria = !!(
            searchQuery.firstName ||
            searchQuery.lastName ||
            searchQuery.party ||
            searchQuery.electorate
        );

        if (activeYears.length === 0 || !hasCriteria) {
            setIsLoading(false);
            setResults([]);
            setError('Pick at least one year and enter a filter.');
            return;
        }

        // Prepare search parameters
        const params = new URLSearchParams();
        if (searchQuery.firstName) params.append('first_name', searchQuery.firstName);
        if (searchQuery.lastName) params.append('last_name', searchQuery.lastName);
        if (searchQuery.party) params.append('party_name', searchQuery.party);
        if (searchQuery.electorate) params.append('electorate_name', searchQuery.electorate);

        let allResults = [];

        for (let year of activeYears) {
            try {
                const response = await fetch(
                    `${API_BASE}/candidates/election-overview/${year}/search/combined?${params.toString()}`
                );

                if (response.ok) {
                    const data = await response.json();
                    console.log(data);
                    const resultsWithYear = data.map(item => ({
                        ...item,
                        election_year: year,
                    }));
                    allResults = [...allResults, ...resultsWithYear];
                }
            } catch (err) {
                console.error(`Error fetching data for ${year}:`, err);
            }
        }

        // Process results for the chart
        setResults(allResults.length === 0 ? [] : allResults);
        setIsLoading(false);
    };

    // add this helper
    const handleReset = () => {
        setSearchQuery({ firstName: '', lastName: '', party: '', electorate: '' });
        setSelectedYears({ 2023: true, 2017: false, 2014: false, 2011: false });
        setResults([]);
        setProcessedResults(null);
        setError(null);
        setHasSearched(false);
    };


    const handleExportCSV = (processedResults) => {
        if (!processedResults || processedResults.length === 0) {
            alert('No data to export');
            return;
        }

        const defaultName = 'election_candidates';
        const filename = prompt('Enter a name for your CSV file:', defaultName) || defaultName;

        const finalFilename = filename.endsWith('.csv') ? filename : `${filename}.csv`;

        const get = (obj, ...keys) => {
            for (const k of keys) {
                const v = obj?.[k];
                if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
            }
            return '';
        };

        const headers = ['Name', 'Party', 'Electorate', 'Election Year', 'Total Expenses', 'Total Donations'];

        const csvRows = [
            headers.join(','),
            ...processedResults.map(r => {
                const first = get(r, 'firstName', 'first_name') || 'Unknown';
                const last = get(r, 'lastName', 'last_name');
                const name = [first, last].filter(Boolean).join(' ');
                const party = get(r, 'party', 'party_name') || 'Unknown';
                const electorate = get(r, 'electorate', 'electorate_name') || 'Unknown';
                const year = get(r, 'election_year', 'year');
                const totExp = get(r, 'total_expenses');
                const totDon = get(r, 'total_donations');
                return [name, party, electorate, year, totExp, totDon].join(',');
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

    // Export detailed CSV by enriching raw results with names/party/electorate
    const exportDetailedCSV = async () => {
        if (!Array.isArray(results) || results.length === 0) {
            alert('No data to export');
            return;
        }

        const safe = (v, def = 'Unknown') => (v === null || v === undefined || String(v).trim() === '' ? def : String(v).trim());

        // Simple in-memory caches to avoid duplicate lookups during export
        const personCache = new Map();
        const partyCache = new Map();
        const electorateCache = new Map();

        const detailed = await Promise.all(results.map(async (result) => {
            // Prefer inline fields from API to avoid extra calls
            let firstName = safe(result.first_name || result.firstName || '', 'Unknown');
            let lastName = safe(result.last_name || result.lastName || '', '');
            let party = safe(result.party_name || result.party || '', 'Unknown');
            let electorate = safe(result.electorate_name || result.electorate || '', 'Unknown');

            // People fallback
            try {
                if ((firstName === 'Unknown' && lastName === '') && result?.people_id) {
                    const key = String(result.people_id);
                    if (personCache.has(key)) {
                        const [f, l] = personCache.get(key);
                        firstName = f; lastName = l;
                    } else {
                        const res = await fetch(`${API_BASE}/candidates/search-id?people_id=${encodeURIComponent(result.people_id)}`);
                        if (res.ok) {
                            const data = await res.json();
                            const d = Array.isArray(data) && data.length ? data[0] : (data || {});
                            firstName = safe(d?.first_name, firstName);
                            lastName = safe(d?.last_name, lastName);
                            personCache.set(key, [firstName, lastName]);
                        }
                    }
                }
            } catch (_) {}

            // Party fallback
            try {
                if (party === 'Unknown' && result?.party_id) {
                    const key = String(result.party_id);
                    if (partyCache.has(key)) {
                        party = partyCache.get(key);
                    } else {
                        const res = await fetch(`${API_BASE}/party/search-id?party_id=${encodeURIComponent(result.party_id)}`);
                        if (res.ok) {
                            const data = await res.json();
                            const d = Array.isArray(data) && data.length ? data[0] : (data || {});
                            party = safe(d?.party_name || d?.name, party);
                            partyCache.set(key, party);
                        }
                    }
                }
            } catch (_) {}

            // Electorate fallback
            try {
                if (electorate === 'Unknown' && result?.electorate_id) {
                    const key = String(result.electorate_id);
                    if (electorateCache.has(key)) {
                        electorate = electorateCache.get(key);
                    } else {
                        const res = await fetch(`${API_BASE}/electorate/search-id?electorate_id=${encodeURIComponent(result.electorate_id)}`);
                        if (res.ok) {
                            const data = await res.json();
                            const d = Array.isArray(data) && data.length ? data[0] : (data || {});
                            electorate = safe(d?.electorate_name || d?.name, electorate);
                            electorateCache.set(key, electorate);
                        }
                    }
                }
            } catch (_) {}

            return {
                firstName,
                lastName,
                party,
                electorate,
                total_expenses: result.total_expenses || 0,
                total_donations: result.total_donations || 0,
                election_year: result.election_year || result.year || 'Unknown',
            };
        }));

        handleExportCSV(detailed);
    };

    // Processed results and setProcessedResults
    const processedResultsForChart = (results) => {
        if (results && results.length > 0 ) {
            const processedData = results.map(result => ({
                firstName: result.firstName || 'Unknown',
                lastName: result.lastName || 'Unknown', 
                total_donations: result.total_donations || 0,
            }));
            setProcessedResults(processedData);
        }
    };

    return (
        <div className="donations-page">
            {/* Header Section */}
            <div className="donations-header">
                <div className="header-content">
                    <div className="header-left">
                        <div className="page-icon">💰</div>
                        <div className="header-text">
                            <h1 className="page-title">Donations Overview</h1>
                            <p className="page-subtitle">Search and analyze political donation data</p>
                        </div>
                    </div>
                    <button onClick={handleBackToHome} className="back-button">
                        ← Back to Home
                    </button>
                </div>
            </div>

            {/* Main Content */}
            <div className="donations-container">
                {/* Search Section */}
                <div className="search-section">
                    <div className="search-card">
                        <div className="card-header">
                            <h2 className="card-title">
                                <span className="card-icon">🔍</span>
                                Search Filters
                            </h2>
                            <button
                                type="button"
                                className="reset-button"
                                onClick={handleReset}
                            >
                                <span>↺</span>
                                Reset
                            </button>
                        </div>

                        {/* Year Selection */}
                        <div className="filter-group">
                            <label className="filter-label">Election Years</label>
                            <div className="year-toggle-group">
                                {Object.keys(selectedYears).sort().reverse().map((year) => (
                                    <button
                                        key={year}
                                        type="button"
                                        className={`year-chip ${selectedYears[year] ? 'active' : ''}`}
                                        onClick={() => handleYearChange(year)}
                                        aria-pressed={selectedYears[year]}
                                    >
                                        {year}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Search Inputs - Using original working structure */}
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
                                <span className="icon" aria-hidden>🏛️</span>
                                <input
                                    type="text"
                                    name="party"
                                    placeholder="Political Party"
                                    value={searchQuery.party}
                                    onChange={handleSearchChange}
                                    className="input"
                                />
                            </div>

                            <div className="field">
                                <span className="icon" aria-hidden>📍</span>
                                <input
                                    type="text"
                                    name="electorate"
                                    placeholder="Electorate"
                                    value={searchQuery.electorate}
                                    onChange={handleSearchChange}
                                    className="input"
                                />
                            </div>
                        </div>

                        {/* Search Button */}
                        <button
                            type="button"
                            className="search-button"
                            onClick={handleSearchSubmit}
                            disabled={isLoading}
                        >
                            {isLoading ? (
                                <>
                                    <span className="loading-spinner">⏳</span>
                                    Searching...
                                </>
                            ) : (
                                <>
                                    <span>🔍</span>
                                    Search Donations
                                </>
                            )}
                        </button>
                    </div>
                </div>

                {/* Results Section */}
                {hasSearched && (
                    <div className="results-section">
                        <div className="results-header">
                            <h2 className="results-title">
                                <span className="results-icon">📊</span>
                                Search Results
                                {Array.isArray(results) && results.length > 0 && (
                                    <span className="results-count">({results.length} donations found)</span>
                                )}
                            </h2>
                            {Array.isArray(results) && results.length > 0 && (
                                <button
                                    onClick={exportDetailedCSV}
                                    className="export-button"
                                >
                                    <span>📥</span>
                                    Export CSV
                                </button>
                            )}
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

                        {Array.isArray(results) && results.length > 0 && (
                            <div className="results-content">
                                <Output results={results} onExportCSV={handleExportCSV} />
                                <BarChart results={results} isLoading={isLoading} />
                            </div>
                        )}

                        {Array.isArray(results) && results.length === 0 && !isLoading && !error && (
                            <div className="no-results">
                                <span className="no-results-icon">🔍</span>
                                <h3>No results found</h3>
                                <p>Try adjusting your search criteria or selecting different years.</p>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );

}

export default DonationsOverview;
