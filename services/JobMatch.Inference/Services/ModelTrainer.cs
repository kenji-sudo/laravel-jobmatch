using System.Security.Cryptography;
using System.Text.Json;
using JobMatch.Inference.Models;
using Microsoft.ML;

namespace JobMatch.Inference.Services;

public static class ModelTrainer
{
    public static int Train(string csv, string output)
    {
        const string header = "profile_id,required_skill_ratio,gwa_score,proficiency_depth,certification_count,internship_count,label";
        using (var reader = new StreamReader(csv))
            if (reader.ReadLine()?.Trim() != header) throw new InvalidDataException("Unexpected training CSV header or feature order.");
        var ml = new MLContext(seed: 42);
        var data = ml.Data.LoadFromTextFile<MatchFeatures>(csv, separatorChar: ',', hasHeader: true, allowQuoting: true);
        var rows = ml.Data.CreateEnumerable<MatchFeatures>(data, reuseRowObject: false).ToList();
        if (rows.Count < 50 || rows.Select(x => x.ProfileId).Distinct().Count() < 10
            || rows.Any(x => !x.IsValid() || string.IsNullOrWhiteSpace(x.ProfileId) || !float.IsFinite(x.Label) || x.Label is < 0 or > 1))
            throw new InvalidDataException("Supply at least 50 valid labelled rows from at least 10 anonymized profiles.");

        // A profile's rows stay in one split, preventing profile leakage into the holdout.
        var split = ml.Data.TrainTestSplit(data, testFraction: 0.2, samplingKeyColumnName: nameof(MatchFeatures.ProfileId), seed: 42);
        if (ml.Data.CreateEnumerable<MatchFeatures>(split.TrainSet, false).Count() < 20
            || ml.Data.CreateEnumerable<MatchFeatures>(split.TestSet, false).Count() < 5)
            throw new InvalidDataException("The grouped split is too small. Supply more distinct labelled profiles.");
        var pipeline = ml.Transforms.Concatenate("Features", MatchFeatures.Names)
            .Append(ml.Regression.Trainers.FastTree(numberOfLeaves: 8, numberOfTrees: 50, minimumExampleCountPerLeaf: 2));
        var model = pipeline.Fit(split.TrainSet);
        var metrics = ml.Regression.Evaluate(model.Transform(split.TestSet));
        if (!double.IsFinite(metrics.MeanAbsoluteError) || !double.IsFinite(metrics.RootMeanSquaredError))
            throw new InvalidDataException("Evaluation did not produce finite metrics.");

        var fullPath = Path.GetFullPath(output);
        Directory.CreateDirectory(Path.GetDirectoryName(fullPath)!);
        ml.Model.Save(model, data.Schema, fullPath);
        var version = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(fullPath))).ToLowerInvariant();
        File.WriteAllText(fullPath + ".json", JsonSerializer.Serialize(new
        {
            schemaVersion = "cole-features-v1", modelVersion = version, features = MatchFeatures.Names,
            trainingRows = ml.Data.CreateEnumerable<MatchFeatures>(split.TrainSet, false).Count(),
            testRows = ml.Data.CreateEnumerable<MatchFeatures>(split.TestSet, false).Count(),
            holdoutMae = metrics.MeanAbsoluteError, holdoutRmse = metrics.RootMeanSquaredError,
            datasetSha256 = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(csv))).ToLowerInvariant(),
            split = "grouped-by-profile", seed = 42, trainedAt = DateTimeOffset.UtcNow,
        }, new JsonSerializerOptions { WriteIndented = true }));
        Console.WriteLine($"Saved model and grouped holdout report. MAE={metrics.MeanAbsoluteError:F4}; RMSE={metrics.RootMeanSquaredError:F4}.");
        Console.WriteLine("These metrics do not establish hiring probability or deployment readiness.");
        return 0;
    }
}
