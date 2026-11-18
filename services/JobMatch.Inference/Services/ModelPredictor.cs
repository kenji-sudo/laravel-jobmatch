using System.Security.Cryptography;
using System.Text.Json;
using JobMatch.Inference.Contracts;
using JobMatch.Inference.Models;
using Microsoft.ML;

namespace JobMatch.Inference.Services;

public sealed class ModelPredictor
{
    private readonly MLContext _ml = new(seed: 42);
    private readonly ITransformer? _model;
    public bool Ready => _model is not null;
    public string Version { get; } = "unavailable";

    public ModelPredictor(IConfiguration config)
    {
        var path = config["Matching:ModelPath"];
        if (string.IsNullOrWhiteSpace(path) || !File.Exists(path) || !File.Exists(path + ".json")) return;
        try
        {
            using var metadata = JsonDocument.Parse(File.ReadAllText(path + ".json"));
            var root = metadata.RootElement;
            var version = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
            if (root.GetProperty("schemaVersion").GetString() != "cole-features-v1"
                || root.GetProperty("modelVersion").GetString() != version
                || !root.GetProperty("features").EnumerateArray().Select(x => x.GetString()).SequenceEqual(MatchFeatures.Names)) return;
            _model = _ml.Model.Load(path, out _);
            Version = version;
        }
        catch (Exception ex) when (ex is IOException or JsonException or InvalidOperationException or ArgumentException or KeyNotFoundException or FormatException)
        {
            // A missing/incompatible artifact makes readiness false, never trains dummy data.
            _model = null;
        }
    }

    public RankedCandidate Predict(Candidate candidate)
    {
        if (_model is null) throw new InvalidOperationException("Model unavailable.");
        var coalitions = Enumerable.Range(0, 32).Select(candidate.Features.Coalition).ToArray();
        var predictions = _ml.Data.CreateEnumerable<MatchPrediction>(_model.Transform(_ml.Data.LoadFromEnumerable(coalitions)), false).ToArray();
        if (predictions.Length != 32 || predictions.Any(x => !float.IsFinite(x.Score)))
            throw new InvalidOperationException("Invalid prediction.");
        var scores = predictions.Select(x => Math.Clamp(x.Score * 100d, 0, 100)).ToArray();
        var contributions = new List<Contribution>();
        // Exact five-feature Shapley values on the reported clamped score, using an all-zero reference.
        int[] factorial = [1, 1, 2, 6, 24, 120];
        for (var feature = 0; feature < 5; feature++)
        {
            var value = 0d;
            for (var mask = 0; mask < 32; mask++)
            {
                if ((mask & (1 << feature)) != 0) continue;
                var count = System.Numerics.BitOperations.PopCount((uint)mask);
                var weight = factorial[count] * factorial[4 - count] / 120d;
                value += weight * (scores[mask | (1 << feature)] - scores[mask]);
            }
            contributions.Add(new(MatchFeatures.Names[feature], value));
        }
        return new(candidate.JobId, scores[31], scores[0], contributions);
    }
}
