import React, { useState, useEffect } from 'react';
import ResponsivePagination from 'react-responsive-pagination';
import 'react-responsive-pagination/themes/bootstrap.css';
import { API_BASE } from './apiConfig';

// Caches to avoid repeated lookups and speed up rendering
const personCache = new Map();
const partyCache = new Map();
const electorateCache = new Map();

class Entry {
    constructor(people_id, party_id, electorate_id, total_expenses, total_donations, election_year) {
        this.people_id = people_id;
        this.party_id = party_id;
        this.electorate_id = electorate_id;
        this.total_expenses = total_expenses;
        this.total_donations = total_donations;
        this.election_year = election_year;
    }
}

const fetchAdditionalDetails = async (result) => {
    const safe = (v, def = "Unknown") => (v === null || v === undefined || v === "" ? def : v);
    const buildUrl = (pathWithQuery) => {
        try { return `${API_BASE}${pathWithQuery}`; } catch { return null; }
    };

    // Defaults
    let firstName = "Unknown";
    let lastName = "Unknown";
    let party = "Unknown";
    let electorate = "Unknown";

    // Prefer inline fields from combined API response to avoid extra lookups
    if (result) {
        firstName = safe(result.first_name, firstName);
        lastName = safe(result.last_name, lastName);
        party = safe(result.party_name, party);
        electorate = safe(result.electorate_name, electorate);
    }

    // People
    try {
        if (result?.people_id && (firstName === "Unknown" || lastName === "Unknown")) {
            const cacheKey = String(result.people_id);
            if (personCache.has(cacheKey)) {
                const [fn, ln] = personCache.get(cacheKey);
                firstName = safe(fn);
                lastName = safe(ln);
            } else {
                const url = buildUrl(`/candidates/search-id?people_id=${encodeURIComponent(result.people_id)}`);
                if (url) {
                    const res = await fetch(url);
                    if (res.ok) {
                        const data = await res.json();
                        if (Array.isArray(data) && data.length) {
                            firstName = safe(data[0]?.first_name);
                            lastName = safe(data[0]?.last_name);
                            personCache.set(cacheKey, [firstName, lastName]);
                        } else if (data && typeof data === "object") {
                            firstName = safe(data.first_name);
                            lastName = safe(data.last_name);
                            personCache.set(cacheKey, [firstName, lastName]);
                        }
                    }
                }
            }
        }
    } catch (e) {
        console.warn("people lookup failed", e);
    }

    // Party
    try {
        if (result?.party_id && party === "Unknown") {
            const cacheKey = String(result.party_id);
            if (partyCache.has(cacheKey)) {
                party = safe(partyCache.get(cacheKey));
            } else {
                const url = buildUrl(`/party/search-id?party_id=${encodeURIComponent(result.party_id)}`);
                if (url) {
                    const res = await fetch(url);
                    if (res.ok) {
                        const data = await res.json();
                        if (Array.isArray(data) && data.length) {
                            party = safe(data[0]?.party_name);
                            partyCache.set(cacheKey, party);
                        } else if (data && typeof data === "object") {
                            party = safe(data.party_name || data.name);
                            partyCache.set(cacheKey, party);
                        }
                    }
                }
            }
        }
    } catch (e) {
        console.warn("party lookup failed", e);
    }

    // Electorate
    try {
        if (result?.electorate_id && electorate === "Unknown") {
            const cacheKey = String(result.electorate_id);
            if (electorateCache.has(cacheKey)) {
                electorate = safe(electorateCache.get(cacheKey));
            } else {
                const url = buildUrl(`/electorate/search-id?electorate_id=${encodeURIComponent(result.electorate_id)}`);
                if (url) {
                    const res = await fetch(url);
                    if (res.ok) {
                        const data = await res.json();
                        if (Array.isArray(data) && data.length) {
                            electorate = safe(data[0]?.electorate_name || data[0]?.name);
                            electorateCache.set(cacheKey, electorate);
                        } else if (data && typeof data === "object") {
                            electorate = safe(data.electorate_name || data.name);
                            electorateCache.set(cacheKey, electorate);
                        }
                    }
                }
            }
        }
    } catch (e) {
        console.warn("electorate lookup failed", e);
    }

    return {
        firstName,
        lastName,
        party,
        electorate,
        total_expenses: result.total_expenses || 0,
        total_donations: result.total_donations || 0,
        election_year: result.election_year || "Unknown",
    };
};

