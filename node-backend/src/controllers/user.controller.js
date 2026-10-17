import  { eventService } from "../services/event.service.js";

async function getAllEvents(req, res, next) {
  try {
    const events = await eventService.getAvailableEvents();
    res.status(200).json(events);
  } catch (error) {
    next(error);
  }
}

async function getEventById(req, res, next) {
  try {
    const event = await eventService.getEventById(req.params.id);
    res.status(200).json(event);
  } catch (error) {
    next(error);
  }
}

export {
  getAllEvents,
  getEventById,
};