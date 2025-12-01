import { useState, useEffect } from 'react';

export default function FeedbackSection({ tutorId }) {
  const [feedback, setFeedback] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
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

    fetchFeedback();
  }, [tutorId]);

  if (loading) return <div className="loading">Loading feedback...</div>;
  if (error) return <div className="error">{error}</div>;
  if (!feedback) return null;

  return (
    <section className="feedback-section">
      <header className="feedback-header">
        <div className="rating-summary">
          <div className="overall-rating">
            <h3>{feedback.ratings.overall.toFixed(1)}</h3>
            <div className="stars">
              {[1, 2, 3, 4, 5].map((star) => (
                <span
                  key={star}
                  className={`star ${star <= feedback.ratings.overall ? 'filled' : ''}`}
                  aria-hidden="true"
                >
                  ★
                </span>
              ))}
            </div>
            <p className="review-count">{feedback.stats.total_reviews} reviews</p>
          </div>
          <div className="rating-breakdown">
            <div className="rating-row">
              <span>Knowledge</span>
              <div className="rating-bar">
                <div
                  className="rating-fill"
                  style={{ width: `${(feedback.ratings.knowledge / 5) * 100}%` }}
                ></div>
              </div>
              <span>{feedback.ratings.knowledge.toFixed(1)}</span>
            </div>
            <div className="rating-row">
              <span>Communication</span>
              <div className="rating-bar">
                <div
                  className="rating-fill"
                  style={{ width: `${(feedback.ratings.communication / 5) * 100}%` }}
                ></div>
              </div>
              <span>{feedback.ratings.communication.toFixed(1)}</span>
            </div>
            <div className="rating-row">
              <span>Punctuality</span>
              <div className="rating-bar">
                <div
                  className="rating-fill"
                  style={{ width: `${(feedback.ratings.punctuality / 5) * 100}%` }}
                ></div>
              </div>
              <span>{feedback.ratings.punctuality.toFixed(1)}</span>
            </div>
            <div className="rating-row">
              <span>Helpfulness</span>
              <div className="rating-bar">
                <div
                  className="rating-fill"
                  style={{ width: `${(feedback.ratings.helpfulness / 5) * 100}%` }}
                ></div>
              </div>
              <span>{feedback.ratings.helpfulness.toFixed(1)}</span>
            </div>
          </div>
        </div>
        <div className="stats-summary">
          <div className="stat">
            <strong>{feedback.stats.total_sessions}</strong>
            <span>Sessions completed</span>
          </div>
          <div className="stat">
            <strong>{feedback.stats.repeat_students}</strong>
            <span>Repeat students</span>
          </div>
        </div>
      </header>

      <div className="reviews-list">
        {feedback.reviews.map((review) => (
          <article key={review.id} className="review-card">
            <header>
              <div className="review-meta">
                <h4>{review.author}</h4>
                <time dateTime={review.date}>{new Date(review.date).toLocaleDateString()}</time>
              </div>
              <div className="review-rating" aria-label={`Rated ${review.rating} out of 5 stars`}>
                {[1, 2, 3, 4, 5].map((star) => (
                  <span key={star} className={`star ${star <= review.rating ? 'filled' : ''}`}>
                    ★
                  </span>
                ))}
              </div>
            </header>
            <p>{review.comment}</p>
            {review.response && (
              <div className="review-response">
                <strong>Tutor's response:</strong>
                <p>{review.response}</p>
              </div>
            )}
          </article>
        ))}
      </div>
    </section>
  );
}