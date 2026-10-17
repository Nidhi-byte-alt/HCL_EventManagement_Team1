
import { eventRepository } from "../repositories/event.repository.js";

function isAvailable(event) {
  if (
    event.status !== "published" ||
    !/^\d{4}-\d{2}-\d{2}$/.test(event.date || "") ||
    !/^([01]\d|2[0-3]):[0-5]\d$/.test(event.time || "")
  ) {
    return false;
  }

  // Validate the calendar date.
  const date = new Date(`${event.date}T00:00:00Z`);

  if (
    Number.isNaN(date.getTime()) ||
    date.toISOString().slice(0, 10) !== event.date
  ) {
    return false;
  }

  // Treat the event date and time as UTC.
  const eventDateTime = Date.parse(
    `${event.date}T${event.time}:00Z`
  );

  return eventDateTime >= Date.now();
}

export const eventService = {
  getAllEvents() {
    return eventRepository.findAll();
  },

  async getAvailableEvents() {
    const events = await eventRepository.findAll();

    return events
      .filter(isAvailable)
      .sort((a, b) =>
        `${a.date}T${a.time}`.localeCompare(`${b.date}T${b.time}`)
      );
  },

  getEventById(id) {
    return eventRepository.findById(id);
  },

  createEvent(data) {
    const event = {
      id: crypto.randomUUID(),
      title: data.title.trim(),
      description: data.description?.trim() ?? "",
      date: data.date,
      location: data.location?.trim() ?? "",
      capacity: data.capacity,
      time: data.time ?? "12:00",
      publishedAt: new Date().toISOString(),
      image: data.image ?? "",
      status: data.status ?? "draft",
    };

    return eventRepository.create(event);
  },

  updateEvent(id, data) {
  return eventRepository.update(id, {
    title: data.title.trim(),
    description: data.description?.trim() ?? "",
    date: data.date,
    location: data.location?.trim() ?? "",
    capacity: data.capacity,
    time: data.time ?? "12:00",
    publishedAt: new Date().toISOString(),
    image: data.image ?? "",
    status: data.status ?? "draft",
  });
},

deleteEvent(id) {
  return eventRepository.delete(id);
},
};
