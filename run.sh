#!/usr/bin/env bash
# Kompiliert und startet das Timing-Beispiel ohne Build-Tool (nur javac/java).
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p out
javac -encoding UTF-8 -d out $(find src -name "*.java")
java -Dfile.encoding=UTF-8 -Dstdout.encoding=UTF-8 -Dstderr.encoding=UTF-8 -cp out com.khsopro.timing.examples.TimingExample
