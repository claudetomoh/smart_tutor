export default function TutorCard({ tutor, onBook, onView }) {
  return (
    <article className="tutor-card" tabIndex={0}>
      <img src={tutor.photo} alt={tutor.name} className="tutor-photo" />
      <div className="tutor-body">
        <h3>{tutor.name}</h3>
        <p className="muted">
          {tutor.subjects.join(' • ')} · ${tutor.price}/hr · ★ {tutor.rating.toFixed(1)}
        </p>
        <p>{tutor.bio}</p>
        {tutor.highlights?.length ? (
          <ul className="highlight-list">
            {tutor.highlights.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        ) : null}
        <p className="muted">
          {tutor.location} • {tutor.mode.map((mode) => mode[0].toUpperCase() + mode.slice(1)).join(', ')}
        </p>
        <div className="card-actions">
          <button className="btn btn-primary" onClick={() => onBook(tutor)}>
            Book
          </button>
          <button className="btn btn-outline" onClick={() => onView(tutor)}>
            View Profile
          </button>
        </div>
      </div>
    </article>
  );
}

