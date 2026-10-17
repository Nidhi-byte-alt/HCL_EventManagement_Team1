
import { eventService } from "../services/event.service.js";

function isValidEventInput(body) {
  if (!body || typeof body !== "object" || Array.isArray(body)) {
    return false;
  }

  const { title, date, capacity } = body;

  return (
    typeof title === "string" &&
    title.trim().length > 0 &&
    typeof date === "string" &&
    !Number.isNaN(Date.parse(date)) &&
    /^\d{4}-\d{2}-\d{2}$/.test(date) &&
    Number.isInteger(capacity) &&
    capacity >= 1
  );
}

export const eventController = {
  async getAll(req, res, next) {
    try {
        //throw new Error("Test centralized error handling");
      const events = await eventService.getAllEvents();
      return res.status(200).json(events);
    } catch (error) {
      next(error);
    }
  },

  async getById(req, res, next) {
    try {
      const event = await eventService.getEventById(req.params.id);

      if (!event) {
        return res.status(404).json({
          success: false,
          message: "Event not found",
        });
      }

      return res.status(200).json(event);
    } catch (error) {
      next(error);
    }
  },

  async create(req, res, next) {
    try {
      if (!isValidEventInput(req.body)) {
        return res.status(400).json({
          success: false,
          message:
            "Valid title, date (YYYY-MM-DD), and positive integer capacity are required",
        });
      }

      const event = await eventService.createEvent(req.body);

      return res.status(201).json({
        success: true,
        message: "Event created successfully",
        data: event,
      });
    } catch (error) {
      next(error);
    }
  },

  async update(req, res, next) {
    try {
      if (!isValidEventInput(req.body)) {
        return res.status(400).json({
          success: false,
          message:
            "Valid title, date (YYYY-MM-DD), and positive integer capacity are required",
        });
      }

      const event = await eventService.updateEvent(
        req.params.id,
        req.body
      );

      if (!event) {
        return res.status(404).json({
          success: false,
          message: "Event not found",
        });
      }

      return res.status(200).json({
        success: true,
        message: "Event updated successfully",
        data: event,
      });
    } catch (error) {
      next(error);
    }
  },

  async delete(req, res, next) {
    try {
      const deleted = await eventService.deleteEvent(req.params.id);

      if (!deleted) {
        return res.status(404).json({
          success: false,
          message: "Event not found",
        });
      }

      return res.status(200).json({
        success: true,
        message: "Event deleted successfully",
      });
    } catch (error) {
      next(error);
    }
  },
};
