using JobMatch.Inference.Models;

namespace JobMatch.Inference.Contracts;

public sealed record Candidate(string JobId, MatchFeatures Features);
public sealed record RankRequest(List<Candidate>? Candidates);
public sealed record Contribution(string Feature, double Value);
public sealed record RankedCandidate(string JobId, double Score, double BaseScore, List<Contribution> Contributions);
public sealed record RankResponse(string SchemaVersion, string ModelVersion, List<RankedCandidate> Candidates);
