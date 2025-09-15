import { useNavigate } from "react-router-dom";
import './SettingsNav.css';

export default function SettingsNav() {
  const navigate = useNavigate();

  const handleBackToHome = () => navigate('/');
  const handleAccountSettings = () => navigate('/account-settings');
  const handleAdminPage = () => {
    const newWindow = window.open('/webofinfluence/src/dashboard.html', '_blank');
    if (newWindow) {
      newWindow.focus();
    } else {
      alert('Popup blocked! Please allow popups for this site.');
    }
  };

  return (
    <div className="settings-container">
      {/* Header Section */}
      <div className="settings-header">
        <div className="settings-header-content">
          <h1 className="settings-title">
            <span className="settings-icon">⚙️</span>
            Settings
          </h1>
          <button onClick={handleBackToHome} className="back-button">
            ← Back to Home
          </button>
        </div>
      </div>

      {/* Settings Navigation Content */}
      <div className="settings-content">
        {/* Account Settings Card */}
        <div className="settings-card settings-nav-card" onClick={handleAccountSettings}>
          <div className="card-header">
            <div className="card-icon">👤</div>
            <h2 className="card-title">Account Settings</h2>
          </div>
          <p className="card-description">
            Manage your profile information, password, and personal account settings.
          </p>
          <div className="nav-arrow">→</div>
        </div>

        {/* Admin Page Card */}
        <div className="settings-card settings-nav-card" onClick={handleAdminPage}>
          <div className="card-header">
            <div className="card-icon">🛠️</div>
            <h2 className="card-title">Admin Page</h2>
          </div>
          <p className="card-description">
            Access administrative functions and system management tools.
          </p>
          <div className="nav-arrow">→</div>
        </div>
      </div>
    </div>
  );
}
