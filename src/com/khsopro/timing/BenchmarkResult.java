package com.khsopro.timing;

public final class BenchmarkResult {

    private final String name;
    private final Stats stats;

    public BenchmarkResult(String name, Stats stats) {
        this.name = name;
        this.stats = stats;
    }

    public String name() {
        return name;
    }

    public Stats stats() {
        return stats;
    }

    @Override
    public String toString() {
        return name + ": " + stats;
    }
}
