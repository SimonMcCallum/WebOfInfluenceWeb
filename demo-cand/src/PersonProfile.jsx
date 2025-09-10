import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import './PersonProfile.css';
import { API_BASE } from './apiConfig';

const PersonProfile = () => {
  const { firstName, lastName } = useParams();
  const [profileData, setProfileData] = useState(null);
  const [donations, setDonations] = useState([]);
  const [meetings, setMeetings] = useState([]);
  const [selectedYear, setSelectedYear] = useState('2023');
  const [isLoading, setIsLoading] = useState(true);
  const [sortConfig, setSortConfig] = useState({
    key: 'date',
    direction: 'asc' 
  });

  useEffect(() => {
    const fetchProfileData = async () => {
      setIsLoading(true);
      try {
        const personResponse = await fetch(
          `${API_BASE}/candidates/search?first_name=${firstName}&last_name=${lastName}`
        );
        const personData = await personResponse.json();

        const donationsResponse = await fetch(
          `${API_BASE}/donations/${selectedYear}?first_name=${firstName}&last_name=${lastName}`
        );
        const donationsData = await donationsResponse.json();

        const donationsWithDonors = [];

        for (const donation of donationsData) {
          try {
            const donorResponse = await fetch(
              `${API_BASE}/donor/search-id?donor_id=${donation.donor_id}`
            );
            const donorData = await donorResponse.json();

            donationsWithDonors.push({
              ...donation,
              donor: donorData[0] 
            });
          } catch (error) {
            console.error(`Error fetching donor info for donation ${donation.donor_id}:`, error);
            donationsWithDonors.push({
              ...donation,
              donor: null
            });
          }
        }

        setDonations(donationsWithDonors);

        // Fetch meetings
        const meetingsResponse = await fetch(
          `${API_BASE}/ministerial_diaries/search-cand?first_name=${firstName}&last_name=${lastName}`
        );
        const meetingsData = await meetingsResponse.json();

        setProfileData(personData[0]);
        setMeetings(meetingsData);
      } catch (error) {
        console.error('Error fetching profile data:', error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchProfileData();
  }, [firstName, lastName, selectedYear]);

  const sortTable = (key) => {
    let direction = 'asc';
    if (sortConfig.key === key && sortConfig.direction === 'asc') {
      direction = 'desc';
    }

    setSortConfig({ key, direction });
  };

  const sortedDonations = donations.sort((a, b) => {
    if (sortConfig.key === 'date') {
      return sortConfig.direction === 'asc'
        ? new Date(a.date) - new Date(b.date)
        : new Date(b.date) - new Date(a.date);
    }
    if (sortConfig.key === 'amount') {
      return sortConfig.direction === 'asc' ? a.amount - b.amount : b.amount - a.amount;
    }
    return 0;
  });

  const sortedMeetings = meetings.sort((a, b) => {
    if (sortConfig.key === 'date') {
      return sortConfig.direction === 'asc'
        ? new Date(a.date) - new Date(b.date)
        : new Date(b.date) - new Date(a.date);
    }
    return 0;
  });

  if (isLoading) return <div className="text-center mt-8">Loading...</div>;
  if (!profileData) return <div className="text-center mt-8">Person not found</div>;

  return (
    <div className="container mx-auto p-4">
      <div className="bg-white shadow-lg rounded-lg p-6 mb-6">
        <h1 className="text-2xl font-bold mb-4">
          {profileData.first_name} {profileData.last_name}
        </h1>

        <div className="mb-6">
          <h2 className="text-xl font-semibold mb-2">Donation History</h2>
          <select 
            value={selectedYear} 
            onChange={(e) => setSelectedYear(e.target.value)}
            className="mb-4 p-2 border rounded"
          >
            <option value="2023">2023</option>
            <option value="2017">2017</option>
            <option value="2014">2014</option>
            <option value="2011">2011</option>
          </select>

          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-gray-100">
                <tr>
                  <th 
                    className="py-2 px-4 border cursor-pointer" 
                    onClick={() => sortTable('date')}
                  >
                    Date
                  </th>
                  <th 
                    className="py-2 px-4 border cursor-pointer" 
                    onClick={() => sortTable('amount')}
                  >
                    Amount
                  </th>
                  <th className="py-2 px-4 border">Donor(s) First</th>
                  <th className="py-2 px-4 border">Donor(s) Last</th>
                </tr>
              </thead>
              <tbody>
                {sortedDonations.map((donation, index) => (
                  <tr key={index} className="hover:bg-gray-50">
                    <td className="py-2 px-4 border">{new Date(donation.date).toLocaleDateString()}</td>
                    <td className="py-2 px-4 border">${donation.amount}</td>
                    <td className="py-2 px-4 border">
                      {donation.donor ? donation.donor.first_name.split('#').join(', ') : 'Unknown'}
                    </td>
                    <td className="py-2 px-4 border">
                      {donation.donor ? [...new Set(donation.donor.last_name.split('#'))].join(', ') : 'Unknown'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div>
          <h2 className="text-xl font-semibold mb-2">Meeting History</h2>
          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-gray-100">
                <tr>
                  <th 
                    className="py-2 px-4 border cursor-pointer" 
                    onClick={() => sortTable('date')}
                  >
                    Date
                  </th>
                  <th className="py-2 px-4 border">Time</th>
                  <th className="py-2 px-4 border">Location</th>
                  <th className="py-2 px-4 border">Title</th>
                </tr>
              </thead>
              <tbody>
                {sortedMeetings.map((meeting, index) => (
                  <tr key={index} className="hover:bg-gray-50">
                    <td className="py-2 px-4 border">{new Date(meeting.date).toLocaleDateString()}</td>
                    <td className="py-2 px-4 border">{meeting.start_time}</td>
                    <td className="py-2 px-4 border">{meeting.location || 'N/A'}</td>
                    <td className="py-2 px-4 border">{meeting.title || "None"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PersonProfile;
