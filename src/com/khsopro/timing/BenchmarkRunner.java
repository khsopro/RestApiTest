package com.khsopro.timing;

import java.util.function.Supplier;

/**
 * Handgebauter Mikro-Benchmark-Runner nach den Grundprinzipien von JMH (Java Microbenchmark
 * Harness), um typische Messfehler bei Java-Zeitmessungen zu vermeiden:
 *
 *  - JIT-Warmup: der Code läuft vor der eigentlichen Messung "warm", damit nicht versehentlich
 *    der langsame Interpreter- statt der JIT-kompilierte Pfad gemessen wird.
 *  - Dead-Code-Elimination: Rückgabewerte werden über ein Blackhole "verbraucht" (nach dem
 *    Stoppen des Timers), damit der JIT die Funktion nicht als wirkungslos erkennt und
 *    wegoptimiert.
 *  - Auflösung/Monotonie: System.nanoTime() statt System.currentTimeMillis().
 *  - Robustheit: viele Messwiederholungen + Statistik (Median/Perzentile) statt eines
 *    einzelnen, rauschanfälligen Werts.
 *
 * Für belastbare, publikationsreife Benchmarks (mit JVM-Forking pro Benchmark, dynamischer
 * Steady-State-Erkennung, Multi-Thread-Benchmarks etc.) sollte stattdessen die echte JMH-
 * Bibliothek verwendet werden – dieser Runner ist eine abhängigkeitsfreie "gut genug"-Lösung.
 */
public final class BenchmarkRunner {

    private final Blackhole blackhole = new Blackhole();

    /**
     * Misst eine Funktion mit fixer Warmup- und Messphase.
     *
     * @param name                   Bezeichner für die Ausgabe
     * @param warmupIterations       Aufrufe vor der Messung, um den JIT-Compiler "anspringen"
     *                               zu lassen (bei rechenintensivem Code eher Tausende, bei
     *                               sehr kurzem Code reichen oft wenige Hundert)
     * @param measurementIterations  Anzahl gemessener Aufrufe
     * @param function               die zu messende Funktion
     */
    public <T> BenchmarkResult measure(
            String name,
            int warmupIterations,
            int measurementIterations,
            Supplier<T> function
    ) {
        for (int i = 0; i < warmupIterations; i++) {
            blackhole.consume(function.get());
        }

        long[] samples = new long[measurementIterations];
        for (int i = 0; i < measurementIterations; i++) {
            long start = System.nanoTime();
            T result = function.get();
            long elapsed = System.nanoTime() - start;
            blackhole.consume(result);
            samples[i] = elapsed;
        }

        return new BenchmarkResult(name, new Stats(samples));
    }

    /** Variante für Funktionen ohne Rückgabewert (void). */
    public BenchmarkResult measureVoid(
            String name,
            int warmupIterations,
            int measurementIterations,
            Runnable function
    ) {
        return measure(name, warmupIterations, measurementIterations, () -> {
            function.run();
            return null;
        });
    }

    /**
     * Misst genau einen einzigen, NICHT warmgelaufenen Aufruf – relevant, wenn nicht die
     * "heiße", JIT-optimierte Performance interessiert, sondern ein echter Cold-Start
     * (z. B. der allererste REST-Aufruf nach dem Start der Anwendung).
     */
    public <T> long measureSingleShot(Supplier<T> function) {
        long start = System.nanoTime();
        T result = function.get();
        long elapsed = System.nanoTime() - start;
        blackhole.consume(result);
        return elapsed;
    }
}
