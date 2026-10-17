import express from "express";

import * as userController from "../controllers/user.controller.js";
import * as registrationController from "../controllers/registration.controller.js";
import { allowUser } from "../middleware/role.middleware.js";

const userRoutes = express.Router();

// Authorization only; authentication is handled by event.routes.js.
userRoutes.use(allowUser);

userRoutes.post(
  "/events/:id/register",
  registrationController.registerForEvent
);

userRoutes.get(
  "/events/registrations",
  registrationController.getAllRegistration
);

userRoutes.delete(
  "/events/registrations/:registrationId",
  registrationController.cancelRegistration
);

userRoutes.get("/events", userController.getAllEvents);
userRoutes.get("/events/:id", userController.getEventById);

export { userRoutes };