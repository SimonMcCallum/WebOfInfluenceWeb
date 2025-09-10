import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from './auth/AuthProvider';
import './HomePage.css';


const HomePage = () => {
  //const [firstName, setFirstName] = useState('');
  //const [lastName, setLastName] = useState('');
  const navigate = useNavigate();
  const { logout } = useAuth();

  //Handld logout functionality
  const handleLogout = async () => {
    try {
      await logout();
      navigate('/login', { replace: true });
    } catch (e) {
      console.error(e);
      alert('Failed to log out, please try again.');
    }
  };

  return (
    <div className="container">
      {/* Header with Navigation */}
      <header className="header">
        <div className="header-content">
          <h1 className="title">Web of Influence Research Homepage</h1>
          <nav className="header-nav">
            <Link to="/settings" className="nav-button settings-button">
              <span className="button-icon">⚙️</span>
              Settings
            </Link>
            <button onClick={handleLogout} className="nav-button logout-button">
              <span className="button-icon">🚪</span>
              Logout
            </button>
          </nav>
        </div>
      </header>

      {/* Main Navigation Cards */}
      <div className="main-navigation">
        <h2 className="navigation-title">Explore the Data</h2>
        <div className="navigation-cards">
          <Link to="/candidate-overview" className="nav-card donations-card">
            <div className="card-icon">💰</div>
            <div className="card-content">
              <h3 className="card-title">Donations Overview</h3>
              <p className="card-description">
                Analyze political donation patterns across multiple election cycles. 
                Search by candidate, donor, or amount with interactive visualizations.
              </p>
              <div className="card-features">
                <span className="feature-tag">2011-2023 Data</span>
                <span className="feature-tag">Interactive Charts</span>
                <span className="feature-tag">Advanced Search</span>
              </div>
            </div>
            <div className="card-arrow">→</div>
          </Link>

          <Link to="/meetings" className="nav-card meetings-card">
            <div className="card-icon">📅</div>
            <div className="card-content">
              <h3 className="card-title">Ministerial Meetings</h3>
              <p className="card-description">
                Access comprehensive ministerial diary records and meeting logs. 
                Track government interactions and decision-making processes.
              </p>
              <div className="card-features">
                <span className="feature-tag">Meeting Records</span>
                <span className="feature-tag">Diary Entries</span>
                <span className="feature-tag">Search & Filter</span>
              </div>
            </div>
            <div className="card-arrow">→</div>
          </Link>
        </div>
      </div>

      {/* About section */}
      <div className="about-section">
        <h2 className="about-title">About Web of Influence</h2>
        
        <div className="about-content">
          <div className="about-intro">
            <p className="about-description">
              The Web of Influence is a comprehensive research platform designed to promote transparency and accountability in New Zealand politics by providing public access to political donation data and ministerial meeting records.
            </p>
          </div>

          <div className="about-features">
            <h3 className="feature-title">Key Features</h3>
            <div className="features-grid">
              <div className="feature-item">
                <h4 className="feature-name">Political Donations Analysis</h4>
                <p className="feature-description">
                  Explore detailed donation records from multiple election cycles (2011, 2014, 2017, 2020, 2023) with interactive visualizations and comprehensive search capabilities.
                </p>
              </div>
              <div className="feature-item">
                <h4 className="feature-name">Ministerial Meetings Database</h4>
                <p className="feature-description">
                  Access detailed records of ministerial diaries and meeting logs, providing insights into government interactions and decision-making processes.
                </p>
              </div>
            </div>
          </div>

          <div className="about-mission">
            <h3 className="feature-title">Our Goal</h3>
            <p className="about-description">
              We believe that transparency in political processes is fundamental to a healthy democracy. By making this information easily accessible and searchable, we aim to empower citizens, researchers, and journalists to better understand the relationships and influences that shape New Zealand's political landscape.
            </p>
          </div>

          <div className="about-usage">
            <h3 className="feature-title">Getting Started</h3>
            <p className="about-description">
              Use the navigation options above to explore donation data or ministerial meetings. Each section provides powerful search and filtering tools to help you find specific information or identify patterns in the data.
            </p>
          </div>
        </div>
      </div>


      {/* 
      <div className="search-section">
        <input
          type="text"
          placeholder="Enter First Name"
          value={firstName}
          onChange={(e) => setFirstName(e.target.value)}
          className="search-input"
        />
        <input
          type="text"
          placeholder="Enter Last Name"
          value={lastName}
          onChange={(e) => setLastName(e.target.value)}
          className="search-input"
        />
        <button onClick={handleSearchSubmit} className="search-button">
          Search Profile
        </button>
      </div> */}
    </div>
  );
};

export default HomePage;
