
import { useState } from "react";
import "./App.css";
import Dashboard from "./Dashboard";

const API_URL = "http://localhost:5000/api/auth";

const initialForm = {
  name: "",
  email: "",
  password: "",
  confirmPassword: "",
  adminInviteCode: "",
  rememberMe: true,
};

function App() {
  const [page, setPage] = useState("register");
  const [accountType, setAccountType] = useState("User");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [messageType, setMessageType] = useState("error");
  const [authenticatedUser, setAuthenticatedUser] = useState(null);

  const [form, setForm] = useState(initialForm);

  const isRegister = page === "register";

  const updateField = (e) => {
    const { name, value, checked, type } = e.target;

    setForm((previous) => ({
      ...previous,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const switchPage = (nextPage) => {
    setPage(nextPage);
    setMessage("");
    setMessageType("error");
    setShowPassword(false);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setMessage("");

    if (isRegister && form.password !== form.confirmPassword) {
      setMessage("Your passwords do not match.");
      return;
    }

    if (form.password.length < 8) {
      setMessage("Password must contain at least 8 characters.");
      return;
    }

    if (
      isRegister &&
      accountType === "Admin" &&
      !form.adminInviteCode.trim()
    ) {
      setMessage("An admin invitation code is required.");
      return;
    }

    setLoading(true);

    try {
      const payload = isRegister
        ? {
            name: form.name.trim(),
            email: form.email.trim(),
            password: form.password,
            accountType,
            ...(accountType === "Admin"
              ? { adminInviteCode: form.adminInviteCode }
              : {}),
          }
        : {
            email: form.email.trim(),
            password: form.password,
          };

      const response = await fetch(
        `${API_URL}/${isRegister ? "register" : "login"}`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(payload),
        }
      );

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(
          data.message ||
            (isRegister
              ? "Registration failed."
              : "Invalid email or password.")
        );
      }

      if (isRegister) {
        setMessage(
          data.message ||
            "Registration successful! Please sign in to continue."
        );
        setMessageType("success");

        setForm({
          ...initialForm,
          email: form.email.trim(),
        });

        setPage("login");
        return;
      }

      if (!data.token || !data.user) {
        throw new Error(
          "The login API must return both token and user details."
        );
      }

      const userRole = String(
        data.user.role || data.user.accountType || ""
      ).toLowerCase();

      if (!["user", "admin"].includes(userRole)) {
        throw new Error(
          "The API returned an invalid user role. Please contact the team."
        );
      }

      const normalizedUser = {
        ...data.user,
        role: userRole === "admin" ? "Admin" : "User",
      };

      if (form.rememberMe) {
        sessionStorage.setItem("accessToken", data.token);
      } else {
        sessionStorage.removeItem("accessToken");
      }

      setAuthenticatedUser(normalizedUser);
      setMessage("");
    } catch (error) {
      setMessage(
        error.message === "Failed to fetch"
          ? "Unable to connect to the server. Check the API URL and backend status."
          : error.message
      );
      setMessageType("error");
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    sessionStorage.removeItem("accessToken");
    setAuthenticatedUser(null);
    setForm(initialForm);
    setPage("login");
    setMessage("You have been signed out.");
    setMessageType("success");
  };

  // Display the appropriate dashboard after successful login.
  if (authenticatedUser) {
    return (
      <Dashboard
        user={authenticatedUser}
        onLogout={handleLogout}
      />
    );
  }

  return (
    <main className="auth-page">
      <section className="auth-shell">
        <aside className="auth-visual">
          <div className="brand">
            <span className="brand-icon">✦</span>
            <span>Eventora</span>
          </div>

          <div className="visual-content">
            <span className="eyebrow">YOUR EVENTS. YOUR WORLD.</span>

            <h1>
              Every great
              <br />
              experience starts
              <br />
              <span>with a moment.</span>
            </h1>

            <p>
              Discover exciting events, connect with people,
              and turn your next gathering into something special.
            </p>

            <div className="event-preview">
              <div className="event-art">
                <span>✺</span>
              </div>

              <div className="event-info">
                <span className="event-tag">UPCOMING EXPERIENCE</span>
                <strong>Ideas in Motion</strong>
                <span>Connect. Create. Celebrate.</span>
              </div>

              <span className="event-arrow">↗</span>
            </div>

            <div className="visual-footer">
              <div className="avatar-stack">
                <span>P</span>
                <span>A</span>
                <span>R</span>
                <span>+</span>
              </div>
              <span>One platform. Endless possibilities.</span>
            </div>
          </div>

          <div className="visual-decoration decoration-one" />
          <div className="visual-decoration decoration-two" />
        </aside>

        <section className="auth-form-panel">
          <div className="mobile-brand">
            <span className="brand-icon">✦</span>
            Eventora
          </div>

          <div className="form-heading">
            <span className="form-kicker">
              {isRegister ? "GET STARTED TODAY" : "WELCOME BACK"}
            </span>

            <h2>
              {isRegister ? "Create your account" : "Welcome back!"}
            </h2>

            <p>
              {isRegister
                ? "Join the community and find your next experience."
                : "Sign in to continue your event journey."}
            </p>
          </div>

          <div className="auth-tabs">
            <button
              type="button"
              className={!isRegister ? "active" : ""}
              onClick={() => switchPage("login")}
            >
              Sign in
            </button>

            <button
              type="button"
              className={isRegister ? "active" : ""}
              onClick={() => switchPage("register")}
            >
              Create account
            </button>
          </div>

          {isRegister && (
            <>
              <label className="field-label">I want to join as</label>

              <div className="role-options">
                <button
                  type="button"
                  className={`role-card ${
                    accountType === "User" ? "selected" : ""
                  }`}
                  onClick={() => setAccountType("User")}
                >
                  <span className="role-icon">♙</span>
                  <span className="role-text">
                    <strong>Event User</strong>
                    <small>Explore and attend</small>
                  </span>
                  <span className="role-check">
                    {accountType === "User" ? "✓" : ""}
                  </span>
                </button>

                <button
                  type="button"
                  className={`role-card ${
                    accountType === "Admin" ? "selected" : ""
                  }`}
                  onClick={() => setAccountType("Admin")}
                >
                  <span className="role-icon">♜</span>
                  <span className="role-text">
                    <strong>Event Admin</strong>
                    <small>Create and manage</small>
                  </span>
                  <span className="role-check">
                    {accountType === "Admin" ? "✓" : ""}
                  </span>
                </button>
              </div>
            </>
          )}

          <form onSubmit={handleSubmit}>
            {isRegister && (
              <div className="field">
                <label htmlFor="name">Full name</label>
                <input
                  id="name"
                  name="name"
                  placeholder="Enter your full name"
                  value={form.name}
                  onChange={updateField}
                  autoComplete="name"
                  maxLength={100}
                  required
                />
              </div>
            )}

            <div className="field">
              <label htmlFor="email">Email address</label>
              <input
                id="email"
                name="email"
                type="email"
                placeholder="you@example.com"
                value={form.email}
                onChange={updateField}
                autoComplete="email"
                required
              />
            </div>

            <div className="field">
              <div className="label-row">
                <label htmlFor="password">Password</label>

                {!isRegister && (
                  <button
                    type="button"
                    className="text-link"
                    onClick={() => {
                      setMessage(
                        "Password reset is not configured yet. Please contact the administrator."
                      );
                      setMessageType("error");
                    }}
                  >
                    Forgot password?
                  </button>
                )}
              </div>

              <div className="password-wrap">
                <input
                  id="password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  placeholder={
                    isRegister
                      ? "At least 8 characters"
                      : "Enter your password"
                  }
                  value={form.password}
                  onChange={updateField}
                  autoComplete={
                    isRegister ? "new-password" : "current-password"
                  }
                  minLength={isRegister ? 8 : undefined}
                  required
                />

                <button
                  type="button"
                  className="show-password"
                  onClick={() => setShowPassword(!showPassword)}
                >
                  {showPassword ? "Hide" : "Show"}
                </button>
              </div>
            </div>

            {isRegister && (
              <>
                <div className="field">
                  <label htmlFor="confirmPassword">
                    Confirm password
                  </label>

                  <input
                    id="confirmPassword"
                    name="confirmPassword"
                    type="password"
                    placeholder="Re-enter your password"
                    value={form.confirmPassword}
                    onChange={updateField}
                    autoComplete="new-password"
                    required
                  />
                </div>

                {accountType === "Admin" && (
                  <div className="field">
                    <label htmlFor="adminInviteCode">
                      Admin invitation code
                    </label>

                    <input
                      id="adminInviteCode"
                      name="adminInviteCode"
                      type="password"
                      placeholder="Enter your authorized invite code"
                      value={form.adminInviteCode}
                      onChange={updateField}
                      autoComplete="off"
                      required
                    />

                    <small className="helper-text">
                      Admin access requires backend verification.
                    </small>
                  </div>
                )}
              </>
            )}

            {!isRegister && (
              <label className="remember-option">
                <input
                  type="checkbox"
                  name="rememberMe"
                  checked={form.rememberMe}
                  onChange={updateField}
                />
                <span>Remember me on this device</span>
              </label>
            )}

            {message && (
              <div
                className={`form-message ${messageType}`}
                role="status"
              >
                {message}
              </div>
            )}

            <button
              className="primary-button"
              type="submit"
              disabled={loading}
            >
              {loading
                ? "Please wait..."
                : isRegister
                  ? "Create my account →"
                  : "Sign in to your account →"}
            </button>
          </form>

          <p className="switch-prompt">
            {isRegister
              ? "Already part of the community?"
              : "New to Eventora?"}{" "}
            <button
              type="button"
              onClick={() =>
                switchPage(isRegister ? "login" : "register")
              }
            >
              {isRegister ? "Sign in" : "Create an account"}
            </button>
          </p>

          <p className="terms">
            By continuing, you agree to our{" "}
            <a href="#terms">Terms</a> and{" "}
            <a href="#privacy">Privacy Policy</a>.
          </p>
        </section>
      </section>
    </main>
  );
}

export default App;