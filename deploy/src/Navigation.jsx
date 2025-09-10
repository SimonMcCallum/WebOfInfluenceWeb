import React from 'react';
import { Link, useLocation } from 'react-router-dom';

const Navigation = () => {
  const location = useLocation();
  
  return (
    <nav className="bg-gray-800 text-white p-4">
      <div className="container mx-auto flex justify-between items-center">
        <div className="text-xl font-bold">WOI Database Website</div>
        <div className="space-x-4">
          <Link 
            to="/" 
            className={`hover:text-gray-300 ${location.pathname === '/' ? 'text-blue-400' : ''}`}
          >
            Home
          </Link>
          <Link 
            to="/candidate-overview" 
            className={`hover:text-gray-300 ${location.pathname === '/candidate-overview' ? 'text-blue-400' : ''}`}
          >
            Donations Overview
          </Link>
          <Link 
            to="/meetings" 
            className={`hover:text-gray-300 ${location.pathname === '/meetings' ? 'text-blue-400' : ''}`}
          >
            Ministerial Diaries
          </Link>
        </div>
      </div>
    </nav>
  );
};

export default Navigation;