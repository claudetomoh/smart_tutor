const metrics = [
  { label: 'Upcoming sessions', value: 6 },
  { label: 'Active students', value: 14 },
  { label: 'Monthly earnings', value: '$820' },
];

const recentBookings = [
  { student: 'Amelia', subject: 'Mathematics', date: 'Oct 31, 2025', status: 'Confirmed' },
  { student: 'David', subject: 'Physics', date: 'Nov 02, 2025', status: 'Pending' },
  { student: 'Kwesi', subject: 'Calculus', date: 'Nov 05, 2025', status: 'Rescheduled' },
];

export default function Dashboard() {
  return (
    <section className="section" aria-labelledby="dashboard-title">
      <div className="container">
        <header className="section-header">
          <h2 id="dashboard-title" className="section-title">
            Tutor dashboard snapshot
          </h2>
          <p className="section-sub">
            Manage your schedule, earnings, and student communication in one glance.
          </p>
        </header>
        <div className="dashboard-grid">
          {metrics.map((metric) => (
            <div key={metric.label} className="dashboard-card">
              <p className="metric-value">{metric.value}</p>
              <p className="metric-label">{metric.label}</p>
            </div>
          ))}
        </div>
        <table className="dashboard-table">
          <caption className="sr-only">Recent booking requests</caption>
          <thead>
            <tr>
              <th scope="col">Student</th>
              <th scope="col">Subject</th>
              <th scope="col">Date</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            {recentBookings.map((booking) => (
              <tr key={`${booking.student}-${booking.date}`}>
                <td>{booking.student}</td>
                <td>{booking.subject}</td>
                <td>{booking.date}</td>
                <td>
                  <span className={`status-badge status-${booking.status.toLowerCase()}`}>
                    {booking.status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

