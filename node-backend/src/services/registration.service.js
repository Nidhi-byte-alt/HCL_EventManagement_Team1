import * as eventRepository from "../repositories/event.repository.js";
import * as registrationRepository from "../repositories/registration.repository.js";
import * as eventService from "./event.service.js";

async function registerForEvent(eventId, userId) {
  const id = Number(eventId);

  if (!Number.isSafeInteger(id) || id < 1) {
    const error = new Error("Invalid event ID");
    error.status = 400;
    throw error;
  }

  if (typeof userId !== "string" || !userId.trim()) {
    const error = new Error("Authenticated user is required");
    error.status = 401;
    throw error;
  }

  // Ensure the event is published and available by date and time.
  const event = await eventRepository.getEventById(id);

  if (!event || !eventService.isAvailable(event)) {
    const error = new Error("Available event not found");
    error.status = 404;
    throw error;
  }

  // Check existing registrations for this user and event.
  const registrations =
    await registrationRepository.getAllRegistrations();

  const alreadyRegistered = registrations.some(
    (registration) =>
      Number(registration.eventId) === id &&
      String(registration.userId) === userId
  );

  if (alreadyRegistered) {
    const error = new Error(
      "You are already registered for this event"
    );
    error.status = 409;
    throw error;
  }

  // Create registration only if no duplicate exists.
  return registrationRepository.createRegistration({
    eventId: id,
    userId,
  });
}

async function getAllRegistration(userId) {
  if (typeof userId !== "string" || !userId.trim()) {
    const error = new Error("Authenticated user is required");
    error.status = 401;
    throw error;
  }

  const registrations =
    await registrationRepository.getAllRegistrations();

  const userRegistrations = registrations.filter(
    (registration) => String(registration.userId) === userId
  );

  const events = await eventRepository.getAllEvents();

  return userRegistrations.map((registration) => {
    const event = events.find(
      (event) => Number(event.id) === Number(registration.eventId)
    );

    return {
      ...registration,
      event: event || null,
    };
  });
}

async function cancelRegistration(registrationId, userId) {
  if (typeof userId !== "string" || !userId.trim()) {
    const error = new Error("Authenticated user is required");
    error.status = 401;
    throw error;
  }

  const id = Number(registrationId);

  if (!Number.isSafeInteger(id) || id < 1) {
    const error = new Error("Invalid registration ID");
    error.status = 400;
    throw error;
  }

  const registration =
    await registrationRepository.cancelRegistration(id, userId);

  if (!registration) {
    const error = new Error("Registration not found");
    error.status = 404;
    throw error;
  }

  return registration;
}

export {
  registerForEvent,
  getAllRegistration,
  cancelRegistration,
};