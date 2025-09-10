import React from 'react';
import { Link } from 'react-router-dom';
import './Settings.css';

const Settings = () => {
  return (
    <div className="settings-container">
      <header className="settings-header">
        <div className="header-content">
          <Link to="/home" className="back-button">
            <span className="back-icon">←</span>
            Back to Home
          </Link>
          <h1 className="settings-title">Settings</h1>
        </div>
      </header>
      
      <div className="settings-content">
        {/* Settings content will be added here in the future */}
      </div>
    </div>
  );
};

export default Settings;
