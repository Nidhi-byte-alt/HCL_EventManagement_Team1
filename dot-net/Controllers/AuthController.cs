using EventManagementApi.Models;
using EventManagementApi.Services;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;

namespace EventManagementApi.Controllers;

[ApiController]
[Route("api/[controller]")]
public class AuthController(
    JsonFileStore<AppUser> store,
    IPasswordHasher<AppUser> hasher,
    TokenService tokens) : ControllerBase
{
    [HttpPost("register")]
    public async Task<IActionResult> Register(RegisterRequest request)
    {
        var users = await store.GetAllAsync();

        var email = request.Email.Trim();

        if (users.Any(u =>
            u.Email.Equals(email, StringComparison.OrdinalIgnoreCase)))
        {
            return Conflict(new { message = "Email already registered." });
        }

        var user = new AppUser
        {
            Name = request.Name.Trim(),
            Email = email,
            Role = "User"
        };

        user.PasswordHash = hasher.HashPassword(user, request.Password);

        users.Add(user);
        await store.SaveAllAsync(users);

        return Ok(new
        {
            message = "Registration successful.",
            user.Id,
            user.Name,
            user.Email,
            user.Role
        });
    }

    [HttpPost("login")]
    public async Task<IActionResult> Login(LoginRequest request)
    {
        var users = await store.GetAllAsync();

        var user = users.FirstOrDefault(u =>
            u.Email.Equals(request.Email.Trim(),
                           StringComparison.OrdinalIgnoreCase));

        if (user is null)
            return Unauthorized(new { message = "Invalid email or password." });

        var result = hasher.VerifyHashedPassword(
            user, user.PasswordHash, request.Password);

        if (result == PasswordVerificationResult.Failed)
            return Unauthorized(new { message = "Invalid email or password." });

        return Ok(tokens.CreateToken(user));
    }
}