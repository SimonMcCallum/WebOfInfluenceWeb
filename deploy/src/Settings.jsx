import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "./auth/AuthProvider";
import { sendPasswordResetEmail } from "firebase/auth";
import { auth } from "./firebase";
import './Settings.css';

export default function Settings() {
  const { user } = useAuth();
  const [msg, setMsg] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const navigate = useNavigate();
  const email = user?.email ?? "";
  const name = user?.displayName ?? "(no display name)";

  const sendReset = async () => {
    try {
      setIsLoading(true);
      setMsg("");
      await sendPasswordResetEmail(auth, email);
      setMsg("Password reset email sent. Check your inbox.");
    } catch (e) {
      setMsg(e.message || "Could not send reset email.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleBackToHome = () => navigate('/');

  return (
    <div className="settings-container">
      {/* Header Section */}
      <div className="settings-header">
        <div className="settings-header-content">
          <h1 className="settings-title">
            <span className="settings-icon">⚙️</span>
            Account Settings
          </h1>
          <button onClick={handleBackToHome} className="back-button">
            ← Back to Home
          </button>
        </div>
      </div>

      {/* Settings Content */}
      <div className="settings-content">
        {/* Profile Information Card */}
        <div className="settings-card">
          <div className="card-header">
            <div className="card-icon">👤</div>
            <h2 className="card-title">Profile Information</h2>
          </div>
          <p className="card-description">
            Your account details and personal information.
          </p>
          <div className="profile-info">
            <div className="info-item">
              <span className="info-label">Email Address</span>
              <span className="info-value">{email}</span>
            </div>
            <div className="info-item">
              <span className="info-label">Display Name</span>
              <span className="info-value">{name}</span>
            </div>
          </div>
        </div>

        {/* Password Management Card */}
        <div className="settings-card">
          <div className="card-header">
            <div className="card-icon">🔒</div>
            <h2 className="card-title">Password Management</h2>
          </div>
          <p className="card-description">
            Manage your account password and security settings.
          </p>
          <div className="password-section">
            <div className="password-info">
              <p>
                To change your password, we'll send a secure reset link to <strong>{email}</strong>
              </p>
            </div>
            <button 
              onClick={sendReset} 
              className="reset-button"
              disabled={isLoading}
            >
              {isLoading ? (
                <>
                  <span>⏳</span>
                  Sending...
                </>
              ) : (
                <>
                  <span>📧</span>
                  Send Password Reset Email
                </>
              )}
            </button>
            {msg && (
              <div className={`message ${msg.includes('sent') ? 'success' : 'error'}`}>
                {msg}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
