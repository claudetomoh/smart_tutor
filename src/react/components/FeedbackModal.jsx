import { useEffect, useState } from 'react';

export default function FeedbackModal({ session, onClose, onSubmit }) {
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const handleKey = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, [onClose]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    try {
      await onSubmit({
        sessionId: session.id,
        tutorId: session.tutorId,
        studentId: session.studentId,
        rating,
        comment
      });
      onClose();
    } catch (error) {
      console.error('Failed to submit feedback:', error);
      alert('Failed to submit feedback. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="modal open" role="dialog" aria-modal="true" aria-labelledby="feedback-modal-title">
      <div className="modal-inner">
        <button className="modal-close" onClick={onClose} aria-label="Close feedback modal">
          ✕
        </button>
        <h3 id="feedback-modal-title">Session Feedback</h3>
        <p className="muted">
          Your feedback helps tutors improve and helps other students find great matches.
        </p>
        <form className="feedback-form" onSubmit={handleSubmit}>
          <div className="rating-group">
            <label>Overall Rating</label>
            <div className="star-rating" role="radiogroup">
              {[5, 4, 3, 2, 1].map((value) => (
                <button
                  key={value}
                  type="button"
                  className={`star-btn ${value <= rating ? 'active' : ''}`}
                  onClick={() => setRating(value)}
                  aria-label={`${value} stars`}
                  aria-pressed={value === rating}
                >
                  ★
                </button>
              ))}
            </div>
          </div>
          <label>
            Your Feedback
            <textarea
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              rows={4}
              placeholder="What went well? What could be improved?"
              required
            ></textarea>
          </label>
          <div className="modal-actions">
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              {submitting ? 'Submitting...' : 'Submit Feedback'}
            </button>
            <button type="button" className="btn btn-outline" onClick={onClose}>
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}