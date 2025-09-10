import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import HomePage from './Homepage'; 
import App from './App.jsx'; 
import CandidateOverview from './CandidateOverview.jsx';
import MeetingsSearch from './MeetingsSearch.jsx';
import PersonProfile from './PersonProfile.jsx';
import LoginPage from './Loginpage.jsx'; 

createRoot(document.getElementById('root')).render(
  <Router basename={import.meta.env.BASE_URL.replace(/\/$/, '')}>
    <Routes>
      <Route path="/" element={<LoginPage />} />
      <Route path="/home" element={<HomePage />} />
      <Route path="/candidate-overview" element={<CandidateOverview />} />
      <Route path="/meetings" element={<MeetingsSearch />} />
      <Route path="/person/:firstName/:lastName" element={<PersonProfile />} />
    </Routes>
  </Router>
);
