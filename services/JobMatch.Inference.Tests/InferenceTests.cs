using System.Globalization;
using System.Net;
using System.Net.Http.Json;
using JobMatch.Inference.Contracts;
using JobMatch.Inference.Models;
using JobMatch.Inference.Services;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.Configuration;
using Xunit;

namespace JobMatch.Inference.Tests;

public sealed class ModelFixture : IDisposable
{
    public string DirectoryPath { get; } = Path.Combine(Path.GetTempPath(), "jobmatch-tests-" + Guid.NewGuid());
    public string ModelPath => Path.Combine(DirectoryPath, "model.zip");
    public const string Key = "test-only-internal-key-32-characters-long";

    public ModelFixture()
    {
        Directory.CreateDirectory(DirectoryPath);
        var csv = Path.Combine(DirectoryPath, "synthetic-test-only.csv");
        using (var writer = new StreamWriter(csv))
        {
            writer.WriteLine("profile_id,required_skill_ratio,gwa_score,proficiency_depth,certification_count,internship_count,label");
            var random = new Random(42);
            for (var i = 0; i < 200; i++)
            {
                var ratio = random.NextDouble(); var gwa = random.NextDouble();
                var depth = random.NextDouble() * 5; var cert = random.Next(5); var internship = random.Next(3);
                var label = ratio * 0.6 + gwa * 0.2 + depth / 5 * 0.1 + cert / 4d * 0.05 + internship / 2d * 0.05;
                writer.WriteLine(string.Join(',', new[] { "fixture-" + i / 4, ratio.ToString(CultureInfo.InvariantCulture), gwa.ToString(CultureInfo.InvariantCulture), depth.ToString(CultureInfo.InvariantCulture), cert.ToString(), internship.ToString(), label.ToString(CultureInfo.InvariantCulture) }));
            }
        }
        ModelTrainer.Train(csv, ModelPath);
    }

    public WebApplicationFactory<Program> Factory(string? model = null) => new WebApplicationFactory<Program>()
        .WithWebHostBuilder(builder => builder.ConfigureAppConfiguration((_, config) => config.AddInMemoryCollection(new Dictionary<string, string?>
        { ["Integration:ApiKey"] = Key, ["Matching:ModelPath"] = model ?? ModelPath })));

    public void Dispose() => Directory.Delete(DirectoryPath, recursive: true);
}

public sealed class InferenceTests(ModelFixture fixture) : IClassFixture<ModelFixture>
{
    private static MatchFeatures Features() => new() { RequiredSkillRatio = .7f, GwaScore = .8f, ProficiencyDepth = 3, CertificationCount = 2, InternshipCount = 1 };

    [Fact]
    public async Task PredictsWithAnArtifactAndReconcilesAllFiveContributions()
    {
        using var app = fixture.Factory(); using var client = app.CreateClient();
        client.DefaultRequestHeaders.Authorization = new("Bearer", ModelFixture.Key);
        var response = await client.PostAsJsonAsync("/v1/rank", new RankRequest([new("12", Features())]));
        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var result = await response.Content.ReadFromJsonAsync<RankResponse>();
        Assert.NotNull(result); Assert.Equal("cole-features-v1", result.SchemaVersion);
        var row = Assert.Single(result.Candidates); Assert.Equal("12", row.JobId);
        Assert.InRange(row.Score, 0, 100); Assert.Equal(5, row.Contributions.Count);
        Assert.Equal(row.Score, row.BaseScore + row.Contributions.Sum(x => x.Value), precision: 7);
    }

    [Fact]
    public async Task ProtectsPredictionEndpoint()
    {
        using var app = fixture.Factory(); using var client = app.CreateClient();
        var response = await client.PostAsJsonAsync("/v1/rank", new RankRequest([new("1", Features())]));
        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
    }

    [Fact]
    public async Task MissingArtifactDoesNotTrainDummyData()
    {
        using var app = fixture.Factory(Path.Combine(fixture.DirectoryPath, "missing.zip")); using var client = app.CreateClient();
        client.DefaultRequestHeaders.Authorization = new("Bearer", ModelFixture.Key);
        var response = await client.PostAsJsonAsync("/v1/rank", new RankRequest([new("1", Features())]));
        Assert.Equal(HttpStatusCode.ServiceUnavailable, response.StatusCode);
    }

    [Fact]
    public async Task RejectsForgedOrDuplicateCandidates()
    {
        using var app = fixture.Factory(); using var client = app.CreateClient();
        client.DefaultRequestHeaders.Authorization = new("Bearer", ModelFixture.Key);
        var features = Features(); features.CertificationCount = -1;
        Assert.Equal(HttpStatusCode.BadRequest, (await client.PostAsJsonAsync("/v1/rank", new RankRequest([new("1", features)]))).StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, (await client.PostAsJsonAsync("/v1/rank", new RankRequest([new("1", Features()), new("1", Features())]))).StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, (await client.PostAsJsonAsync("/v1/rank", new RankRequest([]))).StatusCode);
    }

    [Fact]
    public void RejectsInvalidFeatureRangesAndNonfiniteValues()
    {
        var features = Features(); features.GwaScore = float.NaN; Assert.False(features.IsValid());
        features = Features(); features.ProficiencyDepth = 6; Assert.False(features.IsValid());
        features = Features(); features.InternshipCount = .5f; Assert.False(features.IsValid());
    }

    [Fact]
    public void RejectsThreeRowTrainingInsteadOfShippingAPlaceholder()
    {
        var file = Path.Combine(fixture.DirectoryPath, "too-small.csv");
        File.WriteAllText(file, "profile_id,required_skill_ratio,gwa_score,proficiency_depth,certification_count,internship_count,label\na,1,1,3,1,1,0.9\nb,0,0,1,0,0,0.1\nc,0.5,0.5,2,1,0,0.5\n");
        Assert.Throws<InvalidDataException>(() => ModelTrainer.Train(file, Path.Combine(fixture.DirectoryPath, "invalid.zip")));
    }
}
