import { useMemo, useState } from 'react';
import Hero from './components/Hero.jsx';
import SearchBar from './components/SearchBar.jsx';
import Filters from './components/Filters.jsx';
import TutorDirectory from './components/TutorDirectory.jsx';
import BookingModal from './components/BookingModal.jsx';
import TutorProfile from './components/TutorProfile.jsx';
import Dashboard from './components/Dashboard.jsx';
import TutorDashboard from './components/TutorDashboard.jsx';
import MessagesPanel from './components/MessagesPanel.jsx';
import Team from './components/Team.jsx';
import { tutors as mockTutors, subjects } from './data/tutors.js';
import { teamMembers } from './data/team.js';
import routes from './routes.jsx';

function toTitle(text) {
  return text ? text[0].toUpperCase() + text.slice(1) : '';
}

export default function App() {
  const [query, setQuery] = useState('');
  const [subject, setSubject] = useState('');
  const [price, setPrice] = useState('');
  const [rating, setRating] = useState('');
  const [mode, setMode] = useState('all');
  const [selectedTutor, setSelectedTutor] = useState(null);
  const [profileTutor, setProfileTutor] = useState(null);

  const filteredTutors = useMemo(() => {
    const q = query.trim().toLowerCase();
    return mockTutors.filter((tutor) => {
      const matchesQuery = q
        ? [tutor.name, tutor.bio, tutor.subjects.join(' '), tutor.location].join(' ').toLowerCase().includes(q)
        : true;

      const matchesSubject = subject ? tutor.subjects.includes(subject) : true;

      const matchesPrice = price
        ? price === '20'
          ? tutor.price < 20
          : price === '25'
          ? tutor.price >= 20 && tutor.price <= 25
          : tutor.price >= 26
        : true;

      const matchesRating = rating ? tutor.rating >= Number(rating) : true;

      const matchesMode = mode === 'all' ? true : tutor.mode.includes(mode);

      return matchesQuery && matchesSubject && matchesPrice && matchesRating && matchesMode;
    });
  }, [mode, price, query, rating, subject]);

  const activeFilters = useMemo(() => {
    const list = [];
    if (subject) list.push(subject);
    if (price) list.push(price === '20' ? 'Under $20/hr' : price === '25' ? '$20 - $25/hr' : '$25+/hr');
    if (rating) list.push(`${rating}+ rating`);
    if (mode !== 'all') list.push(`${toTitle(mode)} sessions`);
    if (query) list.push(`“${query}”`);
    return list;
  }, [mode, price, query, rating, subject]);

  const handleClearFilters = () => {
    setQuery('');
    setSubject('');
    setPrice('');
    setRating('');
    setMode('all');
  };

  const handleConfirmBooking = ({ tutorId, datetime, notes }) => {
    const tutor = mockTutors.find((item) => item.id === tutorId);
    if (!tutor) return;
    alert(`Booking confirmed with ${tutor.name} on ${new Date(datetime).toLocaleString()}\nNotes: ${notes}`);
    setSelectedTutor(null);
  };

  return (
    <div>
      <header className="site-header">
        <div className="container header-inner">
          <a className="brand" href="#spa-hero">
            <img src="/public/images/logo.svg" alt="SmartTutor Connect" className="logo" />
            <span className="brand-name" aria-hidden="true">
              SmartTutor Connect
            </span>
          </a>
          <nav className="main-nav" aria-label="Primary">
            {routes.map((route) => (
              <a key={route.id} href={`#${route.id}`}>
                {route.label}
              </a>
            ))}
          </nav>
        </div>
      </header>
      <main>
        <Hero onFindTutor={() => document.querySelector('#spa-directory')?.scrollIntoView({ behavior: 'smooth' })} onBecomeTutor={() => document.querySelector('#spa-dashboard')?.scrollIntoView({ behavior: 'smooth' })} />
        <section className="section about" id="spa-about" aria-labelledby="spa-about-title">
          <div
            className="about-overlay"
            aria-hidden="true"
            style={{ backgroundImage: "url('/images/about-bg.svg')" }}
          ></div>
          <div className="container about-grid">
            <div className="about-text">
              <h2 id="spa-about-title" className="section-title">
                About SmartTutor Connect
              </h2>
              <p>
                We build a trusted learning ecosystem that pairs every learner with the right mentor. From the first search to post-session feedback, SmartTutor Connect orchestrates each step with transparency, security, and human support.
              </p>
              <p>
                Our hybrid model empowers tutors with intelligent dashboards, while families gain clarity through verified profiles, ratings, and payments handled in one place. We believe that meaningful tutoring experiences unlock confidence, curiosity, and long-term success.
              </p>
              <ul className="feature-list">
                <li>
                  <strong>Holistic onboarding:</strong> Vetting, training, and continuous quality reviews for every tutor.
                </li>
                <li>
                  <strong>Inclusive access:</strong> Scholarships, flexible pricing, and multilingual support for global learners.
                </li>
                <li>
                  <strong>Impact-driven insights:</strong> Real-time progress reports keep students, parents, and tutors aligned.
                </li>
              </ul>
            </div>
            <aside className="about-metrics" role="complementary" aria-label="SmartTutor impact metrics">
              <div className="metric-card">
                <h3>12k+</h3>
                <p>Sessions delivered across STEM, languages, and creative disciplines.</p>
              </div>
              <div className="metric-card">
                <h3>1.2k</h3>
                <p>Verified tutors with personalized dashboards and automated scheduling.</p>
              </div>
              <div className="metric-card">
                <h3>94%</h3>
                <p>Average satisfaction score rated by students and parents worldwide.</p>
              </div>
            </aside>
          </div>
        </section>
        <Team members={teamMembers} />
        <section id="spa-directory" className="section directory" aria-labelledby="spa-directory-title">
          <div className="container">
            <header className="section-header">
              <h2 id="spa-directory-title" className="section-title">
                Explore tutors
              </h2>
              <p className="section-sub">
                Use advanced filters to discover the perfect tutor by subject, mode, price, and rating.
              </p>
            </header>
            <SearchBar
              subjects={subjects}
              query={query}
              subject={subject}
              price={price}
              rating={rating}
              mode={mode}
              onQueryChange={setQuery}
              onSubjectChange={setSubject}
              onPriceChange={setPrice}
              onRatingChange={setRating}
              onModeChange={setMode}
              onSubmit={() => { /** filters update automatically */ }}
            />
            <Filters activeFilters={activeFilters} onClear={handleClearFilters} />
            <TutorDirectory tutors={filteredTutors} onBook={setSelectedTutor} onView={setProfileTutor} />
          </div>
        </section>
        <section id="spa-dashboard" className="section" aria-label="Dashboard showcase">
          <div className="container">
            <header className="section-header">
              <h2 className="section-title">Tutor Dashboard</h2>
              <p className="section-sub">Manage your sessions, earnings, and student feedback in one place.</p>
            </header>
            <Dashboard />
            <TutorDashboard tutorId={1} /> {/* In production, use actual tutor ID from auth context */}
          </div>
        </section>
        <section id="spa-messages" className="section" aria-label="Messaging showcase">
          <div className="container">
            <header className="section-header">
              <h2 className="section-title">Messaging workspace</h2>
              <p className="section-sub">Preview the new conversation hub tutors and students see in the product.</p>
            </header>
            <MessagesPanel />
          </div>
        </section>
      </main>
      <footer className="site-footer" aria-label="SmartTutor footer">
        <div className="container footer-inner">
          <p className="footer-copy">© {new Date().getFullYear()} SmartTutor Connect. Built for personalized learning.</p>
          <div className="footer-links">
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
            <a href="#">Support</a>
          </div>
        </div>
      </footer>

      {selectedTutor ? (
        <div id="react-booking-modal" className="modal-layer">
          <BookingModal tutor={selectedTutor} onClose={() => setSelectedTutor(null)} onConfirm={handleConfirmBooking} />
        </div>
      ) : null}

      {profileTutor ? (
        <div id="react-profile-modal" className="modal-layer">
          <TutorProfile tutor={profileTutor} onBook={(tutor) => setSelectedTutor(tutor)} onClose={() => setProfileTutor(null)} />
        </div>
      ) : null}
    </div>
  );
}

