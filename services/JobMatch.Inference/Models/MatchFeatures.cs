using Microsoft.ML.Data;

namespace JobMatch.Inference.Models;

// Feature vocabulary follows ColeKenneth/jobmatch-ai feature/ai (dcd9d27).
// Counts are supplied from actual certification and internship records by Laravel.
public sealed class MatchFeatures
{
    [LoadColumn(0)] public string ProfileId { get; set; } = "";
    [LoadColumn(1)] public float RequiredSkillRatio { get; set; }
    [LoadColumn(2)] public float GwaScore { get; set; }
    [LoadColumn(3)] public float ProficiencyDepth { get; set; }
    [LoadColumn(4)] public float CertificationCount { get; set; }
    [LoadColumn(5)] public float InternshipCount { get; set; }
    [LoadColumn(6)] public float Label { get; set; }

    public static readonly string[] Names = [nameof(RequiredSkillRatio), nameof(GwaScore), nameof(ProficiencyDepth), nameof(CertificationCount), nameof(InternshipCount)];
    private float[] Values => [RequiredSkillRatio, GwaScore, ProficiencyDepth, CertificationCount, InternshipCount];

    public bool IsValid() => Values.All(float.IsFinite)
        && RequiredSkillRatio is >= 0 and <= 1 && GwaScore is >= 0 and <= 1
        && ProficiencyDepth is >= 0 and <= 5
        && CertificationCount is >= 0 and <= 100 && InternshipCount is >= 0 and <= 100
        && CertificationCount == MathF.Truncate(CertificationCount)
        && InternshipCount == MathF.Truncate(InternshipCount);

    public MatchFeatures Coalition(int mask) => new()
    {
        RequiredSkillRatio = (mask & 1) != 0 ? RequiredSkillRatio : 0,
        GwaScore = (mask & 2) != 0 ? GwaScore : 0,
        ProficiencyDepth = (mask & 4) != 0 ? ProficiencyDepth : 0,
        CertificationCount = (mask & 8) != 0 ? CertificationCount : 0,
        InternshipCount = (mask & 16) != 0 ? InternshipCount : 0,
    };
}

public sealed class MatchPrediction
{
    [ColumnName("Score")] public float Score { get; set; }
}
