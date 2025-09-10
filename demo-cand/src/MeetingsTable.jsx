import React, { useState } from "react";

const MeetingsTable = ({ meetings }) => {
  const [sortOrder, setSortOrder] = useState("asc");

  // Function to sort by date
  const handleSort = () => {
    setSortOrder((prevOrder) => (prevOrder === "asc" ? "desc" : "asc"));
  };

  // Sort the meetings based on date
  const sortedMeetings = [...meetings].sort((a, b) => {
    const dateA = new Date(a.date);
    const dateB = new Date(b.date);

    if (sortOrder === "asc") {
      return dateA - dateB; // Ascending order
    } else {
      return dateB - dateA; // Descending order
    }
  });

  return (
    <div className="mt-6 overflow-x-auto">
      <table className="min-w-full bg-white border border-gray-300">
        <thead className="bg-gray-100">
          <tr>
            <th
              className="py-2 px-4 border cursor-pointer"
              onClick={handleSort}
            >
              Date
            </th>
            <th className="py-2 px-4 border">Start Time</th>
            <th className="py-2 px-4 border">End Time</th>
            <th className="py-2 px-4 border">Title</th>
            <th className="py-2 px-4 border">Type</th>
            <th className="py-2 px-4 border">Portfolio</th>
            <th className="py-2 px-4 border">Location</th>
            <th className="py-2 px-4 border">Notes</th>
            <th className="py-2 px-4 border">Attendees</th>
          </tr>
        </thead>
        <tbody>
          {sortedMeetings.map((meeting, index) => (
            <tr key={index} className="hover:bg-gray-50">
              <td className="py-2 px-4 border">
                {new Date(meeting.date).toLocaleDateString()}
              </td>
              <td className="py-2 px-4 border">{meeting.start_time || "N/A"}</td>
              <td className="py-2 px-4 border">{meeting.end_time || "N/A"}</td>
              <td className="py-2 px-4 border">{meeting.title}</td>
              <td className="py-2 px-4 border">{meeting.type}</td>
              <td className="py-2 px-4 border">{meeting.portfolio || "N/A"}</td>
              <td className="py-2 px-4 border">{meeting.location || "N/A"}</td>
              <td className="py-2 px-4 border">{meeting.notes || "N/A"}</td>
              <td className="py-2 px-4 border">
                {Array.isArray(meeting.attendees)
                  ? meeting.attendees.join(", ")
                  : meeting.attendees || "N/A"}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default MeetingsTable;
