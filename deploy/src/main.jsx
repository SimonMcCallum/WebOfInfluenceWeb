import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';

// pages
import HomePage from './Homepage.jsx'; 
//import CandidateOverview from './CandidateOverview.jsx';
//import MeetingsSearch from './MeetingsSearch.jsx';
//import PersonProfile from './PersonProfile.jsx';
import LoginPage from './LoginPage.jsx'; 
//import Settings from './Settings.jsx';

// auth
import AuthProvider from './auth/AuthProvider';
import ProtectedRoute from './auth/ProtectedRoute';

createRoot(document.getElementById('root')).render(
  <AuthProvider>
    <Router basename="/webofinfluence">
      <Routes>
        {/* Default route - always shows login page first */}
        <Route path="/" element={<LoginPage />} />
        <Route path="/login" element={<LoginPage />} />
        
        {/* Protected routes - all pages require authentication */}
        <Route element={<ProtectedRoute />}>
          <Route path="/home" element={<HomePage />} />
          {/* <Route path="/candidate-overview" element={<CandidateOverview />} />
          <Route path="/meetings" element={<MeetingsSearch />} />
          <Route path="/person/:firstName/:lastName" element={<PersonProfile />} /> */}
          <Route path="/settings" element={<Settings />} />
        </Route>
      </Routes>
    </Router>
  </AuthProvider>
);
