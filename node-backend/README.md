# Smart Event Management & Registration Platform — Node.js Backend

## 1. Project Overview

This is the Node.js and Express backend for the Smart Event Management & Registration Platform. It provides REST APIs for managing events and uses a JSON file for data persistence.

**Current scope:** Event CRUD operations, input validation, and centralized error handling.

Authentication and registration functionality are handled separately by the team and should be documented here after integration.

## 2. Features

- Retrieve all events.
- Retrieve an event by ID.
- Create new events.
- Update existing events.
- Delete events.
- Validate event request data.
- Persist events in a JSON file.
- Handle malformed JSON requests.
- Handle unknown routes.
- Centralized handling of unexpected server errors.
- JSON responses for successful and unsuccessful requests.

## 3. Technologies Used

- Node.js
- Express.js
- CORS
- JavaScript ES Modules
- JSON file storage
- Postman for API testing
- Nodemon for development

## 4. Prerequisites

Install the following before running the application:

- Node.js and npm
- Visual Studio Code or another code editor
- Postman (recommended for API testing)

## 5. Project Structure

```text
server/
├── src/
│   ├── app.js
│   ├── controllers/
│   │   └── event.controller.js
│   ├── services/
│   │   └── event.service.js
│   ├── repositories/
│   │   └── event.repository.js
│   ├── routes/
│   │   └── event.routes.js
│   ├── middleware/
│   │   └── error.middleware.js
│   └── data/
│       └── events.json
├── package.json
└── README.md
```

## 6. Installation

Open a terminal in the `server` directory.

Install the existing project dependencies:

```bash
npm install
```

If the dependencies have not yet been installed, use:

```bash
npm install express cors
npm install --save-dev nodemon
```

Ensure that `package.json` includes the following configuration and scripts. Preserve any other existing dependencies and project settings.

```json
{
  "type": "module",
  "scripts": {
    "start": "node src/app.js",
    "dev": "nodemon src/app.js"
  }
}
```

## 7. Running the Application

### Development mode

Run:

```bash
npm run dev
```

Nodemon automatically restarts the server when watched files change.

### Normal mode

Run:

```bash
npm start
```

The server listens on port `3000`.

Base URL:

```text
http://localhost:3000
```

### Health check

Request:

```http
GET http://localhost:3000/
```

Expected response:

```json
{
  "success": true,
  "message": "Smart Event Management API is running"
}
```

## 8. Event API Documentation

Base URL:

```text
http://localhost:3000
```

| Method | Endpoint | Description | Success Status |
|---|---|---|---|
| GET | `/api/events` | Retrieve all events | 200 OK |
| GET | `/api/events/:id` | Retrieve an event by ID | 200 OK |
| POST | `/api/events` | Create an event | 201 Created |
| PUT | `/api/events/:id` | Update an event | 200 OK |
| DELETE | `/api/events/:id` | Delete an event | 200 OK |

### 8.1 Get All Events

**Request**

```http
GET /api/events
```

Full URL:

```text
http://localhost:3000/api/events
```

**Expected response**

The API returns an array of event objects.

```json
[
  {
    "id": "1",
    "title": "Node.js Backend Workshop",
    "description": "Learn backend development and REST APIs",
    "date": "2026-11-10",
    "location": "New Delhi",
    "capacity": 50
  },
  {
    "id": "2",
    "title": "React Development Meetup",
    "description": "Explore React and modern frontend development",
    "date": "2026-11-15",
    "location": "Jaipur",
    "capacity": 30
  }
]
```

The actual response depends on the contents of `events.json`.

### 8.2 Get Event by ID

**Request**

```http
GET /api/events/1
```

Full URL:

```text
http://localhost:3000/api/events/1
```

**Success response**

Returns the event object if the event exists.

**If the event does not exist**

Status: `404 Not Found`

```json
{
  "message": "Event not found"
}
```

### 8.3 Create an Event

**Request**

```http
POST /api/events
```

Full URL:

```text
http://localhost:3000/api/events
```

Set the request header:

```http
Content-Type: application/json
```

In Postman, select **Body → raw → JSON**.

**Request body**

```json
{
  "title": "Backend Hackathon",
  "description": "Node.js and Express workshop",
  "date": "2026-11-20",
  "location": "New Delhi",
  "capacity": 40
}
```

**Validation requirements**

- `title` must be a non-empty string.
- `date` must be a valid date string in `YYYY-MM-DD` format according to the controller's validation.
- `capacity` must be a positive integer.
- `description` and `location` are optional in the current service implementation.

**Success status:** `201 Created`

The current controller response format is:

```json
{
  "success": true,
  "message": "Event created successfully",
  "data": {
    "id": "generated-id",
    "title": "Backend Hackathon",
    "description": "Node.js and Express workshop",
    "date": "2026-11-20",
    "location": "New Delhi",
    "capacity": 40
  }
}
```

The actual ID is generated by the backend.

### 8.4 Update an Event

**Request**

```http
PUT /api/events/1
```

Full URL:

```text
http://localhost:3000/api/events/1
```

Set:

```http
Content-Type: application/json
```

**Request body**

```json
{
  "title": "Updated Node.js Workshop",
  "description": "Advanced Node.js backend development",
  "date": "2026-11-12",
  "location": "New Delhi",
  "capacity": 60
}
```

