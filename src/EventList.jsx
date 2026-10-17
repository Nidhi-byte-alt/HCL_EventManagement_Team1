import { useEffect, useState } from "react";
import "./EventList.css";

// Override this in your React project's .env file if your API uses another host/port:
// VITE_API_BASE_URL=http://localhost:5001
const API_BASE_URL = (
  import.meta.env.VITE_API_BASE_URL || "http://localhost:5001"
).replace(/\/$/, "");

const EVENTS_ENDPOINT = `${API_BASE_URL}/api/events`;

function formatDate(value) {
  if (!value) return "Date to be announced";

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return new Intl.DateTimeFormat("en-IN", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(date);
}

function getImageUrl(image) {
  if (!image) return "";

  const value = String(image);
  if (/^(https?:)?\/\//i.test(value) || value.startsWith("data:")) {
    return value;
  }

  return `${API_BASE_URL}/${value.replace(/^\/+/, "")}`;
}

function normalizeEvents(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.events)) return payload.events;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;
  return [];
}

export default function EventList({ onViewEvent, onRegister }) {
  const [events, setEvents] = useState([]);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retryCount, setRetryCount] = useState(0);

  useEffect(() => {
    const controller = new AbortController();

    async function fetchEvents() {
      setLoading(true);
      setError("");

      try {
        const response = await fetch(EVENTS_ENDPOINT, {
          method: "GET",
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(
            `Failed to fetch events. Server returned HTTP ${response.status}.`
          );
        }

        const payload = await response.json();
        setEvents(normalizeEvents(payload));
      } catch (err) {
        if (err.name !== "AbortError") {
          setError(
            err.message ||
              "Unable to load events. Please check your API connection."
          );
        }
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }

    fetchEvents();
    return () => controller.abort();
  }, [retryCount]);

  const filteredEvents = events.filter((event) => {
    const query = search.trim().toLowerCase();
    if (!query) return true;

    return [
      event.name,
      event.id,
      event.location,
      event.description,
    ].some((value) => String(value ?? "").toLowerCase().includes(query));
  });

  return (
    <main className="event-page">
      <header className="event-page__header">
        <div>
          <p className="event-page__eyebrow">DISCOVER · CONNECT · EXPERIENCE</p>
          <h1>Explore events</h1>
          <p className="event-page__subtitle">
            Find your next experience and make it memorable.
          </p>
        </div>

        <label className="event-search">
          <span aria-hidden="true">⌕</span>
          <span className="sr-only">Search events</span>
          <input
            type="search"
            placeholder="Search events, places..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </label>
      </header>

      <section className="event-results" aria-live="polite">
        <div className="event-results__heading">
          <h2>Events</h2>
          {!loading && !error && (
            <span>
              {filteredEvents.length}{" "}
              {filteredEvents.length === 1 ? "event" : "events"}
            </span>
          )}
        </div>

        {loading && (
          <div className="event-state">
            <div className="event-spinner" />
            <p>Loading events…</p>
          </div>
        )}

        {!loading && error && (
          <div className="event-state event-state--error">
            <h3>We couldn’t load the events</h3>
            <p>{error}</p>
            <p className="event-state__hint">
              Endpoint: <code>{EVENTS_ENDPOINT}</code>
            </p>
            <button
              className="event-button event-button--secondary"
              onClick={() => setRetryCount((count) => count + 1)}
            >
              Try again
            </button>
          </div>
        )}

        {!loading && !error && filteredEvents.length === 0 && (
          <div className="event-state">
            <div className="event-state__icon">✦</div>
            <h3>{search ? "No matching events" : "No events available yet"}</h3>
            <p>
              {search
                ? "Try another search term."
                : "Please check back soon for new events."}
            </p>
          </div>
        )}

        {!loading && !error && filteredEvents.length > 0 && (
          <div className="event-grid">
            {filteredEvents.map((event) => (
              <article className="event-card" key={event.id}>
                <div className="event-card__image-wrap">
                  {event.image ? (
                    <img
                      className="event-card__image"
                      src={getImageUrl(event.image)}
                      alt={event.name || "Event"}
                      loading="lazy"
                      onError={(e) => {
                        e.currentTarget.style.display = "none";
                        e.currentTarget.nextElementSibling?.classList.remove(
                          "is-hidden"
                        );
                      }}
                    />
                  ) : null}

                  <div
                    className={`event-card__image-placeholder ${
                      event.image ? "is-hidden" : ""
                    }`}
                  >
                    <span>✦</span>
                    <small>EVENTORA</small>
                  </div>

                  <span className="event-card__date">
                    {formatDate(event.date)}
                  </span>
                </div>

                <div className="event-card__body">
                  <div className="event-card__meta">
                    <span>◷ {event.time || "Time to be announced"}</span>
                    <span title="Event ID">ID: {event.id ?? "—"}</span>
                  </div>

                  <h3>{event.name || "Untitled event"}</h3>
                  <p className="event-card__location">
                    ⌖ {event.location || "Location to be announced"}
                  </p>
                  <p className="event-card__description">
                    {event.description ||
                      "More details about this event will be available soon."}
                  </p>

                  <div className="event-card__actions">
                    <button
                      type="button"
                      className="event-button event-button--primary"
                      onClick={() => onViewEvent?.(event)}
                    >
                      View details <span aria-hidden="true">→</span>
                    </button>

                    {onRegister && (
                      <button
                        type="button"
                        className="event-button event-button--secondary"
                        onClick={() => onRegister(event)}
                      >
                        Register
                      </button>
                    )}
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>
    </main>
  );
}
