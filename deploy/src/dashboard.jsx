import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import './dashboard.css';

export default function AdminDashboard() {
  const [config, setConfig] = useState({});
  const [apiStatus, setApiStatus] = useState('Testing API connection...');

  useEffect(() => {
    // Display configuration
    setConfig(window.__APP_CONFIG__ || {});

    // API health test
    const testApiHealth = async () => {
      try {
        const base = (window.__APP_CONFIG__ && window.__APP_CONFIG__.API_BASE) || '/webofinfluence/api';
        const response = await fetch(base + '/', { cache: 'no-store' });
        const text = await response.text();
        setApiStatus('✅ API Health: ' + text);
      } catch (error) {
        setApiStatus('⚠️ API Health fetch failed: ' + error.message);
      }
    };

    testApiHealth();
  }, []);

  return (
    <div className="container">
      <div className="header">
        <h1>🛠️ Admin Dashboard</h1>
        <p className="subtitle">Web Of Influence - System Management</p>
      </div>

      <div style={{textAlign: 'right', marginBottom: '1rem'}}>
        <Link to="/settings" className="back-button">
          <span>← Back to Settings</span>
        </Link>
      </div>

      <div className="status ok">
        <div className="status-text">✅ Server deployment is active</div>
        <p>System is running and ready for administration tasks.</p>
      </div>

      <div className="dashboard-grid">
        {/* API Section */}
        <div className="dashboard-card">
          <div className="card-header">
            <div className="card-icon">🔌</div>
            <h2 className="card-title">API Management</h2>
          </div>
          <div className="button-group">
            <div className="button-item">
              <a href="/webofinfluence/api/APITest.html" className="dashboard-button" target="_blank" rel="noopener">
                <span>API Health Check</span>
                <span className="button-arrow">→</span>
              </a>
              <p className="button-description">Test the API server status and verify that all endpoints are responding correctly.</p>
            </div>
          </div>
        </div>

        {/* Analytics Section */}
        <div className="dashboard-card">
          <div className="card-header">
            <div className="card-icon">📊</div>
            <h2 className="card-title">Analytics Dashboard</h2>
          </div>
          <div className="button-group">
            <div className="button-item">
              <a href="/webofinfluence/analytics/analytics.html" className="dashboard-button" target="_blank" rel="noopener">
                <span>Open Analytics (D3)</span>
                <span className="button-arrow">→</span>
              </a>
              <p className="button-description">Access interactive data visualizations and charts powered by D3.js for comprehensive data analysis.</p>
            </div>
          </div>
        </div>

        {/* AI Name Finder Section */}
        <div className="dashboard-card">
          <div className="card-header">
            <div className="card-icon">🤖</div>
            <h2 className="card-title">AI Name Finder</h2>
          </div>
          <div className="button-group">
            <div className="button-item">
              <a href="/webofinfluence/src/ai-name-finder.html" className="dashboard-button" target="_blank" rel="noopener">
                <span>Find Names with AI</span>
                <span className="button-arrow">→</span>
              </a>
              <p className="button-description">Upload a file and let AI extract person names for you</p>
            </div>
          </div>
        </div>
      </div>

      {/* Frontend Status */}
      <div className="status warn">
        <div className="status-text">⚠️ Frontend Build Status</div>
        <p>The React app has not been built on this server because Node/npm is not available. You have two options:</p>
        <ol style={{marginTop: '1rem', paddingLeft: '1.5rem'}}>
          <li>Install Node/npm on the cPanel build environment; the pipeline will run <code style={{background: 'rgba(0,0,0,0.1)', padding: '0.2rem 0.4rem', borderRadius: '3px'}}>npm run build</code> automatically.</li>
          <li>Build locally and commit the built assets in <code style={{background: 'rgba(0,0,0,0.1)', padding: '0.2rem 0.4rem', borderRadius: '3px'}}>demo-cand/dist</code> to this repository.</li>
        </ol>
      </div>

      {/* Runtime Config */}
      <div className="config-section">
        <h3 className="config-title">🔧 Runtime Configuration</h3>
        <p style={{marginBottom: '1rem', color: '#6b7280'}}>Current API_BASE computed in the browser:</p>
        <div className="config-code">
          <pre>{JSON.stringify(config, null, 2)}</pre>
        </div>
        <div className="api-status" style={{color: apiStatus.includes('✅') ? '#10b981' : apiStatus.includes('⚠️') ? '#f59e0b' : '#ef4444'}}>
          {apiStatus}
        </div>
      </div>
    </div>
  );
}
