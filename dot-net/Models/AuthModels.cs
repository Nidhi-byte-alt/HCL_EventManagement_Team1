using System.ComponentModel.DataAnnotations;

namespace EventManagementApi.Models;

public class RegisterRequest
{
    [Required, StringLength(100, MinimumLength = 2)]
    public string Name { get; set; } = "";

    [Required, EmailAddress]
    public string Email { get; set; } = "";

    [Required, MinLength(8)]
    public string Password { get; set; } = "";
}

public class LoginRequest
{
    [Required, EmailAddress]
    public string Email { get; set; } = "";

    [Required]
    public string Password { get; set; } = "";
}

public record AuthResponse(
    string Token,
    DateTime ExpiresAt,
    string Name,
    string Email,
    string Role);