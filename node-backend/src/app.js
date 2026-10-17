
import express from "express";
import cors from "cors";
import eventRoutes from "./routes/event.routes.js";
import { errorHandler } from "./middleware/error.middleware.js";
import "dotenv/config";

const PORT = 3000;
const app = express();

//middleware
app.use(cors());
app.use(express.json());


app.get("/", (req, res) => {
  res.status(200).json({
    success: true,
    message: "Smart Event Management API is running",
  });
});

// routes
app.use("/api", eventRoutes);


app.use((req, res) => {
  res.status(404).json({
    success: false,
    message: `Route not found: ${req.method} ${req.originalUrl}`,
  });
});


app.use(errorHandler);

app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
