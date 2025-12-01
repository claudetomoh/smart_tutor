import TutorCard from './TutorCard.jsx';

export default function TutorDirectory({ tutors, onBook, onView }) {
  if (!tutors.length) {
    return (
      <p className="muted" role="status">
        No tutors match your filters yet. Adjust your search to explore more mentors.
      </p>
    );
  }

  return (
    <div className="tutor-grid">
      {tutors.map((tutor) => (
        <TutorCard key={tutor.id} tutor={tutor} onBook={onBook} onView={onView} />)
      )}
    </div>
  );
}

