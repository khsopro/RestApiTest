# Java Timing Toolkit

Abhängigkeitsfreies Utility, um die Laufzeit einer Java-Methode zu messen, **ohne** dass die
Messung durch typische JVM-Effekte verfälscht wird.

## Warum eine simple Stoppuhr verfälscht

```java
long start = System.currentTimeMillis();
methode();
long dauer = System.currentTimeMillis() - start;
```

Diese naive Messung ist aus mehreren Gründen unzuverlässig:

1. **JIT-Warmup**: Java-Code startet interpretiert und wird erst nach vielen Aufrufen vom
   JIT-Compiler (C1/C2) in optimierten Maschinencode übersetzt. Ein einzelner, "kalter" Aufruf
   misst also größtenteils die Interpreter- bzw. Kompilierphase, nicht die tatsächliche
   Programmleistung.
2. **Dead-Code-Elimination**: Wenn der Rückgabewert einer Funktion nirgends verwendet wird,
   kann der JIT den Aufruf ganz oder teilweise wegoptimieren – man misst dann fälschlich "0 ms".
3. **Auflösung/Monotonie**: `System.currentTimeMillis()` ist an die Systemuhr gekoppelt (kann
   z. B. durch NTP-Sync springen) und hat eine gröbere Auflösung als `System.nanoTime()`.
4. **Rauschen**: GC-Pausen, OS-Scheduling und andere Threads lassen einzelne Messwerte stark
   streuen. Ein einzelner Wert sagt wenig aus – erst Median/Perzentile über viele
   Wiederholungen sind belastbar.

## Lösung: `BenchmarkRunner`

`src/com/khsopro/timing/BenchmarkRunner.java` folgt den Grundprinzipien von
[JMH](https://openjdk.org/projects/code-tools/jmh/) (dem De-facto-Standard für
Java-Microbenchmarks), ohne eine externe Bibliothek/Build-Tool vorauszusetzen:

- **Warmup-Phase**: ruft die Funktion X-mal auf, bevor gemessen wird, damit der JIT bereits
  optimiert hat.
- **Blackhole**: konsumiert den Rückgabewert *nach* dem Stoppen des Timers, damit der JIT den
  Aufruf nicht wegoptimiert – ohne dass die Konsum-Kosten selbst in die Messung einfließen.
- **`System.nanoTime()`**: monoton, hohe Auflösung.
- **Statistik statt Einzelwert**: `Stats` liefert Mittelwert, Median, Min/Max, Stddev, P95/P99
  über alle Mess-Iterationen.

### Verwendung

```java
BenchmarkRunner runner = new BenchmarkRunner();

BenchmarkResult result = runner.measure(
        "meineFunktion",
        2_000,   // Warmup-Iterationen
        5_000,   // Mess-Iterationen
        () -> meineFunktion(argument)
);

System.out.println(result); // z. B.: meineFunktion: n=5000  mean=12.3 µs  median=11.8 µs ...
```

Für Funktionen ohne Rückgabewert gibt es `measureVoid(...)`. Für einen bewusst **nicht**
warmgelaufenen Einzelaufruf (z. B. um einen echten Cold-Start zu messen) gibt es
`measureSingleShot(...)`.

### Ausführen

Kein Build-Tool nötig, nur JDK:

```bash
./run.sh
```

Das kompiliert alles unter `src/` nach `out/` und führt das Beispiel
`com.khsopro.timing.examples.TimingExample` aus, das naive vs. korrekte Messung gegenüberstellt.

## Grenzen dieses Ansatzes

Für harte, publikationsreife Zahlen (z. B. Performance-Regressionen in CI) empfiehlt sich die
echte JMH-Bibliothek, da sie zusätzlich:

- jeden Benchmark standardmäßig in einer **frischen JVM forkt** (verhindert Verzerrung durch
  JIT-Zustand aus vorherigen Benchmarks),
- den Blackhole-Verbrauch per **Bytecode-Instrumentierung** statt Sprachmitteln einbaut (noch
  geringerer Overhead),
- dynamische Steady-State-/Iterationszeit-Konfiguration sowie Multi-Thread-Benchmarks bietet.

Dieser Runner deckt den Alltag ("ist Variante A schneller als B?", "wie schnell ist diese
Methode wirklich?") ohne zusätzliche Abhängigkeit oder Build-Tool-Setup ab.
