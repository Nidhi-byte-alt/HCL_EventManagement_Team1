using System.Security.Claims;
using EventManagementApi.Models;
using EventManagementApi.Services;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace EventManagementApi.Controllers;

[ApiController]
[Route("api/[controller]")]
public class EventsController(
    JsonFileStore<EventItem> store) : ControllerBase
{
    [HttpGet]
    [AllowAnonymous]
    public async Task<IActionResult> GetAll()
        => Ok(await store.GetAllAsync());

    [HttpGet("{id:guid}")]
    [AllowAnonymous]
    public async Task<IActionResult> GetById(Guid id)
    {
        var events = await store.GetAllAsync();
        var item = events.FirstOrDefault(e => e.Id == id);

        return item is null ? NotFound() : Ok(item);
    }

    [HttpPost]
    [Authorize(Roles = "Admin")]
    public async Task<IActionResult> Create(EventItem request)
    {
        if (string.IsNullOrWhiteSpace(request.Title) ||
            string.IsNullOrWhiteSpace(request.Location) ||
            request.Capacity <= 0 ||
            request.StartDate <= DateTimeOffset.UtcNow)
        {
            return BadRequest(new { message = "Invalid event details." });
        }

        var events = await store.GetAllAsync();

        var item = new EventItem
        {
            Title = request.Title.Trim(),
            Description = request.Description,
            Location = request.Location.Trim(),
            StartDate = request.StartDate,
            Capacity = request.Capacity,
            CreatedBy = Guid.Parse(
                User.FindFirstValue(ClaimTypes.NameIdentifier)
                ?? User.FindFirstValue("sub")!)
        };

        events.Add(item);
        await store.SaveAllAsync(events);

        return CreatedAtAction(
            nameof(GetById), new { id = item.Id }, item);
    }

    [HttpPut("{id:guid}")]
    [Authorize(Roles = "Admin")]
    public async Task<IActionResult> Update(
        Guid id, EventItem request)
    {
        if (string.IsNullOrWhiteSpace(request.Title) ||
            string.IsNullOrWhiteSpace(request.Location) ||
            request.Capacity <= 0 ||
            request.StartDate <= DateTimeOffset.UtcNow)
        {
            return BadRequest(new { message = "Invalid event details." });
        }

        var events = await store.GetAllAsync();
        var item = events.FirstOrDefault(e => e.Id == id);

        if (item is null)
            return NotFound();

        item.Title = request.Title.Trim();
        item.Description = request.Description;
        item.Location = request.Location.Trim();
        item.StartDate = request.StartDate;
        item.Capacity = request.Capacity;

        await store.SaveAllAsync(events);
        return Ok(item);
    }

    [HttpDelete("{id:guid}")]
    [Authorize(Roles = "Admin")]
    public async Task<IActionResult> Delete(Guid id)
    {
        var events = await store.GetAllAsync();
        var item = events.FirstOrDefault(e => e.Id == id);

        if (item is null)
            return NotFound();

        events.Remove(item);
        await store.SaveAllAsync(events);

        return NoContent();
    }

    [HttpGet("admin-only")]
    [Authorize(Roles = "Admin")]
    public IActionResult AdminOnly()
        => Ok(new { message = "Welcome, administrator." });

    [HttpGet("my-access")]
    [Authorize(Roles = "User,Admin")]
    public IActionResult MyAccess()
        => Ok(new
        {
            name = User.Identity?.Name,
            role = User.FindFirstValue(ClaimTypes.Role)
        });
}