
import { useMemo, useState } from "react";
import "./Dashboard.css";

const initialEvents = [
  {
    id: 1,
    title: "Future of Technology",
    category: "Technology",
    date: "2026-11-12",
    time: "10:00 AM",
    location: "Jaipur Convention Centre",
    price: 499,
    attendees: 128,
    capacity: 200,
    color: "purple",
    description: "Explore emerging technologies, AI and innovation.",
  },
  {
    id: 2,
    title: "Creative Business Summit",
    category: "Business",
    date: "2026-11-18",
    time: "11:00 AM",
    location: "The Grand Conference Hall",
    price: 799,
    attendees: 86,
    capacity: 150,
    color: "orange",
    description: "Connect with founders, entrepreneurs and leaders.",
  },
  {
    id: 3,
    title: "Design & Innovation Workshop",
    category: "Workshop",
    date: "2026-11-24",
    time: "02:00 PM",
    location: "Innovation Hub",
    price: 299,
    attendees: 54,
    capacity: 100,
    color: "green",
    description: "Learn practical approaches to creative problem-solving.",
  },
  {
    id: 4,
    title: "Digital Marketing Masterclass",
    category: "Marketing",
    date: "2026-12-02",
    time: "09:30 AM",
    location: "Jaipur Business Centre",
    price: 399,
    attendees: 92,
    capacity: 120,
    color: "pink",
    description: "Discover modern marketing strategies and analytics.",
  },
];

const money = (value) =>
  new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: 0,
  }).format(value);

const formatDate = (value) =>
  new Date(`${value}T12:00:00`).toLocaleDateString("en-IN", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });

