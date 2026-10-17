using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using EventManagementApi.Models;
using Microsoft.IdentityModel.Tokens;

namespace EventManagementApi.Services;

public class TokenService(IConfiguration configuration)
{
    public AuthResponse CreateToken(AppUser user)
    {
        var issuer = configuration["Jwt:Issuer"]!;
        var audience = configuration["Jwt:Audience"]!;
        var key = configuration["Jwt:Key"]
            ?? throw new InvalidOperationException("JWT key is missing.");

        if (Encoding.UTF8.GetByteCount(key) < 32)
            throw new InvalidOperationException(
                "JWT key must contain at least 32 UTF-8 bytes.");

        var expires = DateTime.UtcNow.AddMinutes(60);

        var claims = new[]
        {
            new Claim(JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new Claim(JwtRegisteredClaimNames.Email, user.Email),
            new Claim(ClaimTypes.Name, user.Name),
            new Claim(ClaimTypes.Role, user.Role),
            new Claim(JwtRegisteredClaimNames.Jti,
                      Guid.NewGuid().ToString())
        };

        var signingKey = new SymmetricSecurityKey(
            Encoding.UTF8.GetBytes(key));

        var credentials = new SigningCredentials(
            signingKey, SecurityAlgorithms.HmacSha256);

        var token = new JwtSecurityToken(
            issuer: issuer,
            audience: audience,
            claims: claims,
            expires: expires,
            signingCredentials: credentials);

        return new AuthResponse(
            new JwtSecurityTokenHandler().WriteToken(token),
            expires,
            user.Name,
            user.Email,
            user.Role);
    }
}