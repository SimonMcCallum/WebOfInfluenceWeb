import React, { useState } from "react";

const formatTime = (t) => {
  if (!t) return null;
  // Accept "HH:MM:SS" or "HH:MM"
  const m = String(t).match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  if (!m) return t; // fallback to raw if unexpected
  let h = parseInt(m[1], 10);
  const min = m[2];
  const ampm = h >= 12 ? "PM" : "AM";
  h = h % 12;
  if (h === 0) h = 12;
  return `${h}:${min} ${ampm}`;
};

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
    <div className="meetings-table-wrapper">
      <table className="meetings-table">
        <thead className="bg-gray-100">
          <tr>
            <th
              className="py-2 px-4 border cursor-pointer"
              onClick={handleSort}
            >
              Date
            </th>
            <th className="py-2 px-4 border">Minister</th>
            <th className="py-2 px-4 border">Start Time</th>
            <th className="py-2 px-4 border">End Time</th>
            <th className="py-2 px-4 border">Title</th>
            <th className="py-2 px-4 border">Type</th>
            <th className="py-2 px-4 border">Portfolio</th>
            <th className="py-2 px-4 border">Location</th>
            <th className="py-2 px-4 border">Attendees</th>
            <th className="py-2 px-4 border">Notes</th>
          </tr>
        </thead>
        <tbody>
          {sortedMeetings.map((meeting, index) => (
            <tr key={index} className="hover:bg-gray-50">
              <td className="py-2 px-4 border">
                {new Date(meeting.date).toLocaleDateString()}
              </td>
              <td className="py-2 px-4 border">
                {(() => {
                  const fn = meeting.minister_first_name || "";
                  const ln = meeting.minister_last_name || "";
                  const name = `${fn} ${ln}`.trim();
                  return name || "N/A";
                })()}
              </td>
              <td className="py-2 px-4 border">
                {formatTime(meeting.start_time) || "N/A"}
              </td>
              <td className="py-2 px-4 border">
                {formatTime(meeting.end_time) || "N/A"}
              </td>
              <td className="py-2 px-4 border">{meeting.title}</td>
              <td className="py-2 px-4 border">{meeting.type}</td>
              <td className="py-2 px-4 border">{meeting.portfolio || "N/A"}</td>
              <td className="py-2 px-4 border">{meeting.location || "N/A"}</td>
              <td className="py-2 px-4 border">
                {meeting.with_text ? String(meeting.with_text) : "N/A"}
              </td>
              <td className="py-2 px-4 border">{meeting.notes || "N/A"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default MeetingsTable;
