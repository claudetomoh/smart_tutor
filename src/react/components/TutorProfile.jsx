import FeedbackSection from './FeedbackSection';

export default function TutorProfile({ tutor, onBook, onClose }) {
  return (
    <div className="modal open" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
      <div className="modal-inner">
        <button className="modal-close" onClick={onClose} aria-label="Close profile modal">
          ✕
        </button>
        <h3 id="profile-modal-title">{tutor.name}</h3>
        <p className="muted">
          {tutor.subjects.join(' • ')} · {tutor.mode.map((mode) => mode[0].toUpperCase() + mode.slice(1)).join(', ')} · Based in {tutor.location}
        </p>
        <p>{tutor.bio}</p>
        <h4>Highlights</h4>
        <ul className="highlight-list">
          {tutor.highlights?.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
        <p>
          <strong>Availability:</strong> {tutor.availability.join(', ')}
        </p>
        <div className="card-actions">
          <button className="btn btn-primary" onClick={() => onBook(tutor)}>
            Book this Tutor
          </button>
          <button className="btn btn-outline" onClick={onClose}>
            Close
          </button>
        </div>
        
        <hr />
        <h4>Student Feedback</h4>
        <FeedbackSection tutorId={tutor.id} />
      </div>
    </div>
  );
}

