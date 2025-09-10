import React, { useState, useEffect } from 'react';
import MeetingsTable from './MeetingsTable';
import './MeetingsSearch.css';
import './CandidateOverview.css';
import { API_BASE } from './apiConfig';
import { useNavigate } from 'react-router-dom';
import ResponsivePagination from 'react-responsive-pagination';
import 'react-responsive-pagination/themes/bootstrap.css';

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
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;
  useEffect(() => { setCurrentPage(1); }, [meetings]);

  const navigate = useNavigate();
  const handleBackToHome = () => navigate('/home');
  
  const handleSearchChange = (event) => {
    const { name, value } = event.target;
    setSearchQuery(prevState => ({ ...prevState, [name]: value }));
  };

  const handleSearchSubmit = async () => {
    if (!searchQuery.firstName && !searchQuery.lastName && !searchQuery.startDate && !searchQuery.endDate && !searchQuery.portfolio) {
      alert('Please enter at least one search criteria');
      return;
    }

    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        first_name: searchQuery.firstName,
        last_name: searchQuery.lastName,
        start_date: searchQuery.startDate,
        end_date: searchQuery.endDate,
        portfolio: searchQuery.portfolio
      });

      const response = await fetch(
        `${API_BASE}/ministerial_diaries/search-cand-filter?${params.toString()}`
      );

      if (response.ok) {
        const data = await response.json();
        setMeetings(data);
      } else {
        const error = await response.json();
        alert(error.error || 'No meetings found');
        setMeetings([]);
      }
    } catch (error) {
      console.error('Error fetching meetings:', error);
      alert('Error fetching meetings data');
    } finally {
      setIsLoading(false);
    }
  };

  const handleReset = () => {
    setSearchQuery({ firstName: '', lastName: '', startDate: '', endDate: '', portfolio: '' });
    setMeetings([]);
    setCurrentPage(1);
  };

  const hasAnyInput =
    searchQuery.firstName ||
    searchQuery.lastName ||
    searchQuery.startDate ||
    searchQuery.endDate ||
    searchQuery.portfolio;

  return (
    <div className="page-wrapper">
      {/* Header row with title */}
      <div className="header-row">
        <h2>Web Of Influence Research</h2>
      </div>

      {/* Search MinisterMeetings + back button */}
      <div className="donations-header-row">
        <h2 className="donations-search-header">Minister Meetings</h2>
        <button onClick={handleBackToHome} className="home-button">
          ← Back to Home
        </button>
      </div>

      {/* Search Filters */}
      <div className="filter-card">
          <div className="filter-card__header-row">
              <div className="filter-card__header">Search Filters</div>

              {hasAnyInput && (
                <button
                  type="button"
                  className="small-reset-button"
                  onClick={handleReset}
                  aria-label="Reset filters"
                >
                  ↺ Reset
                </button>
              )}
            </div>

            {/* First Name */}
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

            {/* Last Name */}
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

            {/* Start Date */}
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

            {/* End Date */}
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

            {/* Portfolio */}
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

      {/* Search Meetings */}
      <div className="actions">
        <button
          type="button"
          className="action-button search-button"
          onClick={handleSearchSubmit}
          disabled={isLoading}
        >
          {isLoading ? 'Searching…' : '🔍 Search Meetings'}
        </button>
      </div>

      {meetings.length > 0 && (
        <>
          {/* Pagination ABOVE the table */}
          <div className="pagination-row">
            <ResponsivePagination
              current={currentPage}
              total={Math.ceil(meetings.length / itemsPerPage)}
              onPageChange={(page) => {
                setCurrentPage(page);
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
              maxWidth={600}
              previousLabel={currentPage > 1 ? "Previous" : ""}
              nextLabel="Next"
            />
          </div>

          {/* Table */}
          <MeetingsTable
            meetings={meetings.slice(
              (currentPage - 1) * itemsPerPage,
              currentPage * itemsPerPage
            )}
          />
        </>
      )}
      </div>
      );
      };

      export default MeetingsSearch;