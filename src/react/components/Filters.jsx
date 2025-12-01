export default function Filters({ activeFilters = [], onClear }) {
  if (!activeFilters.length) {
    return (
      <p className="muted" role="status">
        Showing results based on default filters.
      </p>
    );
  }

  return (
    <div className="active-filters" role="status">
      <span className="muted">Filters:</span>
      {activeFilters.map((filter) => (
        <span key={filter} className="filter-pill">
          {filter}
        </span>
      ))}
      <button type="button" className="link-button" onClick={onClear}>
        Clear all
      </button>
    </div>
  );
}

