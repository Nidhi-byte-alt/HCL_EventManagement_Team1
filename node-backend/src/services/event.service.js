
import { eventRepository } from "../repositories/event.repository.js";

export const eventService = {
  getAllEvents() {
    return eventRepository.findAll();
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
      capacity: data.capacity
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
  });
},

deleteEvent(id) {
  return eventRepository.delete(id);
},
};
