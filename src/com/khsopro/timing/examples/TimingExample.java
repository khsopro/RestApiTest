package com.khsopro.timing.examples;

import com.khsopro.timing.BenchmarkResult;
import com.khsopro.timing.BenchmarkRunner;

/**
 * Demonstriert den Unterschied zwischen einer naiven, verfälschten Einzelmessung und einer
 * korrekten Messung mit Warmup + Statistik. Direkt ausführbar über run.sh.
 */
public final class TimingExample {

    public static void main(String[] args) {
        System.out.println("== 1) Naive Messung eines einzelnen, kalten Aufrufs ==");
        naiveMeasurement();

        System.out.println();
        System.out.println("== 2) Korrekte Messung mit Warmup + Statistik ==");
        correctMeasurement();
    }

    private static void naiveMeasurement() {
        long start = System.currentTimeMillis();
        long result = fibonacci(35);
        long elapsed = System.currentTimeMillis() - start;
        System.out.println("fibonacci(35) = " + result + "  -> " + elapsed + " ms (einzelner, kalter Aufruf!)");
        System.out.println("Problem: enthaelt Klassenladen, Interpreter-Phase und laufende JIT-Kompilierung.");
        System.out.println("         Der zweite Aufruf waere schon deutlich schneller - reine Messkunst-Verfaelschung.");
    }

    private static void correctMeasurement() {
        BenchmarkRunner runner = new BenchmarkRunner();

        BenchmarkResult result = runner.measure(
                "fibonacci(30)",
                2_000,  // Warmup-Iterationen: JIT darf sich vorher "warmlaufen"
                5_000,  // Mess-Iterationen: für stabile Statistik (Median/P95 statt Einzelwert)
                () -> fibonacci(30)
        );

        System.out.println(result);
    }

    private static long fibonacci(int n) {
        if (n <= 1) return n;
        return fibonacci(n - 1) + fibonacci(n - 2);
    }
}
