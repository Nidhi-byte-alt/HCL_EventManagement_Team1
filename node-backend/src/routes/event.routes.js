
import { Router } from "express";
import { eventController } from "../controllers/event.controller.js";

const router = Router();

router.get("/", eventController.getAll);
router.get("/:id", eventController.getById);
router.post("/", eventController.create);
router.put("/:id", eventController.update);
router.delete("/:id", eventController.delete);

export default router;
