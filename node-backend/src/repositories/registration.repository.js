import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const DATA_FILE = path.join(
  __dirname,
  "../data/registrations.json"
);

async function getAllRegistrations() {
  try {
    const data = await fs.readFile(DATA_FILE, "utf8");

    // Handle an empty file safely.
    if (!data.trim()) {
      return [];
    }

    const registrations = JSON.parse(data);

    if (!Array.isArray(registrations)) {
      throw new Error("Registrations data must be a JSON array");
    }

    return registrations;
  } catch (error) {
    if (error.code === "ENOENT") {
      await fs.writeFile(DATA_FILE, "[]", "utf8");
      return [];
    }

    throw error;
  }
}

async function createRegistration(registrationData) {
  const registrations = await getAllRegistrations();

  // Additional duplicate protection.
  const duplicate = registrations.some(
    (registration) =>
      Number(registration.eventId) === Number(registrationData.eventId) &&
      String(registration.userId) === String(registrationData.userId)
  );

  if (duplicate) {
    const error = new Error(
      "You are already registered for this event"
    );
    error.status = 409;
    throw error;
  }

  const newRegistration = {
    id:
      registrations.reduce(
        (max, registration) =>
          Math.max(max, Number(registration.id) || 0),
        0
      ) + 1,
    ...registrationData,
    registeredAt: new Date().toISOString(),
  };

  registrations.push(newRegistration);

  await fs.writeFile(
    DATA_FILE,
    JSON.stringify(registrations, null, 2),
    "utf8"
  );

  return newRegistration;
}

async function cancelRegistration(registrationId, userId) {
  const registrations = await getAllRegistrations();

  const index = registrations.findIndex(
    (registration) =>
      Number(registration.id) === Number(registrationId) &&
      String(registration.userId) === String(userId)
  );

  // Do not reveal whether another user's registration exists.
  if (index === -1) {
    return null;
  }

  const [cancelledRegistration] = registrations.splice(index, 1);

  await fs.writeFile(
    DATA_FILE,
    JSON.stringify(registrations, null, 2),
    "utf8"
  );

  return cancelledRegistration;
}

export {
  getAllRegistrations,
  createRegistration,
  cancelRegistration,
};