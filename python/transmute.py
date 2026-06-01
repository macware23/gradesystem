#!/usr/bin/env python3
"""
GradeFlow - Transmutation Engine
================================
Faithful Python conversion of the school's MagsEquivalent VBA function.

Maps a raw score (rawscore out of perfectscore) to a transmuted grade
equivalent using a piecewise-linear scale defined by:
  - cutoff:    the raw percentage that maps exactly to 75 (passing line)
  - zeroequiv: the grade given for a raw score of 0 (the floor, e.g. 65)

Above the cutoff, scores map linearly into 75..100.
Below the cutoff (but above 0), scores map linearly into zeroequiv..75.
A raw score of exactly 0 maps to zeroequiv.

This module can be:
  - imported (use mags_equivalent / transmute_percentage), or
  - called via stdin/stdout with JSON for the PHP bridge.
"""
import sys
import json


def mags_equivalent(perfectscore, rawscore, cutoff, zeroequiv):
    """Direct, verified conversion of the MagsEquivalent VBA function.

    Returns the transmuted grade equivalent for a single activity.
    """
    perfectscore = float(perfectscore)
    rawscore = float(rawscore)
    cutoff = float(cutoff)
    zeroequiv = float(zeroequiv)

    if perfectscore <= 0:
        return zeroequiv

    # Slope denominators (identical to the VBA)
    zpointshigh = 100 - cutoff          # raw% room above cutoff
    zpointslow = cutoff - 0             # raw% room below cutoff
    zequivhigh = 100 - 75               # grade room above 75  -> 25
    zequivlow = 75 - zeroequiv          # grade room below 75

    # Guard against division by zero from degenerate settings
    mulhigh = (zpointshigh / zequivhigh) if zequivhigh != 0 else 1
    mullow = (zpointslow / zequivlow) if zequivlow != 0 else 1

    pscore = (rawscore / perfectscore) * 100  # raw as a percentage

    if pscore >= cutoff:
        equivalent = 75 + (pscore - cutoff) / mulhigh
    elif pscore > 0:
        equivalent = zeroequiv + (pscore / mullow)
    else:
        equivalent = zeroequiv

    return round(equivalent, 2)


def transmute_percentage(pscore, cutoff, zeroequiv):
    """Transmute when you already have a percentage (0..100)."""
    return mags_equivalent(100.0, pscore, cutoff, zeroequiv)


def main():
    """Batch interface for PHP.

    Input JSON:
      {"cutoff":50,"zeroequiv":65,
       "items":[{"id":"q1","perfect":25,"raw":20}, ...]}
    Output JSON:
      {"results":{"q1":90.0, ...}}
    """
    try:
        data = json.loads(sys.stdin.read() or "{}")
    except json.JSONDecodeError as e:
        print(json.dumps({"error": f"Invalid JSON: {e}"}))
        return

    cutoff = data.get("cutoff", 50)
    zeroequiv = data.get("zeroequiv", 65)
    results = {}
    for item in data.get("items", []):
        raw = item.get("raw")
        perfect = item.get("perfect", 100)
        if raw is None:
            results[item.get("id")] = None
        else:
            results[item.get("id")] = mags_equivalent(perfect, raw, cutoff, zeroequiv)
    print(json.dumps({"results": results}))


if __name__ == "__main__":
    main()
