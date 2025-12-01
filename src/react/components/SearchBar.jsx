const priceFilters = [
  { value: '', label: 'All Rates' },
  { value: '20', label: 'Under $20/hr' },
  { value: '25', label: '$20 - $25/hr' },
  { value: '26', label: '$25+/hr' },
];

const ratingFilters = [
  { value: '', label: 'Any Rating' },
  { value: '4.5', label: '4.5+' },
  { value: '4.8', label: '4.8+' },
];

const modeFilters = [
  { value: 'all', label: 'All Modes' },
  { value: 'online', label: 'Online' },
  { value: 'in-person', label: 'In-person' },
  { value: 'hybrid', label: 'Hybrid' },
];

export default function SearchBar({
  subjects,
  query,
  subject,
  price,
  rating,
  mode,
  onQueryChange,
  onSubjectChange,
  onPriceChange,
  onRatingChange,
  onModeChange,
  onSubmit,
}) {
  return (
    <div>
      <div className="search-controls" role="search">
        <input
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyUp={(event) => event.key === 'Enter' && onSubmit()}
          placeholder="Search tutors, subjects, or keywords"
          aria-label="Search tutors"
        />
        <select value={subject} onChange={(event) => onSubjectChange(event.target.value)} aria-label="Filter by subject">
          <option value="">All Subjects</option>
          {subjects.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </select>
        <select value={price} onChange={(event) => onPriceChange(event.target.value)} aria-label="Filter by price">
          {priceFilters.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        <select value={rating} onChange={(event) => onRatingChange(event.target.value)} aria-label="Filter by rating">
          {ratingFilters.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        <button type="button" className="btn btn-primary" onClick={onSubmit}>
          Search
        </button>
      </div>
      <div className="filter-chips" role="group" aria-label="Session mode filters">
        {modeFilters.map((item) => (
          <button
            type="button"
            key={item.value}
            className={`filter-chip ${mode === item.value ? 'active' : ''}`}
            onClick={() => onModeChange(item.value)}
          >
            {item.label}
          </button>
        ))}
      </div>
    </div>
  );
}

