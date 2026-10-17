
import { Router } from "express";
import { authenticateUser } from "../middleware/auth.middleware.js";
import { userRoutes } from "./user.routes.js";
import { companyRoutes } from "./company.routes.js";

const router = Router();

// Authenticate every event API.
router.use(authenticateUser);

// Mount role-specific routes.
router.use("/users", userRoutes);
router.use("/company", companyRoutes);

export default router;
