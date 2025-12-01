import { useEffect, useState } from 'react';
import FeedbackModal from './FeedbackModal';

export default function BookingModal({ tutor, onClose, onConfirm, session }) {
  const [showFeedback, setShowFeedback] = useState(false);

  useEffect(() => {
    const handleKey = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [onClose]);

  // If this is a completed session, show feedback form
  if (session?.status === 'completed' && !session.feedback) {
    return (
      <FeedbackModal
        session={session}
        onClose={onClose}
        onSubmit={async (feedback) => {
          try {
            const response = await fetch('/api/feedback.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                userId: tutor.id,
                authorId: session.studentId,
                rating: feedback.rating,
                comment: feedback.comment,
                sessionId: session.id
              })
            });
            
            if (!response.ok) throw new Error('Failed to submit feedback');
            
            const data = await response.json();
            onConfirm({ type: 'feedback', data });
          } catch (error) {
            console.error('Error submitting feedback:', error);
            throw error;
          }
        }}
      />
    );
  }

  const handleSubmit = (event) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const datetime = form.get('datetime');
    const notes = form.get('notes');
    if (!datetime || !notes) return;
    onConfirm({ type: 'booking', tutorId: tutor.id, datetime, notes });
  };

  return (
    <div className="modal open" role="dialog" aria-modal="true" aria-labelledby="booking-modal-title">
      <div className="modal-inner">
        <button className="modal-close" onClick={onClose} aria-label="Close booking modal">
          ✕
        </button>
        <h3 id="booking-modal-title">Book {tutor.name}</h3>
        <p className="muted">
          {tutor.subjects.join(' • ')} · ${tutor.price}/hr · {tutor.mode.map((mode) => mode[0].toUpperCase() + mode.slice(1)).join(', ')}
        </p>
        <form className="booking-form" onSubmit={handleSubmit}>
          <label>
            Preferred date & time
            <input name="datetime" type="datetime-local" required />
          </label>
          <label>
            Session focus
            <textarea name="notes" rows={3} placeholder="Tell the tutor about your goals" required></textarea>
          </label>
          <button type="submit" className="btn btn-primary">
            Confirm Booking
          </button>
        </form>
      </div>
    </div>
  );
}

