import { useState, useEffect } from 'react';

const FeedbackOverview = ({ feedbackStats }) => (
  <div className="dashboard-stats">
    <div className="stat-card">
      <h3>{feedbackStats.ratings.overall.toFixed(1)}</h3>
      <p>Overall Rating</p>
    </div>
    <div className="stat-card">
      <h3>{feedbackStats.stats.total_reviews}</h3>
      <p>Total Reviews</p>
    </div>
    <div className="stat-card">
      <h3>{feedbackStats.stats.repeat_students}</h3>
      <p>Repeat Students</p>
    </div>
    <div className="stat-card">
      <h3>{((feedbackStats.stats.total_reviews / feedbackStats.stats.total_sessions) * 100).toFixed(0)}%</h3>
      <p>Feedback Rate</p>
    </div>
  </div>
);

const FeedbackTable = ({ feedback, onRespond }) => (
  <div className="feedback-table-container">
    <table className="dashboard-table">
      <thead>
        <tr>
          <th>Student</th>
          <th>Rating</th>
          <th>Comment</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {feedback.reviews.map((review) => (
          <tr key={review.id}>
            <td>{review.author}</td>
            <td>
              <div className="star-rating" aria-label={`${review.rating} out of 5 stars`}>
                {[1, 2, 3, 4, 5].map((star) => (
                  <span key={star} className={star <= review.rating ? 'star filled' : 'star'}>
                    ★
                  </span>
                ))}
              </div>
            </td>
            <td>{review.comment}</td>
            <td>{new Date(review.date).toLocaleDateString()}</td>
            <td>
              {!review.response ? (
                <button
                  className="btn btn-outline btn-sm"
                  onClick={() => onRespond(review)}
                >
                  Respond
                </button>
              ) : (
                <span className="badge badge-success">Responded</span>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

const ResponseModal = ({ review, onClose, onSubmit }) => {
  const [response, setResponse] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!response.trim() || submitting) return;

    setSubmitting(true);
    try {
      await onSubmit(review.id, response);
      onClose();
    } catch (error) {
      console.error('Failed to submit response:', error);
      alert('Failed to submit response. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="modal open" role="dialog" aria-modal="true">
      <div className="modal-inner">
        <button className="modal-close" onClick={onClose}>✕</button>
        <h3>Respond to Feedback</h3>
        <div className="review-preview">
          <p><strong>Student:</strong> {review.author}</p>
          <p><strong>Rating:</strong> {review.rating}/5</p>
          <p><strong>Comment:</strong> {review.comment}</p>
        </div>
        <form onSubmit={handleSubmit}>
          <label>
            Your Response
            <textarea
              value={response}
              onChange={(e) => setResponse(e.target.value)}
              placeholder="Write your response..."
              rows={4}
              required
            />
          </label>
          <div className="modal-actions">
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              {submitting ? 'Submitting...' : 'Submit Response'}
            </button>
            <button type="button" className="btn btn-outline" onClick={onClose}>
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default function TutorDashboard({ tutorId }) {
  const [feedback, setFeedback] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedReview, setSelectedReview] = useState(null);

  useEffect(() => {
    fetchFeedback();
  }, [tutorId]);

  const fetchFeedback = async () => {
    try {
      const response = await fetch(`/api/feedback.php?userId=${tutorId}&role=tutor`);
      if (!response.ok) throw new Error('Failed to fetch feedback');
      const data = await response.json();
      setFeedback(data.data);
    } catch (err) {
      console.error('Error fetching feedback:', err);
      setError('Failed to load feedback. Please try again later.');
    } finally {
      setLoading(false);
    }
  };

  const handleRespond = async (reviewId, response) => {
    try {
      const result = await fetch('/api/feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          type: 'response',
          reviewId,
          response,
          tutorId
        })
      });

      if (!result.ok) throw new Error('Failed to submit response');
      
      // Refresh feedback data
      await fetchFeedback();
    } catch (error) {
      console.error('Error submitting response:', error);
      throw error;
    }
  };

  if (loading) return <div className="loading">Loading dashboard...</div>;
  if (error) return <div className="error">{error}</div>;
  if (!feedback) return null;

  return (
    <div className="tutor-dashboard">
      <h2>Feedback Dashboard</h2>
      <FeedbackOverview feedbackStats={feedback} />
      
      <div className="dashboard-actions">
        <h3>Recent Feedback</h3>
        <div className="filters">
          <select defaultValue="all">
            <option value="all">All Ratings</option>
            <option value="5">5 Stars</option>
            <option value="4">4 Stars</option>
            <option value="3">3 Stars</option>
            <option value="2">2 Stars</option>
            <option value="1">1 Star</option>
          </select>
          <select defaultValue="recent">
            <option value="recent">Most Recent</option>
            <option value="oldest">Oldest First</option>
            <option value="highest">Highest Rated</option>
            <option value="lowest">Lowest Rated</option>
          </select>
        </div>
      </div>

      <FeedbackTable
        feedback={feedback}
        onRespond={setSelectedReview}
      />

      {selectedReview && (
        <ResponseModal
          review={selectedReview}
          onClose={() => setSelectedReview(null)}
          onSubmit={handleRespond}
        />
      )}
    </div>
  );
}