const Output = ({ results, onExportCSV }) => {
    const [processedResults, setProcessedResults] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 10;

    useEffect(() => {
        const processResults = async () => {
            setIsLoading(true);

            if (!results || results.length === 0 || (results.length === 1 && typeof results[0] === 'string' && results[0] === 'No results found')) {
                setProcessedResults([]);
                setIsLoading(false);
                return;
            }

            try {
                // Fast path: if inline fields are present, avoid extra lookups so the table renders immediately
                const inlineReady = results.every(r =>
                    r && (r.first_name || r.last_name || r.party_name || r.electorate_name)
                );

                if (inlineReady) {
                    const mapped = results.map(r => ({
                        firstName: r.first_name || 'Unknown',
                        lastName: r.last_name || 'Unknown',
                        party: r.party_name || 'Unknown',
                        electorate: r.electorate_name || 'Unknown',
                        total_expenses: r.total_expenses || 0,
                        total_donations: r.total_donations || 0,
                        election_year: r.election_year || 'Unknown',
                    }));
                    setProcessedResults(mapped);
                } else {
                    // Fallback: enrich only when inline fields are missing
                    const detailedResults = await Promise.all(
                        results.map(result => fetchAdditionalDetails(result))
                    );
                    setProcessedResults(detailedResults);
                }
            } catch (error) {
                console.error("Error processing results:", error);
                setProcessedResults([]);
            } finally {
                setIsLoading(false);
            }
        };

        processResults();
    }, [results]);

    // Calculate pagination values 
    const totalPages = Math.ceil(processedResults.length / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const currentData = processedResults.slice(startIndex, endIndex);

    const handlePageChange = (page) => {
        setCurrentPage(page);
        //window.scrollTo(0, 0); // Scroll to top when page changes
    };

    if (isLoading) {
        return <div>Loading results...</div>;
    }

    if (processedResults.length === 0) {
        return <p className="text-center text-gray-500">No results found</p>;
    }

    return (
        <div>
            {/* Use your shared button styles */}
            <div className="pagination-row" style={{ marginTop: '40px', marginBottom: '20px' }}>
                <ResponsivePagination
                    current={currentPage}
                    total={totalPages}
                    onPageChange={handlePageChange}
                    maxWidth={600}
                    previousLabel={currentPage > 1 ? "Previous" : ""}
                    nextLabel={currentPage < totalPages ? "Show more" : ""}
                />
            </div>

            <table
                style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    marginTop: '1rem',
                    fontFamily: 'Arial, sans-serif',
                    fontSize: '0.9rem',
                    color: '#333',
                }}
            >
                <thead>
                    <tr
                        style={{
                            backgroundColor: '#f5f5f5',
                            borderBottom: '2px solid #ddd',
                            textAlign: 'left',
                        }}
                    >
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Name</th>
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Party</th>
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Electorate</th>
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Election Year</th>
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Total Expenses</th>
                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>Total Donations</th>
                    </tr>
                </thead>
                <tbody>
                    {currentData.map((detail, index) => (
                        <tr
                            key={index}
                            style={{
                                backgroundColor: index % 2 === 0 ? '#f9f9f9' : '#fff',
                                borderBottom: '1px solid #ddd',
                                textAlign: 'left',
                            }}
                            onMouseOver={(e) => (e.currentTarget.style.backgroundColor = '#e8f5e9')}
                            onMouseOut={(e) => (e.currentTarget.style.backgroundColor = index % 2 === 0 ? '#f9f9f9' : '#fff')}
                        >
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.firstName} {detail.lastName}
                            </td>
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.party}
                            </td>
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.electorate}
                            </td>
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.election_year}
                            </td>
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.total_expenses}
                            </td>
                            <td style={{ padding: '0.75rem', borderBottom: '1px solid #ddd' }}>
                                {detail.total_donations}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

export default Output;
