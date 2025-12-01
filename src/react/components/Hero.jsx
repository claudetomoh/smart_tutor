export default function Hero({ onFindTutor, onBecomeTutor }) {
  return (
    <section className="hero" id="spa-hero" aria-labelledby="spa-hero-title">
      <div className="hero-overlay" aria-hidden="true"></div>
      <picture>
        <source srcSet="/public/images/home-hero.png" type="image/png" />
        <img src="/public/images/hero-bg.svg" alt="Students and tutors collaborating" className="hero-bg" />
      </picture>
      <div className="hero-content container">
        <p className="eyebrow">SmartTutor Connect</p>
        <h1 id="spa-hero-title" className="hero-title">
          Learn Smarter. Teach Better.
        </h1>
        <p className="hero-sub">
          Seamlessly connect students, parents, and verified tutors with secure sessions and effortless scheduling.
        </p>
        <div className="hero-ctas">
          <button className="btn btn-primary" onClick={onFindTutor}>
            Find a Tutor
          </button>
          <button className="btn btn-outline" onClick={onBecomeTutor}>
            Become a Tutor
          </button>
        </div>
        <div className="hero-cred">
          <span>Verified professionals</span>
          <span aria-hidden>•</span>
          <span>Secure video rooms</span>
          <span aria-hidden>•</span>
          <span>Flexible payment options</span>
        </div>
      </div>
    </section>
  );
}

