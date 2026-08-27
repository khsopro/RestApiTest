package com.khsopro.timing;

/**
 * Verhindert, dass der JIT-Compiler den Rückgabewert einer gemessenen Funktion als toten Code
 * wegoptimiert (Dead-Code-Elimination). Der Verbrauch passiert bewusst NACH dem Stoppen des
 * Timers, damit die Kosten des Konsumierens selbst die Messung nicht verfälschen.
 */
public final class Blackhole {

    private volatile Object sink;

    public void consume(Object value) {
        this.sink = value;
    }

    public void consume(long value) {
        this.sink = value;
    }

    public void consume(double value) {
        this.sink = value;
    }
}
