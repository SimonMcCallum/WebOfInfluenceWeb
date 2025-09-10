import React, { useEffect, useState } from 'react';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend, elements } from 'chart.js';
import { API_BASE } from './apiConfig';

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
);

const partyColors = {
  "NATIONAL PARTY": "rgb(0, 82, 159)", // #00529F
  "LABOUR PARTY": "rgb(216, 42, 32)", // #D82A20
  "ACT": "rgb(253, 228, 1)", // #FDE401
  "GREEN PARTY": "rgb(9, 129, 55)", // #098137
  "TE PATI MAORI": "rgb(106, 29, 44)", // #6A1D2C
  "NEW ZEALAND FIRST PARTY": "rgb(0, 0, 0)", // #000000
  "Unknown": "rgb(190, 190, 190)" // #BEBEBE
};
const BarChart = ({ results, isLoading }) => {  
  const [chartData, setChartData] = useState(null);

  const fetchCandidateInfo = async (people_id, party_id, year) => {
    try {
      const response = await fetch(`${API_BASE}/candidates/search-id?people_id=${people_id}`);
      const data = await response.json();
      const response2 = await fetch(`${API_BASE}/party/search-id?party_id=${party_id}`);
      const data2 = await response2.json();
      const party_n = data2[0]?.party_name || 'Unknown';
      return {
        name: [data[0]?.first_name, data[0]?.last_name].filter(Boolean).join(' ') || 'Unknown',
        party: Object.keys(partyColors).includes(party_n)? party_n : 'Other',
        real_party:party_n,
        year: year
      };
    } catch (error) {
      console.error('Error fetching candidate info:', error);
      return { name: 'Unknown fetch', party: 'Unknown fetch', year: 'Unknown fetch' };
    }
  };

  const getPartyColor = (party) => {
    const normalizedParty = party?.trim().toUpperCase();
    for (const [key, value] of Object.entries(partyColors)) {
      if (key.toUpperCase() === normalizedParty) {
        return value;
      }
    }
    return partyColors.Unknown;
  };

  useEffect(() => {
    // If results are empty, clear the chart
    if (!results || results.length === 0) {
      setChartData(null);
      return;
    }

    const fetchData = async () => {
      if (results && results.length > 0) {
        // Sort results by total_donations in descending order
        const sortedResults = [...results].sort((a, b) => (b.total_donations || 0) - (a.total_donations || 0));

        // Fetch candidate info for each result
        const candidateInfo = await Promise.all(
          sortedResults.map(result =>
            fetchCandidateInfo(result.people_id, result.party_id, result.election_year)
          )
        );

        // Prepare chart data
        const labels = candidateInfo.map(info => `${info.name} (${info.year})`);
        const donations = sortedResults.map(result => Number(result.total_donations ?? 0));
        const backgroundColor = candidateInfo.map(info => getPartyColor(info.party));
        
        setChartData({
          labels: labels,
          datasets: [
            {
              label: 'Total Donations',
              data: donations,
              backgroundColor: backgroundColor,
              borderColor: backgroundColor,
              borderWidth: 1,
              r_party: candidateInfo.map(info => info.real_party),
            },
          ],
          parties: candidateInfo.map(info => info.party), // Save parties for legend
        });
      }
    };

    fetchData();
  }, [results]);

  // ----- Render guards -----
  if (results === null) return null;
  if (isLoading) return <p>Loading chart...</p>;
  if (!Array.isArray(results) || results.length === 0) return <p>No data to display</p>;
  if (!chartData) return null; 

  // ----- Chart configuration -----
  const count = results.length;
  // Dynamically calculate the height of the chart container
  const containerHeight = Math.min(Math.max(400, count * 60), 2000);
  // Calculate bar thickness based on number of candidates to display
  const barThickness = Math.max(15, 100 / count);

  // Chart options
  const options = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: {
      title: {
        display: true,
        text: 'Total Donations per Candidate',
        font: { size: 18 },
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            const totalDonations = Number(context.raw ?? 0);
            const partyName = context.dataset.r_party[context.dataIndex]; 
            return [
              `Total Donations: $${totalDonations.toLocaleString()}`,
              `Party: ${partyName}`,
            ];
          },
        },
      },
      
      legend: {
        display: true,
        position: 'bottom',
        title: {
          display: true,
          text: 'Parties', 
          font: {
            size: 11, 
            weight: 'bold', 
          },
        },
        labels: {
          generateLabels: function(chart) {
            const parties = [...new Set(chartData.parties)];
            
            return parties.map((party) => ({
              text: Object.keys(partyColors).includes(party) ? party.replace(/PARTY/i, '') : 'OTHER',
              fillStyle: Object.keys(partyColors).includes(party) ? getPartyColor(party) : partyColors.Unknown,
              strokeStyle: Object.keys(partyColors).includes(party) ? getPartyColor(party) : partyColors.Unknown,
              hidden: false,
            }));
          },
        },
        
      },
    },
    scales: {
      x: {
        title: {
          display: true,
          text: 'Donations ($)',
        },
        ticks: {
          callback: function(value) {
            return `$${value.toLocaleString()}`;
          },
        },
      },
      y: {
        title: {
          display: true,
          text: 'Candidates',
        },
        ticks: {
          maxRotation: 0,
          autoSkip: true,
        },
      },
    },
    elements: {
      bar: {
        barThickness: barThickness,
      },
    },
  };

  return (
    <div className="chart-container w-full" style={{ height: `${containerHeight}px` }}>
      <Bar data={chartData} options={options} />
    </div>
  );
};

export default BarChart;
