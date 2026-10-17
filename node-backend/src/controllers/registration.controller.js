import {
  registerForEvent as registerForEventService,
  getAllRegistration as getAllRegistrationService,
  cancelRegistration as cancelRegistrationService,
} from "../services/registration.service.js";

async function registerForEvent(req, res, next) {
  try {
    const userId = req.auth.sub;

    const registration = await registerForEventService(
      req.params.id,
      userId
    );

    res.status(201).json({
      message: "Registration successful",
      registration,
    });
  } catch (error) {
    next(error);
  }
}

async function getAllRegistration(req, res, next) {
  try {
    const userId = req.auth.sub;

    const registration = await getAllRegistrationService(userId);

    res.status(200).json({
      message: "Registration list",
      registration,
    });
  } catch (error) {
    next(error);
  }
}

async function cancelRegistration(req, res, next) {
  try {
    const userId = req.auth.sub;
    const { registrationId } = req.params;

    const registration = await cancelRegistrationService(
      registrationId,
      userId
    );

    res.status(200).json({
      message: "Registration cancelled successfully",
      registration,
    });
  } catch (error) {
    next(error);
  }
}

export {
  registerForEvent,
  getAllRegistration,
  cancelRegistration,
};