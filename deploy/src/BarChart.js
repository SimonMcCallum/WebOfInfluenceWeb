import React, {useRef, useEffect, useState} from 'react';
import * as d3 from 'd3';

const BarChart = () => {
    const [data, setData] = useState([]);

    //Ref to hold the SVG element
    const svgRef = useRef(null);

    useEffect(() => {
        if (data.length === 0) return;  // データがなかったらグラフを見せない
    
        // Set up margins and dimensions for the chart
        const margin = { top: 40, right: 30, bottom: 100, left: 150 };
        const width = 800 - margin.left - margin.right;
        const height = 500 - margin.top - margin.bottom;
    
        // Select the SVG element and append a group element for the chart
        const svg = d3.select(svgRef.current)
          .attr("width", width + margin.left + margin.right)
          .attr("height", height + margin.top + margin.bottom)
          .append("g")
          .attr("transform", `translate(${margin.left},${margin.top})`);
    
        // Clean the data and parse donations as numbers
        data.forEach(d => {
          d.TotalDonationsACD = +d.TotalDonationsACD;  // Ensure donations are numbers
          d.CandidateName = d.CandidateName;  // Candidate name
          d.Party = d.Party;  // Party name
        });
    
        // Sort the data by donations (descending)
        data.sort((a, b) => b.TotalDonationsACD - a.TotalDonationsACD);
    
        // Define scales
        const xScale = d3.scaleLinear()
          .domain([0, d3.max(data, d => d.TotalDonationsACD)])  // Max donation value
          .range([0, width]);  // Width of the chart
    
        const yScale = d3.scaleBand()
          .domain(data.map(d => d.CandidateName))  // List of candidate names
          .range([0, height])  // Chart height based on the number of bars
          .padding(0.1);  // Spacing between bars
    
        // Color scale (optional: color bars based on party)
        const partyColorScale = {
          "National Party": "#00529F",
          "Labour Party": "#D82A20",
          "ACT": "FDE401",
          "Greens": "#098137",
          "Te Pati Maori": "#6A1D2C",
          "NZ First": "#000000",
          "default": "#BEBEBE"  // Default color for other parties
        };
    
        // Create bars
        svg.selectAll(".bar")
          .data(data)
          .enter()
          .append("rect")
          .attr("class", "bar")
          .attr("x", 0)
          .attr("y", d => yScale(d.CandidateName))
          .attr("width", d => xScale(d.TotalDonationsACD))
          .attr("height", yScale.bandwidth())
          .attr("fill", d => partyColorScale[d.Party] || partyColorScale["default"]);
    
        // Add donation labels to the bars
        svg.selectAll(".label")
          .data(data)
          .enter()
          .append("text")
          .attr("class", "label")
          .attr("x", d => xScale(d.TotalDonationsACD) + 10)
          .attr("y", d => yScale(d.CandidateName) + yScale.bandwidth() / 2)
          .attr("dy", ".35em")
          .text(d => `$${d.TotalDonationsACD.toLocaleString()}`)
          .style("font-family", "Arial")
          .style("font-size", "12px")
          .style("fill", "black");
    
        // Add the candidate names on the left side
        svg.selectAll(".name")
          .data(data)
          .enter()
          .append("text")
          .attr("class", "name")
          .attr("x", -10)  // Position the name label slightly left of the bars
          .attr("y", d => yScale(d.CandidateName) + yScale.bandwidth() / 2)
          .attr("dy", ".35em")
          .text(d => d.CandidateName)
          .style("font-family", "Arial")
          .style("font-size", "12px")
          .style("fill", "black");
    
        // Create the X-Axis at the bottom
        svg.append("g")
          .attr("transform", `translate(0,${height})`)
          .call(d3.axisBottom(xScale));
    
        // Create the Y-Axis on the left side
        svg.append("g")
          .call(d3.axisLeft(yScale));
      }, [data]);  // Only re-run when `data` changes
    
      return (
        <svg ref={svgRef}></svg>  // Render the SVG element
      );
    };
    
    export default BarChart;    