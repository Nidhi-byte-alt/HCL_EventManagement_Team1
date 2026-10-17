import express from "express";

import  { eventController } from "../controllers/event.controller.js";
import { allowCompany } from "../middleware/role.middleware.js";

const companyRoutes = express.Router();

// Authorization only; authentication is handled by event.routes.js.
companyRoutes.use(allowCompany);

companyRoutes.get("/events", eventController.getAll);
companyRoutes.get("/event/:id", eventController.getById);
companyRoutes.post("/event", eventController.create);
companyRoutes.put("/event/:id", eventController.update);
companyRoutes.delete("/event/:id", eventController.delete);

export { companyRoutes };