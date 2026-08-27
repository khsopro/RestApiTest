package com.khsopro.timing;

import java.util.Arrays;

/**
 * Deskriptive Statistik über eine Menge gemessener Laufzeiten (in Nanosekunden). Ein einzelner
 * Messwert ist bei Java-Timings kaum aussagekräftig (JIT-Zwischenkompilierung, GC-Pausen,
 * Scheduling-Jitter des Betriebssystems), daher Median/Perzentile statt nur Mittelwert.
 */
public final class Stats {

    private final long[] sortedNanos;

    public Stats(long[] samplesNanos) {
        if (samplesNanos.length == 0) {
            throw new IllegalArgumentException("Mindestens ein Messwert erforderlich");
        }
        this.sortedNanos = samplesNanos.clone();
        Arrays.sort(this.sortedNanos);
    }

    public int sampleCount() {
        return sortedNanos.length;
    }

    public double meanNanos() {
        long sum = 0;
        for (long v : sortedNanos) {
            sum += v;
        }
        return (double) sum / sortedNanos.length;
    }

    public long minNanos() {
        return sortedNanos[0];
    }

    public long maxNanos() {
        return sortedNanos[sortedNanos.length - 1];
    }

    public double medianNanos() {
        return percentileNanos(50);
    }

    public double percentileNanos(double percentile) {
        if (percentile <= 0) return sortedNanos[0];
        if (percentile >= 100) return sortedNanos[sortedNanos.length - 1];
        double index = percentile / 100.0 * (sortedNanos.length - 1);
        int lower = (int) Math.floor(index);
        int upper = (int) Math.ceil(index);
        if (lower == upper) return sortedNanos[lower];
        double fraction = index - lower;
        return sortedNanos[lower] + fraction * (sortedNanos[upper] - sortedNanos[lower]);
    }

    public double stddevNanos() {
        double mean = meanNanos();
        double sumSq = 0;
        for (long v : sortedNanos) {
            double diff = v - mean;
            sumSq += diff * diff;
        }
        return Math.sqrt(sumSq / sortedNanos.length);
    }

    @Override
    public String toString() {
        return String.format(
                "n=%d  mean=%s  median=%s  min=%s  max=%s  stddev=%s  p95=%s  p99=%s",
                sampleCount(),
                format(meanNanos()),
                format(medianNanos()),
                format(minNanos()),
                format(maxNanos()),
                format(stddevNanos()),
                format(percentileNanos(95)),
                format(percentileNanos(99))
        );
    }

    private static String format(double nanos) {
        if (nanos < 1_000) return String.format("%.0f ns", nanos);
        if (nanos < 1_000_000) return String.format("%.2f us", nanos / 1_000);
        if (nanos < 1_000_000_000) return String.format("%.2f ms", nanos / 1_000_000);
        return String.format("%.3f s", nanos / 1_000_000_000);
    }
}
