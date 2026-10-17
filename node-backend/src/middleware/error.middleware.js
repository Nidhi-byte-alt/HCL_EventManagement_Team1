
export function errorHandler(err, req, res, next) {
  // If a response has already started, let Express handle the error.
  if (res.headersSent) {
    return next(err);
  }

  console.error(err);

  // Malformed JSON sent to express.json()
  if (err.type === "entity.parse.failed") {
    return res.status(400).json({
      success: false,
      message: "Invalid JSON request body",
    });
  }

  const statusCode =
    Number.isInteger(err.statusCode) &&
    err.statusCode >= 400 &&
    err.statusCode <= 599
      ? err.statusCode
      : 500;

  return res.status(statusCode).json({
    success: false,
    message:
      statusCode >= 500
        ? "Internal server error"
        : err.message || "Request failed",
  });
}
