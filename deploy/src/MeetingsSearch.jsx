import React, { useState, useEffect } from 'react';
import MeetingsTable from './MeetingsTable';
import './DonationsOverview.css';
import './MeetingsSearch.css';
import { API_BASE } from './apiConfig';
import { useNavigate } from 'react-router-dom';
import ResponsivePagination from 'react-responsive-pagination';
import 'react-responsive-pagination/themes/bootstrap.css';

// Format "HH:MM[:SS]" into "h:MM AM/PM"
const formatTime = (t) => {
  if (!t) return null;
  const m = String(t).match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  if (!m) return t;
  let h = parseInt(m[1], 10);
  const min = m[2];
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12;
  if (h === 0) h = 12;
  return `${h}:${min} ${ampm}`;
};

const MeetingsSearch = () => {
  const [searchQuery, setSearchQuery] = useState({
    firstName: '',
    lastName: '',
    startDate: '',
    endDate: '',
    portfolio: ''
  });
  const [meetings, setMeetings] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [hasSearched, setHasSearched] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 20;

  useEffect(() => { setCurrentPage(1); }, [meetings]);

  const navigate = useNavigate();
  const handleBackToHome = () => navigate('/home');
  const handleOpenEvent = (meetingId) => {
    if (!meetingId) return;
    navigate(`/events?meeting_id=${meetingId}`);
  };
  
  const handleSearchChange = (event) => {
    const { name, value } = event.target;
    setSearchQuery(prevState => ({ ...prevState, [name]: value }));
  };

  const handleSearchSubmit = async () => {
    setHasSearched(true);
    setError(null);
    setIsLoading(true);
    setCurrentPage(1);
    
    // Reset results
    setMeetings([]);

    // Check if there are search criteria
    const hasCriteria = !!(
      searchQuery.firstName ||
      searchQuery.lastName ||
      searchQuery.startDate ||
      searchQuery.endDate ||
      searchQuery.portfolio
    );

    if (!hasCriteria) {
      setIsLoading(false);
      setMeetings([]);
      setError('Please enter at least one search criteria.');
      return;
    }

    try {
      const params = new URLSearchParams();
      if (searchQuery.firstName) params.append('first_name', searchQuery.firstName);
      if (searchQuery.lastName) params.append('last_name', searchQuery.lastName);
      if (searchQuery.startDate) params.append('start_date', searchQuery.startDate);
      if (searchQuery.endDate) params.append('end_date', searchQuery.endDate);
      if (searchQuery.portfolio) params.append('portfolio', searchQuery.portfolio);

      const response = await fetch(
        `${API_BASE}/ministerial_diaries/search-cand-filter?${params.toString()}`
      );

      if (response.ok) {
        const data = await response.json();
        setMeetings(data.length === 0 ? [] : data);
      } else {
        const errorData = await response.json();
        setError(errorData.error || 'No meetings found');
        setMeetings([]);
      }
    } catch (err) {
      console.error('Error fetching meetings:', err);
      setError('Error fetching meetings data');
      setMeetings([]);
    } finally {
      setIsLoading(false);
    }
  };

  const handleReset = () => {
    setSearchQuery({ 
      firstName: '', 
      lastName: '', 
      startDate: '', 
      endDate: '', 
      portfolio: '' 
    });
    setMeetings([]);
    setError(null);
    setHasSearched(false);
    setCurrentPage(1);
  };

  const handleExportCSV = () => {
    if (!meetings || meetings.length === 0) {
      alert('No data to export');
      return;
    }

    const defaultName = 'minister_meetings';
    const filename = prompt('Enter a name for your CSV file:', defaultName) || defaultName;
    const finalFilename = filename.endsWith('.csv') ? filename : `${filename}.csv`;

    const headers = ['Date', 'Start Time', 'End Time', 'Title', 'Type', 'Portfolio', 'Location', 'Attendees', 'Notes'];

    const csvRows = [
      headers.join(','),
      ...meetings.map(meeting => [
        new Date(meeting.date).toLocaleDateString(),
        formatTime(meeting.start_time) || 'N/A',
        formatTime(meeting.end_time) || 'N/A',
        `"${meeting.title || 'N/A'}"`,
        meeting.type || 'N/A',
        meeting.portfolio || 'N/A',
        `"${meeting.location || 'N/A'}"`,
        `"${meeting.notes || 'N/A'}"`,
        `"${(meeting.with_text ? String(meeting.with_text) : 'N/A').replaceAll('"', '""')}"`
      ].join(','))
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

  // Calculate pagination
  const totalPages = meetings ? Math.ceil(meetings.length / itemsPerPage) : 0;
  const paginatedMeetings = meetings ? meetings.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage
  ) : [];

  return (
    <div className="donations-page">
      {/* Header Section */}
      <div className="donations-header">
        <div className="header-content">
          <div className="header-left">
            <div className="page-icon">📅</div>
            <div className="header-text">
              <h1 className="page-title">Ministerial Meetings</h1>
              <p className="page-subtitle">Search and analyze ministerial meeting data</p>
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

            {/* Search Inputs */}
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
                <span className="icon" aria-hidden>📅</span>
                <input
                  type="date"
                  name="startDate"
                  placeholder="Start Date"
                  value={searchQuery.startDate}
                  onChange={handleSearchChange}
                  className="input"
                />
              </div>

              <div className="field">
                <span className="icon" aria-hidden>📅</span>
                <input
                  type="date"
                  name="endDate"
                  placeholder="End Date"
                  value={searchQuery.endDate}
                  onChange={handleSearchChange}
                  className="input"
                />
              </div>

              <div className="field">
                <span className="icon" aria-hidden>🏛️</span>
                <input
                  type="text"
                  name="portfolio"
                  placeholder="Portfolio"
                  value={searchQuery.portfolio}
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
                  Search Meetings
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
                {meetings && meetings.length > 0 && (
                  <span className="results-count">({meetings.length} meetings found)</span>
                )}
              </h2>
              {meetings && meetings.length > 0 && (
                <button
                  onClick={handleExportCSV}
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

            {Array.isArray(meetings) && meetings.length > 0 && (
              <div className="results-content">
                {/* Pagination Above Table */}
                {totalPages > 1 && (
                  <div className="pagination-wrapper">
                    <ResponsivePagination
                      current={currentPage}
                      total={totalPages}
                      onPageChange={(page) => {
                        setCurrentPage(page);
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                      }}
                      maxWidth={800}
                      previousLabel="Previous"
                      nextLabel="Next"
                      renderOnZeroPageCount={null}
                    />
                  </div>
                )}

                {/* Meetings Table */}
                <div className="table-container">
                  <MeetingsTable meetings={paginatedMeetings} onOpenEvent={handleOpenEvent} />
                </div>

                {/* Pagination Below Table */}
                {totalPages > 1 && (
                  <div className="pagination-wrapper">
                    <ResponsivePagination
                      current={currentPage}
                      total={totalPages}
                      onPageChange={(page) => {
                        setCurrentPage(page);
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                      }}
                      maxWidth={800}
                      previousLabel="Previous"
                      nextLabel="Next"
                      renderOnZeroPageCount={null}
                    />
                  </div>
                )}
              </div>
            )}

            {Array.isArray(meetings) && meetings.length === 0 && !isLoading && !error && (
              <div className="no-results">
                <span className="no-results-icon">🔍</span>
                <h3>No meetings found</h3>
                <p>Try adjusting your search criteria or date range.</p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default MeetingsSearch;
