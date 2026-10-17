
export function authenticateUser(req, res, next) {
  if (process.env.NODE_ENV === "production") {
    return res.status(503).json({
      message: "Development authentication is disabled"
    });
  }

  const authHeader = req.headers.authorization;

  if (
    typeof authHeader !== "string" ||
    !authHeader.startsWith("Bearer ")
  ) {
    return res.status(401).json({
      message: "Bearer token is required"
    });
  }

  const token = authHeader.slice(7);

  if (
    process.env.DEV_USER_TOKEN &&
    token === process.env.DEV_USER_TOKEN
  ) {
    req.auth = {
      sub: process.env.DEV_USER_ID || "sample-user-001",
      userType: "user"
    };
  } else if (
    process.env.DEV_COMPANY_TOKEN &&
    token === process.env.DEV_COMPANY_TOKEN
  ) {
    req.auth = {
      sub: process.env.DEV_COMPANY_ID || "sample-company-001",
      userType: "company"
    };
  } else {
    return res.status(401).json({
      message: "Invalid development token"
    });
  }

  next();
}
