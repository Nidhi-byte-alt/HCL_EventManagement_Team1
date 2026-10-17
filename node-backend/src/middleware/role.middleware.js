function allowUser(req, res, next) {
  if (!req.auth) {
    return res.status(401).json({
      message: "Authentication required",
    });
  }

  if (req.auth.userType !== "user") {
    return res.status(403).json({
      message: "Access denied. User role required.",
    });
  }

  next();
}

function allowCompany(req, res, next) {
  if (!req.auth) {
    return res.status(401).json({
      message: "Authentication required",
    });
  }

  if (req.auth.userType !== "company") {
    return res.status(403).json({
      message: "Access denied. Company role required.",
    });
  }

  next();
}

export { allowUser, allowCompany };