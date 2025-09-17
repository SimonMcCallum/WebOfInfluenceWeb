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
/**
 * Simple in-memory caches to avoid repeated lookups and reduce network errors
 */
const personCache = new Map();
const partyCache = new Map();

/**
 * Helpers to normalize party names to our canonical color keys
 * - Uppercase
 * - Strip diacritics (e.g., Pāti → PATI)
 * - Map common variants (e.g., ACT NEW ZEALAND → ACT)
 */
const stripDiacritics = (s) => (s ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : '');
const normalizePartyName = (name) => {
  const raw = (name || '').trim();
  const upper = stripDiacritics(raw).toUpperCase();
  if (upper.includes('ACT')) return 'ACT';
  if (upper.includes('NATIONAL')) return 'NATIONAL PARTY';
  if (upper.includes('LABOUR')) return 'LABOUR PARTY';
  if (upper.includes('GREEN')) return 'GREEN PARTY';
  if (upper.includes('TE PATI MAORI')) return 'TE PATI MAORI'; // diacritics stripped above
  if (upper.includes('NEW ZEALAND FIRST')) return 'NEW ZEALAND FIRST PARTY';
  return upper;
};

const BarChart = ({ results, isLoading }) => {  
  const [chartData, setChartData] = useState(null);

  const fetchCandidateInfo = async (result) => {
    try {
      const { people_id, party_id } = result || {};
      const year = result?.election_year ?? result?.year ?? 'Unknown';

      // Prefer inline fields returned by combined API to avoid extra lookups
      let first = (result?.first_name || '').trim() || 'Unknown';
      let last = (result?.last_name || '').trim() || 'Unknown';
      let partyName = (result?.party_name || '').trim() || 'Unknown';

      // If inline names weren't provided, do people lookup with cache
      if ((first === 'Unknown' && last === 'Unknown') && people_id) {
        if (personCache.has(people_id)) {
          [first, last] = personCache.get(people_id);
        } else {
          const res = await fetch(`${API_BASE}?route=/candidates/search-id&people_id=${encodeURIComponent(people_id)}`);
          if (res.ok) {
            const data = await res.json();
            const obj = Array.isArray(data) && data.length ? data[0] : (data || {});
            first = obj?.first_name || 'Unknown';
            last = obj?.last_name || 'Unknown';
            personCache.set(people_id, [first, last]);
          }
        }
      }

      // If inline party wasn't provided, lookup with cache
      if (partyName === 'Unknown' && party_id) {
        if (partyCache.has(party_id)) {
          partyName = partyCache.get(party_id);
        } else {
          const res2 = await fetch(`${API_BASE}?route=/party/search-id&party_id=${encodeURIComponent(party_id)}`);
          if (res2.ok) {
            const data2 = await res2.json();
            const obj2 = Array.isArray(data2) && data2.length ? data2[0] : (data2 || {});
            partyName = obj2?.party_name || obj2?.name || 'Unknown';
            partyCache.set(party_id, partyName);
          }
        }
      }

      // Normalize and only keep known parties for legend coloring; unknowns show as grey but no legend chip
      const normalized = normalizePartyName(partyName);
      const displayParty = Object.prototype.hasOwnProperty.call(partyColors, normalized)
        ? normalized
        : 'Unknown';

      return {
        name: [first, last].filter(Boolean).join(' ') || 'Unknown',
        party: displayParty,
        real_party: partyName || 'Unknown',
        year: year
      };
    } catch (error) {
      console.error('Error fetching candidate info:', error);
      return { name: 'Unknown', party: 'Unknown', real_party: 'Unknown', year: result?.election_year ?? result?.year ?? 'Unknown' };
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
          sortedResults.map(result => fetchCandidateInfo(result))
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
            // Normalize to uppercase to match partyColors keys
            // Only include known parties in the legend; hide 'Unknown/Other' buckets
            const uniqueNormalized = Array.from(
              new Set((chartData.parties || []).map(p => normalizePartyName(p)))
            ).filter(n => Object.prototype.hasOwnProperty.call(partyColors, n) && n !== 'Unknown');

            return uniqueNormalized.map((normalized) => {
              const color = partyColors[normalized];
              const labelText = normalized.replace(/\s*PARTY$/, '');
              return {
                text: labelText,
                fillStyle: color,
                strokeStyle: color,
                hidden: false,
              };
            });
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
