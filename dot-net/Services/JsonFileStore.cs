using System.Text.Json;

namespace EventManagementApi.Services;

public class JsonFileStore<T> where T : class
{
    private readonly string _path;
    private readonly SemaphoreSlim _lock = new(1, 1);

    private static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true
    };

    public JsonFileStore(IWebHostEnvironment env, string fileName)
    {
        var directory = Path.Combine(env.ContentRootPath, "Data");
        Directory.CreateDirectory(directory);
        _path = Path.Combine(directory, fileName);

        if (!File.Exists(_path))
            File.WriteAllText(_path, "[]");
    }

    public async Task<List<T>> GetAllAsync()
    {
        await _lock.WaitAsync();
        try
        {
            var json = await File.ReadAllTextAsync(_path);
            return JsonSerializer.Deserialize<List<T>>(json, Options)
                   ?? new List<T>();
        }
        finally
        {
            _lock.Release();
        }
    }

    public async Task SaveAllAsync(List<T> items)
    {
        await _lock.WaitAsync();
        try
        {
            var json = JsonSerializer.Serialize(items, Options);
            await File.WriteAllTextAsync(_path, json);
        }
        finally
        {
            _lock.Release();
        }
    }
}