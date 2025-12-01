export default function Team({ members }) {
  return (
    <section className="section team" id="spa-team" aria-labelledby="spa-team-title">
      <div className="container">
        <header className="section-header">
          <h2 id="spa-team-title" className="section-title">
            Meet our team
          </h2>
          <p className="section-sub">
            Educators, technologists, and community leaders committed to unlocking potential through personalized learning.
          </p>
        </header>
        <div className="team-grid">
          {members.map((member) => (
            <article key={member.id} className="team-card">
              <img
                src={member.photo}
                alt={member.name}
                onError={(e) => {
                  if (e.currentTarget.src.endsWith('/public/images/tutor-portrait.svg')) return;
                  e.currentTarget.src = '/public/images/tutor-portrait.svg';
                }}
              />
              <div className="team-info">
                <h3>{member.name}</h3>
                <p className="team-role">{member.role}</p>
                <p>{member.bio}</p>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

