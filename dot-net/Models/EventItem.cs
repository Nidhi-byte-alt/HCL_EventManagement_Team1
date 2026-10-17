namespace EventManagementApi.Models;

public class EventItem
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string Title { get; set; } = "";
    public string Description { get; set; } = "";
    public string Location { get; set; } = "";
    public DateTimeOffset StartDate { get; set; }
    public int Capacity { get; set; }
    public Guid CreatedBy { get; set; }
}