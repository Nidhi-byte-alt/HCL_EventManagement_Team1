Smart Event Management & Registration Platform — Node.js Backend
1. Project Overview

This is the Node.js and Express backend for the Smart Event Management & Registration Platform. It provides REST APIs for event management, user event discovery, event registration, and registration cancellation.

The application uses JavaScript ES Modules and JSON files for data persistence. Authentication is handled by authentication middleware, while role-based middleware restricts user and company access to their respective endpoints.

API prefixes:

/api/user/* — User event discovery and registration.
/api/company/* — Company event management.
2. Features
Retrieve available and published events.
Retrieve event details by ID.
Create, update, and delete events as a company.
Register for an event as an authenticated user.
Retrieve the authenticated user's registrations.
Cancel an event registration.
Authenticate requests using authentication middleware.
Authorize requests according to user roles.
Validate event and registration data.
Persist data using JSON files.
Handle malformed JSON and unknown routes.
Centralized error handling and JSON responses.
3. Technologies Used
Node.js
Express.js
JavaScript ES Modules
dotenv for environment configuration
CORS
JSON-file persistence
Postman for API testing
Nodemon for development
4. Prerequisites

Install the following:

Node.js and npm
Visual Studio Code or another code editor
Postman (recommended for API testing)
5. Project Structure
server/
├── src/
│   ├── app.js
│   ├── controllers/
│   │   ├── event.controller.js
│   │   ├── user.controller.js
│   │   └── registration.controller.js
│   ├── services/
│   │   ├── event.service.js
│   │   └── registration.service.js
│   ├── repositories/
│   │   ├── event.repository.js
│   │   └── registration.repository.js
│   ├── routes/
│   │   ├── event.routes.js
│   │   ├── user.routes.js
│   │   └── company.routes.js
│   ├── middleware/
│   │   ├── auth.middleware.js
│   │   ├── role.middleware.js
│   │   └── error.middleware.js
│   └── data/
│       ├── events.json
│       └── registrations.json
├── .env
├── package.json
├── package-lock.json
└── README.md


The exact structure may vary depending on the authentication implementation and additional modules in the project.

6. Installation

Open a terminal in the server directory and run:

npm install


If you need to install the main dependencies separately:

npm install express cors dotenv
npm install --save-dev nodemon


Ensure package.json contains "type": "module" and suitable scripts:

{
  "type": "module",
  "scripts": {
    "start": "node src/app.js",
    "dev": "nodemon src/app.js"
  }
}


Preserve all other existing package configuration and dependencies.

7. Environment Configuration

Create a .env file in the server directory and configure the environment variables required by your authentication implementation.

Example:

PORT=3000


Add any required JWT or authentication secrets using the variable names expected by your middleware. Do not commit secrets to version control.

Load environment variables using:

import "dotenv/config";

8. Running the Application
Development mode
npm run dev

Normal mode
npm start


Default base URL:

http://localhost:3000


The API endpoints documented below assume that the main application mounts the event router at /api.

Health check

If configured in app.js, the health check can be accessed at:

GET /


Expected response:

{
  "success": true,
  "message": "Smart Event Management API is running"
}

9. API Route Configuration

The main event router should be mounted in app.js:

import eventRoutes from "./routes/event.routes.js";

app.use("/api", eventRoutes);


The event router should mount the role-specific routers as follows:

import { Router } from "express";
import { authenticateUser } from "../middleware/auth.middleware.js";
import { userRoutes } from "./user.routes.js";
import { companyRoutes } from "./company.routes.js";

const router = Router();

// Authenticate event API requests.
router.use(authenticateUser);

// Mount role-specific routes.
router.use("/user", userRoutes);
router.use("/company", companyRoutes);

export default router;


Important: Use /user, not /users, to match the requested /api/user/* endpoint prefix.

Authentication must populate req.auth with the authenticated identity and user type expected by the authorization middleware.

10. User API Endpoints

Base URL:

http://localhost:3000/api/user


All user endpoints require authentication and the user role.

Method	Endpoint	Description	Success Status
GET	/api/user/events	Retrieve available events	200 OK
GET	/api/user/events/:id	Retrieve an event by ID	200 OK
POST	/api/user/events/:id/register	Register for an event	201 Created
GET	/api/user/events/registrations	Retrieve the user's registrations	200 OK
DELETE	/api/user/events/registrations/:registrationId	Cancel a registration	200 OK
10.1 Get All Available Events

Request

GET /api/user/events


Returns events that meet the availability criteria implemented in the event service, such as published status and a valid future date and time.

The actual response format depends on the user controller implementation.

10.2 Get Event by ID

Request

GET /api/user/events/1


Returns the requested event if it exists and is available according to the service and controller logic.

Possible responses include:

200 OK — Event returned successfully.
404 Not Found — Event is unavailable or not found, depending on the implementation.
10.3 Register for an Event

Request

POST /api/user/events/1/register


Authentication is required. The user ID is taken from the authenticated request rather than from the request body.

No request body is required by the current registration controller.

Success response

Status: 201 Created

{
  "message": "Registration successful",
  "registration": {
    "id": 1,
    "eventId": 1,
    "userId": "authenticated-user-id",
    "registeredAt": "2026-10-17T09:00:00.000Z"
  }
}


The IDs and timestamp above are illustrative. The actual values are generated by the application.

Possible errors:

Status	Meaning
400 Bad Request	Invalid event ID
401 Unauthorized	Authentication or authenticated user information is missing
404 Not Found	Event is not available
409 Conflict	User is already registered

The registration service checks event availability and existing registrations before creating a registration.

10.4 Get the Current User's Registrations

Request

GET /api/user/events/registrations


Returns registrations belonging to the authenticated user, including associated event information when available.

Success status: 200 OK

Example response structure:

{
  "message": "Registration list",
  "registration": [
    {
      "id": 1,
      "eventId": 1,
      "userId": "authenticated-user-id",
      "registeredAt": "2026-10-17T09:00:00.000Z",
      "event": {
        "id": 1,
        "title": "Backend Workshop"
      }
    }
  ]
}


The event object shown is abbreviated for illustration.

10.5 Cancel a Registration

Request

DELETE /api/user/events/registrations/1


The registration ID is provided in the URL. The authenticated user can cancel only their own registration.

Success response

Status: 200 OK

{
  "message": "Registration cancelled successfully",
  "registration": {
    "id": 1,
    "eventId": 1,
    "userId": "authenticated-user-id"
  }
}


Possible errors:

400 Bad Request — Invalid registration ID.
401 Unauthorized — Missing authenticated user.
404 Not Found — Registration not found or not owned by the current user.
11. Company API Endpoints

Base URL:

http://localhost:3000/api/company


All company endpoints require authentication and the company role.

Method	Endpoint	Description	Success Status
GET	/api/company/events	Retrieve events	200 OK
GET	/api/company/event/:id	Retrieve an event by ID	200 OK
POST	/api/company/event	Create an event	201 Created
PUT	/api/company/event/:id	Update an event	200 OK
DELETE	/api/company/event/:id	Delete an event	200 OK

These paths match the provided company.routes.js file. The singular /event path is intentional in the current route configuration.

11.1 Get Company Events

Request

GET /api/company/events


Returns the events provided by eventController.getAll.

11.2 Get an Event by ID

Request

GET /api/company/event/1


Returns the selected event if found.

11.3 Create an Event

Request

POST /api/company/event
Content-Type: application/json


Example request body based on the current event service:

{
  "title": "Backend Development Workshop",
  "description": "Learn Node.js and Express",
  "date": "2026-11-20",
  "time": "14:00",
  "location": "New Delhi",
  "capacity": 40,
  "image": "",
  "status": "draft"
}


The service generates an event ID and sets a publishedAt timestamp when creating the event, according to the current implementation.

The default time is 12:00 if no time is supplied. The default status is draft if the service applies the suggested default.

Success status: 201 Created, assuming the controller uses that status code.

The exact response body depends on event.controller.js.

11.4 Update an Event

Request

PUT /api/company/event/1
Content-Type: application/json


Example request body:

{
  "title": "Updated Backend Workshop",
  "description": "Advanced Node.js and Express",
  "date": "2026-11-22",
  "time": "15:00",
  "location": "New Delhi",
  "capacity": 60,
  "image": "",
  "status": "published"
}


The update controller and service determine which fields are required and which fields can be changed.

Success status: 200 OK, assuming the controller returns this status.

11.5 Delete an Event

Request

DELETE /api/company/event/1


No request body is required unless the controller specifies otherwise.

Success status: 200 OK, assuming the controller returns this status.

If the event does not exist, the API should return 404 Not Found if that behavior is implemented by the controller or repository.

12. Authentication and Authorization

The event router applies authentication middleware before mounting the user and company routers.

The role middleware checks the authenticated identity:

allowUser permits requests where req.auth.userType === "user".
allowCompany permits requests where req.auth.userType === "company".

Typical authorization responses:

Status	Meaning
401 Unauthorized	Authentication information is missing or invalid
403 Forbidden	Authenticated identity does not have the required role

The authentication middleware must run before the role middleware so that req.auth is available.

Use the authentication mechanism configured in your project when testing protected endpoints in Postman.

13. Error Handling

The application should use centralized Express error middleware to handle unexpected errors, malformed JSON, and errors forwarded by controllers.

Scenario	HTTP Status
Invalid request data	400 Bad Request
Missing or invalid authentication	401 Unauthorized
Incorrect role	403 Forbidden
Event or registration not found	404 Not Found
Duplicate registration	409 Conflict
Unknown route	404 Not Found
Unexpected server error	500 Internal Server Error

The exact response body depends on the error middleware implementation.

Controllers should forward unexpected errors using:

next(error);


Register the unknown-route handler after the application routes and the centralized error handler after the unknown-route handler.

14. Data Persistence

The application uses JSON files for local persistence. Depending on the repository implementation, files may include:

src/data/events.json
src/data/registrations.json


Ensure each file contains valid JSON. For example, an empty collection should be represented as:

[]


JSON-file persistence is suitable for a prototype, but concurrent writes and multi-instance production deployments require stronger concurrency controls and a database.

15. Testing with Postman

Test the following endpoints and behaviors before merging changes:

User routes
GET /api/user/events
GET /api/user/events/:id
POST /api/user/events/:id/register
GET /api/user/events/registrations
DELETE /api/user/events/registrations/:registrationId
Company routes
GET /api/company/events
GET /api/company/event/:id
POST /api/company/event
PUT /api/company/event/:id
DELETE /api/company/event/:id
Security and validation
Requests without valid authentication are rejected.
A user cannot access company-only endpoints.
A company cannot access user-only endpoints.
Duplicate registrations return 409 Conflict.
Invalid event and registration IDs are rejected.
Users cannot cancel another user's registration.
Unavailable events cannot be registered for.
Malformed JSON and unknown routes are handled correctly.
16. Frontend Integration

Use the following base URL during local development:

http://localhost:3000/api


User endpoints begin with /user, while company endpoints begin with /company.

For example:

// Retrieve events as a user
fetch("http://localhost:3000/api/user/events");

// Retrieve company events
fetch("http://localhost:3000/api/company/events");


Protected endpoints require the authentication credentials expected by your authentication middleware.

Coordinate response formats with the frontend team. Some controllers may return data directly, while others return an object containing a message and a registration or data property.

17. Git and Merge Checklist

Before merging:

Run the server with npm run dev.
Verify all user and company routes in Postman.
Confirm the authentication and role middleware work as expected.
Verify event registration, duplicate prevention, and cancellation.
Confirm the JSON data files contain valid data.
Ensure .env secrets are not committed.
Commit the README and source changes to the appropriate team branch.

Backend overview: The documented API structure separates user event discovery and registration from company event management. All endpoints are mounted under /api/user/* or /api/company/*, with authentication and role-based authorization applied by the event router.