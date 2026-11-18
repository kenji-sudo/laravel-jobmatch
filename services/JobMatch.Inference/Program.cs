using System.Security.Cryptography;
using System.Text;
using JobMatch.Inference.Contracts;
using JobMatch.Inference.Services;

if (args.Length > 0 && args[0] == "train")
{
    if (args.Length != 3) { Console.Error.WriteLine("Usage: train labelled.csv model.zip"); return 2; }
    try { return ModelTrainer.Train(args[1], args[2]); }
    catch (Exception ex) when (ex is IOException or InvalidOperationException or ArgumentException or FormatException)
    { Console.Error.WriteLine("Training failed. Check the labelled CSV schema, row counts and output path."); return 1; }
}

var builder = WebApplication.CreateBuilder(args);
builder.WebHost.ConfigureKestrel(x => x.Limits.MaxRequestBodySize = 128 * 1024);
builder.Services.AddSingleton<ModelPredictor>();
var app = builder.Build();
app.Use(async (context, next) =>
{
    context.Response.Headers.CacheControl = "no-store";
    if (context.Request.Path == "/health") { await next(); return; }
    var expected = app.Configuration["Integration:ApiKey"] ?? "";
    var provided = context.Request.Headers.Authorization.ToString();
    if (expected.Length < 32) { context.Response.StatusCode = 503; return; }
    if (!provided.StartsWith("Bearer ", StringComparison.Ordinal)
        || !CryptographicOperations.FixedTimeEquals(SHA256.HashData(Encoding.UTF8.GetBytes(expected)), SHA256.HashData(Encoding.UTF8.GetBytes(provided[7..]))))
    { context.Response.StatusCode = 401; return; }
    await next();
});
app.MapGet("/health", (ModelPredictor predictor) => Results.Ok(new { service = "jobmatch-mlnet", schemaVersion = "cole-features-v1", modelReady = predictor.Ready }));
app.MapPost("/v1/rank", (RankRequest request, ModelPredictor predictor) =>
{
    if (request.Candidates is not { Count: > 0 and <= 50 } rows
        || rows.Any(x => x is null || x.Features is null || !x.Features.IsValid()
            || string.IsNullOrWhiteSpace(x.JobId) || x.JobId.Length > 64)
        || rows.Select(x => x.JobId).Distinct().Count() != rows.Count)
        return Results.BadRequest(new { error = "Invalid candidate feature batch." });
    if (!predictor.Ready) return Results.Problem(statusCode: 503, title: "Trained model unavailable.");
    try
    {
        return Results.Ok(new RankResponse("cole-features-v1", predictor.Version, rows.Select(predictor.Predict).OrderByDescending(x => x.Score).ToList()));
    }
    catch (InvalidOperationException) { return Results.Problem(statusCode: 503, title: "Prediction unavailable."); }
});
app.Run();
return 0;

public partial class Program;
