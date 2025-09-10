import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';

// pages
import HomePage from './Hompage.jsx';
import DonationsOverview from './DonationsOverview.jsx';
import MeetingsSearch from './MeetingsSearch.jsx';
//import PersonProfile from './PersonProfile.jsx';
import LoginPage from './Loginpage.jsx'; 
import SettingsNav from './SettingsNav.jsx';
import AccSettings from './AccSettings.jsx';

// auth
import AuthProvider from './auth/AuthProvider';
import ProtectedRoute from './auth/ProtectedRoute';

createRoot(document.getElementById('root')).render(
  <AuthProvider>
    <Router basename="/webofinfluence">
      <Routes>
        {/* Public routes */}
        <Route path="/login" element={<LoginPage />} />
        
        {/* Protected routes - all pages require authentication */}
        <Route element={<ProtectedRoute />}>
          <Route path="/" element={<Navigate to="/home" replace />} />
          <Route path="/home" element={<HomePage />} />
          <Route path="/donations-overview" element={<DonationsOverview />} />
          <Route path="/meetings" element={<MeetingsSearch />} />
          {/*<Route path="/person/:firstName/:lastName" element={<PersonProfile />} /> */}
          <Route path="/settings" element={<SettingsNav />} />
          <Route path="/account-settings" element={<AccSettings />} />
        </Route>
      </Routes>
    </Router>
  </AuthProvider>
);