The current update controller expects a non-empty title, a valid-format date, and a positive integer capacity.

**Success status:** `200 OK`

```json
{
  "success": true,
  "message": "Event updated successfully",
  "data": {
    "id": "1",
    "title": "Updated Node.js Workshop",
    "description": "Advanced Node.js backend development",
    "date": "2026-11-12",
    "location": "New Delhi",
    "capacity": 60
  }
}
```

The returned event should retain its original ID.

### 8.5 Delete an Event

**Request**

```http
DELETE /api/events/1
```

Full URL:

```text
http://localhost:3000/api/events/1
```

No request body is required.

**Success status:** `200 OK`

```json
{
  "success": true,
  "message": "Event deleted successfully"
}
```

If the event does not exist, the API returns `404 Not Found`.

## 9. Error Handling

The application uses centralized Express error middleware for unexpected errors and malformed JSON. Expected validation errors and missing events are handled by the relevant controller.

| Scenario | HTTP Status | Expected Behavior |
|---|---|---|
| Invalid event input | 400 Bad Request | Returns a validation message |
| Malformed JSON | 400 Bad Request | Returns `Invalid JSON request body` |
| Unknown route | 404 Not Found | Returns a route-not-found message |
| Event not found | 404 Not Found | Returns `Event not found` |
| Unexpected server error | 500 Internal Server Error | Returns `Internal server error` |

### Invalid event input example

```json
{
  "success": false,
  "message": "Valid title, date (YYYY-MM-DD), and positive integer capacity are required"
}
```

### Malformed JSON example

```json
{
  "success": false,
  "message": "Invalid JSON request body"
}
```

### Unknown route example

Request:

```http
GET /api/unknown
```

Response:

```json
{
  "success": false,
  "message": "Route not found: GET /api/unknown"
}
```

### Unexpected server error example

```json
{
  "success": false,
  "message": "Internal server error"
}
```

The actual error details are logged on the server rather than exposed in the client response.

### Middleware execution order

The application registers middleware in this order:

1. CORS and JSON body parsing.
2. Health-check and application routes.
3. Unknown-route handler.
4. Centralized error handler.

Controllers forward unexpected errors using `next(error)`.

## 10. Data Persistence

Events are stored in:

```text
src/data/events.json
```

Example file content:

```json
[
  {
    "id": "1",
    "title": "Node.js Backend Workshop",
    "description": "Learn backend development and REST APIs",
    "date": "2026-11-10",
    "location": "New Delhi",
    "capacity": 50
  }
]
```

Create the file if it does not exist. It must contain valid JSON.

The repository reads and writes this file to retrieve and persist events. Data remains in the file across server restarts.

**Limitation:** JSON-file persistence is appropriate for a hackathon prototype. Concurrent writes and multi-instance production deployments require stronger concurrency control and a database.

## 11. Testing with Postman

Use the following checklist before merging the backend branch.

- [ ] `GET /api/events` returns all events.
- [ ] `GET /api/events/1` returns the selected event.
- [ ] `POST /api/events` creates an event.
- [ ] `PUT /api/events/1` updates an event.
- [ ] `DELETE /api/events/1` deletes an event.
- [ ] Invalid event data returns `400 Bad Request`.
- [ ] Malformed JSON returns `400 Bad Request`.
- [ ] An unknown route returns `404 Not Found`.
- [ ] A nonexistent event ID returns `404 Not Found`.
- [ ] An unexpected error forwarded with `next(error)` returns `500 Internal Server Error`.
- [ ] Temporary test code used to trigger errors has been removed.
- [ ] Existing event APIs still work after error-handling changes.

## 12. Frontend Integration Notes

For local development, the frontend can use:

```text
http://localhost:3000
```

The frontend should call the event endpoints documented above.

**Important response-format note:** The create and update controllers return the event inside the `data` property of the JSON response. If the frontend uses Axios, the event object is therefore accessed as `response.data.data`.

The list and get-by-ID controllers currently return the event list or event object directly. Coordinate these response formats with the frontend teammate before integration.

CORS is enabled in the Express application to support local frontend requests.

## 13. Team Integration Scope

This README documents the Node.js event APIs implemented so far.

The following should be added to the documentation when the team confirms and integrates the corresponding endpoints:

- User authentication and JWT validation.
- Admin authorization for event management.
- Event registration and cancellation APIs.
- Registration capacity and duplicate-registration rules.
- Any additional environment variables or deployment configuration.

Do not assume these endpoints are available until the team has implemented and tested them.

## 14. Git and Merge Checklist

Before merging into the shared team branch:

1. Ensure all temporary test code has been removed.
2. Run the server with `npm run dev`.
3. Test the event APIs and error-handling scenarios in Postman.
4. Confirm `package.json` and `package-lock.json` are included when applicable.
5. Confirm `events.json` contains valid JSON and no unintended test data.
6. Add this README to the `server` directory.
7. Commit and push your branch.
8. Coordinate the merge with the team.

---

**Backend status:** Event CRUD, event input validation, JSON-file persistence, unknown-route handling, malformed-JSON handling, and centralized unexpected-error handling are documented here. Authentication and registration are outside the currently documented implementation scope.