function Dashboard({ user, onLogout }) {
  const role = String(user?.role || user?.accountType || "User").toLowerCase();
  const isAdmin = role === "admin";
  const displayName = user?.name || "Guest";
  const firstName = displayName.split(" ")[0];

  const [events, setEvents] = useState(initialEvents);
  const [registrations, setRegistrations] = useState([]);
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("All");
  const [activePage, setActivePage] = useState("dashboard");
  const [showEventForm, setShowEventForm] = useState(false);
  const [editingEvent, setEditingEvent] = useState(null);
  const [notice, setNotice] = useState("");
  const [eventForm, setEventForm] = useState({
    title: "",
    category: "Technology",
    date: "",
    time: "10:00 AM",
    location: "",
    price: "0",
    capacity: "100",
    description: "",
  });

  const visibleEvents = useMemo(() => {
    return events.filter((event) => {
      const matchesSearch =
        `${event.title} ${event.category} ${event.location}`
          .toLowerCase()
          .includes(search.toLowerCase());

      const matchesCategory =
        category === "All" || event.category === category;

      return matchesSearch && matchesCategory;
    });
  }, [events, search, category]);

  const myRegistrations = registrations.filter(
    (registration) => registration.userEmail === user?.email
  );

  const registeredIds = new Set(
    myRegistrations.map((registration) => registration.eventId)
  );

  const categories = [
    "All",
    ...new Set(events.map((event) => event.category)),
  ];

  const totalAttendees = events.reduce(
    (total, event) => total + event.attendees,
    0
  );

  const openCreateForm = () => {
    setEditingEvent(null);
    setEventForm({
      title: "",
      category: "Technology",
      date: "",
      time: "10:00 AM",
      location: "",
      price: "0",
      capacity: "100",
      description: "",
    });
    setShowEventForm(true);
  };

  const openEditForm = (event) => {
    setEditingEvent(event);
    setEventForm({
      title: event.title,
      category: event.category,
      date: event.date,
      time: event.time,
      location: event.location,
      price: String(event.price),
      capacity: String(event.capacity),
      description: event.description,
    });
    setShowEventForm(true);
  };

  const saveEvent = (e) => {
    e.preventDefault();

    const eventData = {
      ...eventForm,
      id: editingEvent?.id ?? Date.now(),
      price: Number(eventForm.price),
      capacity: Number(eventForm.capacity),
      attendees: editingEvent?.attendees ?? 0,
      color: editingEvent?.color ?? "purple",
    };

    if (editingEvent) {
      setEvents((previous) =>
        previous.map((event) =>
          event.id === editingEvent.id ? eventData : event
        )
      );
      setNotice("Event updated successfully.");
    } else {
      setEvents((previous) => [eventData, ...previous]);
      setNotice("Event created successfully.");
    }

    setShowEventForm(false);
    setActivePage("events");
  };

  const deleteEvent = (id) => {
    if (!window.confirm("Are you sure you want to delete this event?")) {
      return;
    }

    setEvents((previous) => previous.filter((event) => event.id !== id));
    setRegistrations((previous) =>
      previous.filter((registration) => registration.eventId !== id)
    );
    setNotice("Event deleted.");
  };

  const registerForEvent = (event) => {
    if (!user?.email) {
      setNotice("Please sign in to register for an event.");
      return;
    }

    if (registeredIds.has(event.id)) {
      setNotice("You are already registered for this event.");
      return;
    }

    if (event.attendees >= event.capacity) {
      setNotice("Sorry, this event is full.");
      return;
    }

    setRegistrations((previous) => [
      ...previous,
      {
        id: Date.now(),
        eventId: event.id,
        userEmail: user.email,
        registeredAt: new Date().toISOString(),
      },
    ]);

    setEvents((previous) =>
      previous.map((item) =>
        item.id === event.id
          ? { ...item, attendees: item.attendees + 1 }
          : item
      )
    );

    setNotice(`Successfully registered for ${event.title}.`);
  };

  const cancelRegistration = (eventId) => {
    setRegistrations((previous) =>
      previous.filter(
        (registration) =>
          !(
            registration.eventId === eventId &&
            registration.userEmail === user?.email
          )
      )
    );

    setEvents((previous) =>
      previous.map((event) =>
        event.id === eventId
          ? { ...event, attendees: Math.max(0, event.attendees - 1) }
          : event
      )
    );

    setNotice("Your registration has been cancelled.");
  };

  const pageTitle = {
    dashboard: "Dashboard",
    events: isAdmin ? "Manage Events" : "Explore Events",
    registrations: "My Registrations",
  }[activePage];

  const changePage = (page) => {
    setActivePage(page);
    setSearch("");
    setCategory("All");
    setNotice("");
  };

  return (
    <div className="dashboard-app">
      <aside className="dash-sidebar">
        <button
          className="dash-brand"
          onClick={() => changePage("dashboard")}
        >
          <span className="dash-brand-icon">✦</span>
          Eventora
        </button>

        <div className="sidebar-caption">WORKSPACE</div>

        <button
          className={`nav-item ${activePage === "dashboard" ? "active" : ""}`}
          onClick={() => changePage("dashboard")}
        >
          <span>⌂</span> Dashboard
        </button>

        <button
          className={`nav-item ${activePage === "events" ? "active" : ""}`}
          onClick={() => changePage("events")}
        >
          <span>▦</span> {isAdmin ? "Manage Events" : "Explore Events"}
        </button>

        {!isAdmin && (
          <button
            className={`nav-item ${
              activePage === "registrations" ? "active" : ""
            }`}
            onClick={() => changePage("registrations")}
          >
            <span>▤</span> My Registrations
          </button>
        )}

        <div className="sidebar-bottom">
          <div className="help-card">
            <span className="help-icon">✧</span>
            <strong>Need some help?</strong>
            <p>Make your next event memorable.</p>
          </div>

          <div className="sidebar-profile">
            <div className="profile-avatar">
              {displayName.charAt(0).toUpperCase()}
            </div>
            <div className="profile-details">
              <strong>{displayName}</strong>
              <span>{isAdmin ? "Administrator" : "Event User"}</span>
            </div>
            <button
              className="logout-icon"
              onClick={onLogout}
              title="Sign out"
              aria-label="Sign out"
            >
              ↗
            </button>
          </div>
        </div>
      </aside>

      <main className="dash-main">
        <header className="dash-topbar">
          <div>
            <div className="breadcrumb">Workspace / {pageTitle}</div>
            <h1>{pageTitle}</h1>
          </div>

          <div className="topbar-right">
            <span className="today-label">
              {new Date().toLocaleDateString("en-IN", {
                weekday: "short",
                day: "numeric",
                month: "short",
              })}
            </span>
            <div className="profile-avatar top-avatar">
              {displayName.charAt(0).toUpperCase()}
            </div>
          </div>
        </header>

        {notice && (
          <div className="dashboard-notice" role="status">
            <span>{notice}</span>
            <button onClick={() => setNotice("")} aria-label="Dismiss message">
              ×
            </button>
          </div>
        )}

        {activePage === "dashboard" && (
          <>
            <section className="welcome-banner">
              <div className="welcome-content">
                <span className="welcome-eyebrow">
                  {isAdmin ? "YOUR EVENT CONTROL CENTER" : "YOUR NEXT EXPERIENCE"}
                </span>
                <h2>
                  {isAdmin ? "Welcome back," : "Good to see you,"} {firstName}!
                </h2>
                <p>
                  {isAdmin
                    ? "Manage your events, track registrations and create experiences people love."
                    : "Discover something exciting. Your next great experience is just around the corner."}
                </p>
                <button
                  className="banner-button"
                  onClick={() =>
                    isAdmin ? openCreateForm() : changePage("events")
                  }
                >
                  {isAdmin ? "+ Create New Event" : "Explore Events"} <span>→</span>
                </button>
              </div>
              <div className="banner-art" aria-hidden="true">
                <div className="art-circle circle-one" />
                <div className="art-circle circle-two" />
                <div className="art-ticket">
                  <span>✦</span>
                  <strong>MAKE</strong>
                  <strong>MEMORIES</strong>
                  <small>YOUR NEXT CHAPTER</small>
                </div>
                <span className="art-star star-one">✳</span>
                <span className="art-star star-two">✦</span>
              </div>
            </section>

            <section className="stats-grid">
              <StatCard
                icon="▦"
                color="purple"
                label={isAdmin ? "Total Events" : "Available Events"}
                value={events.length}
                note="Across all categories"
              />
              <StatCard
                icon="♙"
                color="orange"
                label={isAdmin ? "Total Registrations" : "My Registrations"}
                value={isAdmin ? totalAttendees : myRegistrations.length}
                note={isAdmin ? "Across all events" : "Your booked events"}
              />
              <StatCard
                icon="◷"
                color="green"
                label="Upcoming Events"
                value={events.filter((event) => event.date >= new Date().toISOString().slice(0, 10)).length}
                note="Ready to explore"
              />
            </section>

            <section className="section-heading">
              <div>
                <h2>{isAdmin ? "Your Events" : "Featured Events"}</h2>
                <p>
                  {isAdmin
                    ? "Keep your events organized and up to date."
                    : "Handpicked experiences you might love."}
                </p>
              </div>
              <button
                className="secondary-button"
                onClick={() => changePage("events")}
              >
                View all <span>→</span>
              </button>
            </section>

            <EventGrid
              events={events.slice(0, 3)}
              isAdmin={isAdmin}
              registeredIds={registeredIds}
              onRegister={registerForEvent}
              onEdit={openEditForm}
              onDelete={deleteEvent}
            />
          </>
        )}

        {activePage === "events" && (
          <>
            <section className="list-intro">
              <div>
                <h2>{isAdmin ? "Your event collection" : "Find your next experience"}</h2>
                <p>
                  {isAdmin
                    ? "Create, update and manage all your events."
                    : "Search by name, location or category to find the right event."}
                </p>
              </div>
              {isAdmin && (
                <button className="primary-action" onClick={openCreateForm}>
                  + Create Event
                </button>
              )}
            </section>

            <div className="event-filters">
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search events or locations..."
                aria-label="Search events"
              />
              <select
                value={category}
                onChange={(e) => setCategory(e.target.value)}
                aria-label="Filter by category"
              >
                {categories.map((item) => (
                  <option key={item} value={item}>{item}</option>
                ))}
              </select>
            </div>

            <EventGrid
              events={visibleEvents}
              isAdmin={isAdmin}
              registeredIds={registeredIds}
              onRegister={registerForEvent}
              onEdit={openEditForm}
              onDelete={deleteEvent}
            />

            {visibleEvents.length === 0 && (
              <div className="empty-state">
                <span>⌕</span>
                <h3>No events found</h3>
                <p>Try a different search term or category.</p>
              </div>
            )}
          </>
        )}

        {activePage === "registrations" && (
          <>
            <section className="list-intro">
              <div>
                <h2>Your event bookings</h2>
                <p>Keep track of the events you have joined.</p>
              </div>
              <button
                className="primary-action"
                onClick={() => changePage("events")}
              >
                + Find Events
              </button>
            </section>

            {myRegistrations.length === 0 ? (
              <div className="empty-state">
                <span>▤</span>
                <h3>No registrations yet</h3>
                <p>Discover events and reserve your place.</p>
                <button
                  className="primary-action"
                  onClick={() => changePage("events")}
                >
                  Explore Events
                </button>
              </div>
            ) : (
              <div className="registration-list">
                {myRegistrations.map((registration) => {
                  const event = events.find(
                    (item) => item.id === registration.eventId
                  );

                  if (!event) return null;

                  return (
                    <article className="registration-row" key={registration.id}>
                      <div className={`event-date-tile ${event.color}`}>
                        <strong>{new Date(`${event.date}T12:00:00`).getDate()}</strong>
                        <span>
                          {new Date(`${event.date}T12:00:00`).toLocaleDateString("en-IN", {
                            month: "short",
                          })}
                        </span>
                      </div>
                      <div className="registration-info">
                        <strong>{event.title}</strong>
                        <span>{event.location} · {event.time}</span>
                        <small>Confirmation #{registration.id}</small>
                      </div>
                      <div className="registration-actions">
                        <span className="status-pill">Registered</span>
                        <button
                          className="danger-link"
                          onClick={() => cancelRegistration(event.id)}
                        >
                          Cancel
                        </button>
                      </div>
                    </article>
                  );
                })}
              </div>
            )}
          </>
        )}
      </main>

      {showEventForm && isAdmin && (
        <div
          className="modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setShowEventForm(false);
          }}
        >
          <form className="event-modal" onSubmit={saveEvent}>
            <div className="modal-heading">
              <div>
                <span className="welcome-eyebrow">EVENT MANAGEMENT</span>
                <h2>{editingEvent ? "Edit Event" : "Create New Event"}</h2>
              </div>
              <button
                type="button"
                className="modal-close"
                onClick={() => setShowEventForm(false)}
              >
                ×
              </button>
            </div>

            <label>
              Event Name *
              <input
                value={eventForm.title}
                onChange={(e) => setEventForm({ ...eventForm, title: e.target.value })}
                required
                maxLength={100}
                placeholder="Enter event name"
              />
            </label>

            <div className="modal-two-columns">
              <label>
                Category *
                <select
                  value={eventForm.category}
                  onChange={(e) => setEventForm({ ...eventForm, category: e.target.value })}
                >
                  <option>Technology</option>
                  <option>Business</option>
                  <option>Workshop</option>
                  <option>Marketing</option>
                  <option>Entertainment</option>
                </select>
              </label>
              <label>
                Date *
                <input
                  type="date"
                  value={eventForm.date}
                  min={new Date().toISOString().slice(0, 10)}
                  onChange={(e) => setEventForm({ ...eventForm, date: e.target.value })}
                  required
                />
              </label>
            </div>

            <div className="modal-two-columns">
              <label>
                Time *
                <input
                  value={eventForm.time}
                  onChange={(e) => setEventForm({ ...eventForm, time: e.target.value })}
                  required
                  placeholder="10:00 AM"
                />
              </label>
              <label>
                Ticket Price (INR) *
                <input
                  type="number"
                  min="0"
                  value={eventForm.price}
                  onChange={(e) => setEventForm({ ...eventForm, price: e.target.value })}
                  required
                />
              </label>
            </div>

            <label>
              Location *
              <input
                value={eventForm.location}
                onChange={(e) => setEventForm({ ...eventForm, location: e.target.value })}
                required
                placeholder="Enter event venue"
              />
            </label>

            <label>
              Capacity *
              <input
                type="number"
                min={editingEvent?.attendees ?? 1}
                value={eventForm.capacity}
                onChange={(e) => setEventForm({ ...eventForm, capacity: e.target.value })}
                required
              />
            </label>

            <label>
              Description
              <textarea
                value={eventForm.description}
                onChange={(e) => setEventForm({ ...eventForm, description: e.target.value })}
                rows={3}
                placeholder="Tell attendees what to expect"
              />
            </label>

            <div className="modal-actions">
              <button
                type="button"
                className="secondary-button"
                onClick={() => setShowEventForm(false)}
              >
                Cancel
              </button>
              <button className="primary-action" type="submit">
                {editingEvent ? "Save Changes" : "Create Event"}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}

function StatCard({ icon, color, label, value, note }) {
  return (
    <article className="stat-card">
      <div className={`stat-icon ${color}`}>{icon}</div>
      <span className="stat-label">{label}</span>
      <strong className="stat-value">{value}</strong>
      <span className="stat-note">{note}</span>
    </article>
  );
}

function EventGrid({
  events,
  isAdmin,
  registeredIds,
  onRegister,
  onEdit,
  onDelete,
}) {
  if (!events.length) {
    return (
      <div className="empty-state">
        <span>▦</span>
        <h3>No events available</h3>
        <p>New events will appear here.</p>
      </div>
    );
  }

  return (
    <div className="events-grid">
      {events.map((event) => (
        <article className="event-card" key={event.id}>
          <div className={`event-cover ${event.color}`}>
            <span className="cover-category">{event.category}</span>
            <span className="cover-symbol">
              {event.category === "Technology"
                ? "✳"
                : event.category === "Business"
                  ? "◇"
                  : event.category === "Workshop"
                    ? "✦"
                    : "✺"}
            </span>
            <span className="cover-date">{formatDate(event.date)}</span>
          </div>

          <div className="event-card-body">
            <h3>{event.title}</h3>
            <p className="event-description">{event.description}</p>
            <div className="event-meta">
              <span>◷ {event.time}</span>
              <span>⌖ {event.location}</span>
            </div>

            <div className="event-card-footer">
              <div className="event-price">
                <strong>{event.price === 0 ? "Free" : money(event.price)}</strong>
                <small>{event.attendees}/{event.capacity} attending</small>
              </div>

              {isAdmin ? (
                <div className="admin-event-actions">
                  <button
                    className="edit-button"
                    onClick={() => onEdit(event)}
                  >
                    Edit
                  </button>
                  <button
                    className="delete-button"
                    onClick={() => onDelete(event.id)}
                  >
                    Delete
                  </button>
                </div>
              ) : (
                <button
                  className="register-event-button"
                  disabled={registeredIds.has(event.id) || event.attendees >= event.capacity}
                  onClick={() => onRegister(event)}
                >
                  {registeredIds.has(event.id)
                    ? "Registered ✓"
                    : event.attendees >= event.capacity
                      ? "Full"
                      : "Register →"}
                </button>
              )}
            </div>
          </div>
        </article>
      ))}
    </div>
  );
}

export default Dashboard;