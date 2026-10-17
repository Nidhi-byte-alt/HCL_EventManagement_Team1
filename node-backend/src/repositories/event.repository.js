
import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const currentFile = fileURLToPath(import.meta.url);
const currentDir = path.dirname(currentFile);

const eventsFile = path.join(
  currentDir,
  "../data/events.json"
);

async function readEvents() {
  const content = await readFile(eventsFile, "utf8");
  return JSON.parse(content);
}

async function saveEvents(events) {
  await writeFile(
    eventsFile,
    JSON.stringify(events, null, 2),
    "utf8"
  );
}

export const eventRepository = {
  findAll: readEvents,

  async findById(id) {
    const events = await readEvents();
    return events.find(event => event.id === id) ?? null;
  },

  async create(event) {
    const events = await readEvents();
    events.push(event);
    await saveEvents(events);
    return event;
  },

  async update(id, updatedData) {
  const events = await readEvents();
  const index = events.findIndex((event) => event.id === id);

  if (index === -1) {
    return null;
  }

  events[index] = {
    ...events[index],
    ...updatedData,
    id: events[index].id,
  };

  await saveEvents(events);
  return events[index];
},

async delete(id) {
  const events = await readEvents();
  const index = events.findIndex((event) => event.id === id);

  if (index === -1) {
    return false;
  }

  events.splice(index, 1);
  await saveEvents(events);
  return true;
},

};
