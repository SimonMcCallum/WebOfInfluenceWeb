import React, { useState, useEffect } from 'react';
import ResponsivePagination from 'react-responsive-pagination';
import 'react-responsive-pagination/themes/bootstrap.css';
import { API_BASE } from './apiConfig';

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
    try {
        const personResponse = await fetch(`${API_BASE}/candidates/search-id?people_id=${result.people_id}`);
        const personData = await personResponse.json();

        const partyResponse = await fetch(`${API_BASE}/party/search-id?party_id=${result.party_id}`);
        const partyData = await partyResponse.json();

        const electorateResponse = await fetch(`${API_BASE}/electorate/search-id?electorate_id=${result.electorate_id}`);
        const electorateData = await electorateResponse.json();

        return {
            firstName: personData[0]?.first_name || "Unknown",
            lastName: personData[0]?.last_name || "Unknown",
            party: partyData[0]?.party_name || "Unknown",
            electorate: electorateData?.electorate_name || "Unknown",
            total_expenses: result.total_expenses || 0,
            total_donations: result.total_donations || 0,
            election_year: result.election_year || "Unknown"
        };
    } catch (error) {
        console.error("Error fetching additional details:", error);
        return {
            firstName: "Error",
            lastName: "Error",
            party: "Error",
            electorate: "Error",
            total_expenses: result.total_expenses || 0,
            total_donations: result.total_donations || 0,
            election_year: result.election_year || "Unknown"
        };
    }
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
                const detailedResults = await Promise.all(
                    results.map(result => fetchAdditionalDetails(result))
                );
                setProcessedResults(detailedResults);
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
            <div className="actions">
                <button
                    type="button"
                    className="export-button"
                    onClick={() => onExportCSV(processedResults)}
                > ⬇︎ Export to CSV
                </button>
            </div>
            
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